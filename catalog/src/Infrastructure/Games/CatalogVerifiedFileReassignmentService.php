<?php
/**
 * Safely moves one verified package out of its current game.
 *
 * Returning to Unverified Files reuses the stable ue_files row. Moving to another
 * game verifies a hardlinked/copy working file in the destination first and only
 * retires the source row after the destination is confirmed verified. A failed
 * target import therefore leaves the source game untouched.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Import\PdoCatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceSupport;

final class CatalogVerifiedFileReassignmentService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogPackageAliases.php';
    }

    /**
     * @param null|callable(array<string,mixed>):void $progress
     * @return array<string,mixed>
     */
    public function move(
        int $fileId,
        int $targetGameId,
        ?int $userId = null,
        ?callable $progress = null
    ): array {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A positive file ID is required.');
        }
        if ($targetGameId < 0) {
            throw new \InvalidArgumentException('Destination game is invalid.');
        }

        $source = \catalog_one(
            $this->db,
            'SELECT f.*,g.name source_game_name,g.slug source_game_slug '
            . 'FROM ue_files f LEFT JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',
            [$fileId]
        );
        if (!$source) {
            return [
                'status' => 'skipped',
                'file_id' => $fileId,
                'message' => 'File #' . $fileId . ' no longer exists in the source game.',
                'warnings' => [],
            ];
        }

        if ($targetGameId === 0) {
            if ((string)($source['scan_status'] ?? '') === 'unverified'
                && (int)($source['game_id'] ?? 0) === 0) {
                return [
                    'status' => 'unverified',
                    'file_id' => $fileId,
                    'message' => (string)($source['original_name'] ?? ('File #' . $fileId))
                        . ' is already in Unverified Files.',
                    'warnings' => [],
                ];
            }
            return (new CatalogVerifiedFileDemotionService($this->db, $this->config))
                ->returnToUnverified($fileId, $userId, $progress);
        }

        if ((string)($source['scan_status'] ?? '') !== 'verified' || (int)($source['game_id'] ?? 0) < 1) {
            return [
                'status' => 'skipped',
                'file_id' => $fileId,
                'message' => 'File #' . $fileId . ' is no longer an active verified file in the source game.',
                'warnings' => [],
            ];
        }

        $sourceGameId = (int)$source['game_id'];
        if ($targetGameId === $sourceGameId) {
            throw new \RuntimeException('The destination is already the file’s current game.');
        }
        $target = \catalog_one(
            $this->db,
            'SELECT g.id,g.name,g.slug,COALESCE(p.engine_key,"") engine_key '
            . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 '
            . 'WHERE g.id=?',
            [$targetGameId]
        );
        if (!$target) {
            throw new \RuntimeException('Destination game no longer exists.');
        }

        $support = new CatalogFileMaintenanceSupport($this->db, $this->config);
        $sourcePath = CatalogFileMaintenanceSupport::storagePath($this->config, $source);
        if ($sourcePath === null || !is_file($sourcePath)) {
            throw new \RuntimeException('The verified source package is missing from controlled storage.');
        }

        $originalName = trim((string)($source['original_name'] ?? '')) ?: basename($sourcePath);
        $packageName = trim((string)($source['package_name'] ?? ''));
        $md5 = strtolower(trim((string)($source['md5'] ?? '')));
        $sourceRelativePath = trim((string)($source['source_relative_path'] ?? ''));
        if ($sourceRelativePath === '') {
            $sourceRelativePath = $originalName;
        }

        // Always pass destination moves through the canonical importer, even
        // when the same MD5 already exists in the target. The importer owns
        // profile verification, duplicate handling, alias publication and
        // affected-dependency refresh; bypassing it here previously skipped those
        // guarantees for same-byte destination files.
        $this->emit($progress, 'target_check', 5, 'Preparing canonical destination verification for ' . (string)$target['name']);
        $workingPath = $this->workingFile($sourcePath, $originalName, $progress);
        try {
            $this->emit($progress, 'target_import', 15, 'Verifying package in ' . (string)$target['name']);
            $result = (new PdoCatalogPackageImporter($this->db, $this->config))->importUploadedFile(
                $targetGameId,
                $workingPath,
                $originalName,
                $userId,
                true,
                function (array $state) use ($progress): void {
                    if ($progress === null) {
                        return;
                    }
                    $sourcePercent = max(0, min(100, (int)($state['percent'] ?? 0)));
                    $state['percent'] = 15 + (int)floor($sourcePercent * 55 / 100);
                    $state['stage'] = 'target_' . (trim((string)($state['stage'] ?? 'import')) ?: 'import');
                    $progress($state);
                },
                false,
                ['source_relative_path' => $sourceRelativePath]
            );
            $targetStatus = strtolower(trim((string)($result[0] ?? '')));
            if (!in_array($targetStatus, ['verified', 'duplicate', 'alias'], true)) {
                throw new \RuntimeException(
                    'Destination import did not produce a verified package. Source file was left in '
                    . (string)$source['source_game_name'] . '.'
                );
            }
            $targetFileId = max(0, (int)($result[1] ?? 0));
            $targetFile = $targetFileId > 0
                ? \catalog_one(
                    $this->db,
                    'SELECT id,game_id,original_name,md5 FROM ue_files '
                    . 'WHERE id=? AND game_id=? AND scan_status="verified"',
                    [$targetFileId, $targetGameId]
                )
                : null;
            if (!$targetFile) {
                throw new \RuntimeException(
                    'Destination import completed without an active verified destination row. Source file was preserved.'
                );
            }
            if ($md5 !== '' && strtolower(trim((string)($targetFile['md5'] ?? ''))) !== $md5) {
                throw new \RuntimeException(
                    'Destination package identity does not match the source bytes. Source file was preserved.'
                );
            }
        } finally {
            if (is_file($workingPath)) {
                @unlink($workingPath);
            }
        }

        $targetFileId = (int)($targetFile['id'] ?? 0);
        if ($targetFileId < 1) {
            throw new \RuntimeException('Could not confirm the verified destination package.');
        }

        $this->emit($progress, 'source_remove', 72, 'Destination verified; removing package from ' . (string)$source['source_game_name']);
        $retired = $this->retireSource($source, $sourcePath, $support, $progress);
        $warnings = is_array($retired['warnings'] ?? null) ? $retired['warnings'] : [];
        $message = 'Moved ' . $originalName . ' from ' . (string)$source['source_game_name']
            . ' to ' . (string)$target['name'] . ' (destination file #' . $targetFileId . ').';
        if ($targetStatus === 'duplicate' || $targetStatus === 'alias') {
            $message .= ' Existing destination bytes were reused.';
        }
        if ($warnings !== []) {
            $message .= ' ' . count($warnings) . ' cleanup warning(s) were recorded.';
        }
        $this->emit($progress, 'complete', 100, $message);

        return [
            'status' => 'moved',
            'file_id' => $fileId,
            'source_game_id' => $sourceGameId,
            'source_game' => (string)$source['source_game_name'],
            'target_game_id' => $targetGameId,
            'target_game' => (string)$target['name'],
            'target_file_id' => $targetFileId,
            'target_status' => $targetStatus,
            'original_name' => $originalName,
            'warnings' => $warnings,
            'message' => $message,
        ];
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function workingFile(string $sourcePath, string $originalName, ?callable $progress): string
    {
        $extension = preg_replace('/[^A-Za-z0-9_]+/', '', (string)pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $directory = dirname($sourcePath);
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $working = $directory . DIRECTORY_SEPARATOR . '.unrealdb-reassign-'
                . bin2hex(random_bytes(8)) . '.' . $extension;
            if (@link($sourcePath, $working)) {
                $this->emit($progress, 'target_prepare', 12, 'Prepared zero-copy working link for destination verification');
                return $working;
            }
        }

        $working = $directory . DIRECTORY_SEPARATOR . '.unrealdb-reassign-'
            . bin2hex(random_bytes(8)) . '.' . $extension;
        $input = @fopen($sourcePath, 'rb');
        $output = @fopen($working, 'xb');
        $size = @filesize($sourcePath);
        if (!is_resource($input) || !is_resource($output) || $size === false || $size < 1) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            @unlink($working);
            throw new \RuntimeException('Could not prepare destination import working file.');
        }

        $copied = 0;
        $lastProgress = 0;
        try {
            while (!feof($input)) {
                $buffer = fread($input, 4 * 1024 * 1024);
                if (!is_string($buffer)) {
                    throw new \RuntimeException('Could not read source package while preparing destination import.');
                }
                if ($buffer === '') {
                    break;
                }
                $offset = 0;
                $length = strlen($buffer);
                while ($offset < $length) {
                    $written = fwrite($output, substr($buffer, $offset));
                    if ($written === false || $written < 1) {
                        throw new \RuntimeException('Could not write destination import working file.');
                    }
                    $offset += $written;
                }
                $copied += $length;
                if (($copied - $lastProgress) >= 32 * 1024 * 1024 || $copied >= (int)$size) {
                    $percent = 5 + (int)floor(min($copied, (int)$size) * 7 / max(1, (int)$size));
                    $this->emit($progress, 'target_prepare', $percent, 'Copying package for destination verification');
                    $lastProgress = $copied;
                }
            }
        } catch (Throwable $error) {
            @unlink($working);
            throw $error;
        } finally {
            fclose($input);
            fclose($output);
        }
        return $working;
    }

    /**
     * @param array<string,mixed> $source
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{warnings:list<string>}
     */
    private function retireSource(
        array $source,
        string $sourcePath,
        CatalogFileMaintenanceSupport $support,
        ?callable $progress
    ): array {
        $fileId = (int)$source['id'];
        $sourceGameId = (int)$source['game_id'];
        $packageName = trim((string)($source['package_name'] ?? ''));
        $metadataPath = CatalogFileMaintenanceSupport::metadataPath($this->config, $sourceGameId, $fileId);
        $affectedIds = $support->affectedIds($sourceGameId, $fileId, $packageName, false);
        $stagedPath = $sourcePath . '.moving-out-' . bin2hex(random_bytes(8));
        if (!@rename($sourcePath, $stagedPath)) {
            throw new \RuntimeException('Destination is verified, but the source package could not be staged for removal.');
        }

        try {
            $this->db->beginTransaction();
            $support->deleteFileProjections($fileId);
            $this->db->prepare('DELETE FROM ue_dependency_package_summaries WHERE file_id=?')->execute([$fileId]);
            \catalog_package_aliases_ensure($this->db);
            $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            $this->db->prepare('DELETE FROM ue_file_metadata WHERE file_id=?')->execute([$fileId]);
            $statement = $this->db->prepare(
                'DELETE FROM ue_files WHERE id=? AND game_id=? AND scan_status="verified"'
            );
            $statement->execute([$fileId, $sourceGameId]);
            if ($statement->rowCount() !== 1) {
                throw new \RuntimeException('Source file changed before it could be removed from its game.');
            }
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (is_file($stagedPath) && !is_file($sourcePath)) {
                @rename($stagedPath, $sourcePath);
            }
            throw $error;
        }

        $warnings = [];
        if (is_file($stagedPath) && !@unlink($stagedPath)) {
            $warnings[] = 'old source package storage could not be deleted';
        }
        if (is_file($metadataPath) && !@unlink($metadataPath)) {
            $warnings[] = 'old source compact metadata file could not be deleted';
        }

        $this->emit($progress, 'source_dependencies', 82, 'Refreshing packages affected by the source removal');
        try {
            $support->refreshIds(
                $affectedIds,
                $progress,
                82,
                97,
                'Refreshing dependencies after moving ' . (string)($source['original_name'] ?? ('file #' . $fileId))
            );
        } catch (Throwable $error) {
            $warnings[] = 'source dependency refresh was deferred: ' . $this->shortError($error);
        }

        try {
            CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                $fileId,
                [$sourceGameId],
                $packageName !== '' ? [$packageName] : [],
                $this->config
            );
        } catch (Throwable $error) {
            $warnings[] = 'projection reconciliation could not be queued: ' . $this->shortError($error);
        }

        return ['warnings' => $warnings];
    }

    /** @param null|callable(array<string,mixed>):void $progress */
    private function emit(?callable $progress, string $stage, int $percent, string $message): void
    {
        if ($progress !== null) {
            $progress([
                'stage' => $stage,
                'done' => max(0, min(100, $percent)),
                'total' => 100,
                'percent' => max(0, min(100, $percent)),
                'message' => $message,
            ]);
        }
    }

    private function shortError(Throwable $error): string
    {
        $message = trim($error->getMessage());
        return mb_substr($message !== '' ? $message : get_class($error), 0, 500, 'UTF-8');
    }
}
