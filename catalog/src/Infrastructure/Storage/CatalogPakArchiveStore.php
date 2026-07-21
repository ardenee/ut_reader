<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Storage;

use PDO;
use Throwable;

final class CatalogPakArchiveStore
{
    private string $storageRoot;

    /** @param array<string,mixed> $config */
    public function __construct(private readonly array $config)
    {
        $this->storageRoot = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR);
        if ($this->storageRoot === '') {
            throw new \InvalidArgumentException('A catalog storage path is required for PAK archives.');
        }
    }

    public static function schemaInstalled(PDO $db): bool
    {
        $statement = $db->query(
            "SELECT COUNT(*) FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('ue_pak_archives','ue_pak_entries')"
        );
        return (int)$statement->fetchColumn() === 2;
    }

    /**
     * @param array<string,mixed> $game
     * @param array<string,mixed> $footer
     * @param array<string,mixed> $index
     */
    public function createOrReset(
        PDO $db,
        array $game,
        string $sourcePath,
        string $originalName,
        array $footer,
        array $index,
        ?int $userId
    ): int {
        if (!self::schemaInstalled($db)) {
            throw new \RuntimeException('PAK archive tables are missing. Run the database migrations first.');
        }
        if (!is_file($sourcePath) || !is_readable($sourcePath) || is_link($sourcePath)) {
            throw new \RuntimeException('Original PAK source is unavailable.');
        }

        $gameId = (int)($game['id'] ?? 0);
        $slug = trim((string)($game['slug'] ?? ''));
        if ($gameId < 1 || $slug === '') {
            throw new \RuntimeException('PAK storage requires a valid target game.');
        }

        $originalName = $this->safeOriginalName($originalName);
        $size = filesize($sourcePath);
        $md5 = md5_file($sourcePath);
        $sha1 = sha1_file($sourcePath);
        $sha256 = hash_file('sha256', $sourcePath);
        if ($size === false || !is_string($md5) || !is_string($sha1) || !is_string($sha256)) {
            throw new \RuntimeException('Could not calculate original PAK identity.');
        }

        $directory = $this->storageRoot . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR
            . $this->safeSlug($slug) . DIRECTORY_SEPARATOR . 'paks';
        $this->ensureDirectory($directory);
        $storedName = strtolower($sha256) . '.pak';
        $destination = $directory . DIRECTORY_SEPARATOR . $storedName;
        $this->publishCopy($sourcePath, $destination, (int)$size, strtolower($sha256));
        $relativePath = ltrim(str_replace('\\', '/', substr($destination, strlen($this->storageRoot))), '/');

        $entryCount = count(is_array($index['entries'] ?? null) ? $index['entries'] : []);
        $db->beginTransaction();
        try {
            $existing = $db->prepare('SELECT id FROM ue_pak_archives WHERE game_id=? AND sha256=? FOR UPDATE');
            $existing->execute([$gameId, strtolower($sha256)]);
            $pakId = (int)($existing->fetchColumn() ?: 0);
            if ($pakId > 0) {
                $update = $db->prepare(
                    'UPDATE ue_pak_archives SET original_name=?,stored_name=?,relative_path=?,file_size=?,md5=?,sha1=?,'
                    . 'pak_version=?,mount_point=?,footer_layout=?,index_offset=?,index_size=?,index_hash=?,'
                    . 'entry_count=?,extracted_count=0,skipped_count=0,status="processing",scan_notes=NULL,uploaded_by=? '
                    . 'WHERE id=?'
                );
                $update->execute([
                    $originalName,
                    $storedName,
                    $relativePath,
                    (int)$size,
                    strtolower($md5),
                    strtolower($sha1),
                    (int)($footer['version'] ?? 0),
                    (string)($index['mount_point'] ?? ''),
                    (string)($footer['layout'] ?? ''),
                    (int)($footer['index_offset'] ?? 0),
                    (int)($footer['index_size'] ?? 0),
                    strtolower((string)($footer['index_hash'] ?? '')),
                    $entryCount,
                    $userId,
                    $pakId,
                ]);
                $db->prepare('DELETE FROM ue_pak_entries WHERE pak_id=?')->execute([$pakId]);
            } else {
                $insert = $db->prepare(
                    'INSERT INTO ue_pak_archives '
                    . '(game_id,original_name,stored_name,relative_path,file_size,md5,sha1,sha256,pak_version,mount_point,'
                    . 'footer_layout,index_offset,index_size,index_hash,entry_count,status,uploaded_by) '
                    . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"processing",?)'
                );
                $insert->execute([
                    $gameId,
                    $originalName,
                    $storedName,
                    $relativePath,
                    (int)$size,
                    strtolower($md5),
                    strtolower($sha1),
                    strtolower($sha256),
                    (int)($footer['version'] ?? 0),
                    (string)($index['mount_point'] ?? ''),
                    (string)($footer['layout'] ?? ''),
                    (int)($footer['index_offset'] ?? 0),
                    (int)($footer['index_size'] ?? 0),
                    strtolower((string)($footer['index_hash'] ?? '')),
                    $entryCount,
                    $userId,
                ]);
                $pakId = (int)$db->lastInsertId();
            }
            $db->commit();
            return $pakId;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $entry */
    public function addEntry(PDO $db, int $pakId, int $entryIndex, array $entry, bool $wasExtracted): int
    {
        $path = trim(str_replace('\\', '/', (string)($entry['filename'] ?? '')), '/');
        $name = basename($path !== '' ? $path : ('entry-' . $entryIndex));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        $statement = $db->prepare(
            'INSERT INTO ue_pak_entries '
            . '(pak_id,entry_index,entry_path,entry_name,extension,data_offset,stored_size,uncompressed_size,'
            . 'compression_method,compression_block_size,entry_hash,is_encrypted,was_extracted,import_status) '
            . 'VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $pakId,
            $entryIndex,
            $path,
            $name,
            $extension,
            (int)($entry['offset'] ?? 0),
            max(0, (int)($entry['size'] ?? 0)),
            max(0, (int)($entry['uncompressed_size'] ?? 0)),
            max(0, (int)($entry['compression_method'] ?? 0)),
            max(0, (int)($entry['compression_block_size'] ?? 0)),
            strtolower((string)($entry['hash'] ?? '')) ?: null,
            !empty($entry['encrypted']) ? 1 : 0,
            $wasExtracted ? 1 : 0,
            $wasExtracted ? 'pending' : 'not_extracted',
        ]);
        return (int)$db->lastInsertId();
    }

    public function updateEntry(PDO $db, int $entryId, string $status, ?int $fileId, string $message = ''): void
    {
        $statement = $db->prepare(
            'UPDATE ue_pak_entries SET import_status=?,file_id=?,import_message=? WHERE id=?'
        );
        $statement->execute([
            substr(trim($status), 0, 32) ?: 'unknown',
            $fileId !== null && $fileId > 0 ? $fileId : null,
            trim($message) !== '' ? $message : null,
            $entryId,
        ]);
    }

    public function finish(PDO $db, int $pakId, int $extractedCount, int $skippedCount, string $notes = ''): void
    {
        $statement = $db->prepare(
            'UPDATE ue_pak_archives SET extracted_count=?,skipped_count=?,status="ready",scan_notes=? WHERE id=?'
        );
        $statement->execute([
            max(0, $extractedCount),
            max(0, $skippedCount),
            trim($notes) !== '' ? $notes : null,
            $pakId,
        ]);
    }

    public function markFailed(PDO $db, int $pakId, string $message): void
    {
        if ($pakId < 1) {
            return;
        }
        $statement = $db->prepare('UPDATE ue_pak_archives SET status="failed",scan_notes=? WHERE id=?');
        $statement->execute([trim($message), $pakId]);
    }

    /** @param array<string,mixed> $pak */
    public function resolve(array $pak): string
    {
        return LocalStoragePathGuard::resolveFile(
            $this->storageRoot,
            dirname(__DIR__, 3),
            (string)($pak['relative_path'] ?? '')
        );
    }

    /** @param array<string,mixed> $pak */
    public function delete(PDO $db, array $pak): void
    {
        $path = $this->resolve($pak);
        $statement = $db->prepare('DELETE FROM ue_pak_archives WHERE id=?');
        $statement->execute([(int)$pak['id']]);
        if (is_file($path) && !@unlink($path)) {
            throw new \RuntimeException('The PAK record was deleted, but its stored file could not be removed.');
        }
    }

    private function publishCopy(string $source, string $destination, int $expectedSize, string $expectedSha256): void
    {
        if (is_file($destination)) {
            $size = filesize($destination);
            $hash = hash_file('sha256', $destination);
            if ($size === $expectedSize && is_string($hash) && hash_equals($expectedSha256, strtolower($hash))) {
                return;
            }
            throw new \RuntimeException('A different file already occupies the PAK storage identity path.');
        }

        $part = $destination . '.part-' . bin2hex(random_bytes(6));
        try {
            if (!@copy($source, $part)) {
                throw new \RuntimeException('Could not copy the original PAK into durable storage.');
            }
            @chmod($part, 0640);
            $size = filesize($part);
            $hash = hash_file('sha256', $part);
            if ($size !== $expectedSize || !is_string($hash) || !hash_equals($expectedSha256, strtolower($hash))) {
                throw new \RuntimeException('Original PAK copy verification failed.');
            }
            if (!@rename($part, $destination)) {
                throw new \RuntimeException('Could not publish the original PAK storage file.');
            }
        } finally {
            @unlink($part);
        }
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F<>:"|?*]+/u', '_', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        if ($name === '' || strtolower((string)pathinfo($name, PATHINFO_EXTENSION)) !== 'pak') {
            return 'archive.pak';
        }
        return $name;
    }

    private function safeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? '';
        return trim($slug, '-_.') ?: 'game';
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create PAK storage directory.');
        }
    }
}
