<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Audits verified storage against ue_files and queues physical orphans for review.
 * Why: Filesystem traversal, path safety, orphan resolution and queue mutation form one maintenance use case.
 * Role: Infrastructure maintenance service behind the legacy CatalogStorageAudit facade.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;

final class CatalogStorageAuditService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerPath.php';
    }

    public function storageRoot(): string
    {
        $root = realpath(rtrim((string)($this->config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Catalog storage folder is unavailable.');
        }
        return $root;
    }

    /** @param array<string,mixed> $game */
    public function verifiedDirectory(array $game): string
    {
        return $this->storageRoot()
            . DIRECTORY_SEPARATOR . 'games'
            . DIRECTORY_SEPARATOR . \scanner_slug_text((string)($game['slug'] ?? ''))
            . DIRECTORY_SEPARATOR . 'verified';
    }

    public static function databaseRelativePath(string $storageRoot, string $physicalPath): string
    {
        $root = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';
        $path = str_replace('\\', '/', $physicalPath);
        if (!str_starts_with($path, $root)) {
            throw new RuntimeException('Storage path is outside the configured storage root.');
        }
        return 'storage/' . ltrim(substr($path, strlen($root)), '/');
    }

    public static function normalizeRelative(string $relative): string
    {
        return strtolower(str_replace('\\', '/', ltrim(trim($relative), '/')));
    }

    public static function pathInside(string $path, string $root): bool
    {
        $realPath = realpath($path);
        $realRoot = realpath($root);
        if ($realPath === false || $realRoot === false) {
            return false;
        }
        return str_starts_with(
            $realPath,
            rtrim($realRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        );
    }

    public static function token(int $gameId, string $relativePath): string
    {
        return CatalogUnverifiedQueueStorage::base64UrlEncode((string)json_encode([
            'game_id' => $gameId,
            'relative_path' => $relativePath,
        ], JSON_UNESCAPED_SLASHES));
    }

    /** @return array{game:array<string,mixed>,path:string,relative_path:string,storage_relative_path:string,original_name:string,size:int,md5:string} */
    public function resolveOrphan(string $token): array
    {
        $decoded = CatalogUnverifiedQueueStorage::base64UrlDecode($token);
        $payload = $decoded === null ? null : json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid storage audit file reference.');
        }

        $gameId = (int)($payload['game_id'] ?? 0);
        $relativePath = trim((string)($payload['relative_path'] ?? ''));
        if ($gameId < 1
            || $relativePath === ''
            || str_contains($relativePath, "\0")
            || str_contains(str_replace('\\', '/', $relativePath), '../')) {
            throw new RuntimeException('Invalid storage audit file reference.');
        }

        $game = \catalog_one($this->db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]);
        if (!$game) {
            throw new RuntimeException('The selected game no longer exists.');
        }

        $verifiedDirectory = $this->verifiedDirectory($game);
        $path = $verifiedDirectory . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, ltrim(str_replace('\\', '/', $relativePath), '/'));
        if (!is_file($path) || !self::pathInside($path, $verifiedDirectory)) {
            throw new RuntimeException('The selected physical storage file is no longer available.');
        }

        $storageRoot = $this->storageRoot();
        $storageRelative = self::databaseRelativePath($storageRoot, realpath($path) ?: $path);
        $existing = \catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE game_id=? AND relative_path=? LIMIT 1',
            [$gameId, $storageRelative]
        );
        if ($existing) {
            throw new RuntimeException(
                'This physical file is already represented by a catalog record. Refresh the audit.'
            );
        }

        $md5 = @md5_file($path);
        return [
            'game' => $game,
            'path' => $path,
            'relative_path' => ltrim(str_replace('\\', '/', $relativePath), '/'),
            'storage_relative_path' => $storageRelative,
            'original_name' => basename($path),
            'size' => (int)(filesize($path) ?: 0),
            'md5' => is_string($md5) ? strtolower($md5) : '',
        ];
    }

    /** @return array{original_name:string,game_name:string} */
    public function queueOrphan(string $token): array
    {
        $orphan = $this->resolveOrphan($token);
        $queueDirectory = CatalogUnverifiedQueueStorage::unverifiedDirectory(
            $this->config,
            $orphan['game'],
            true
        );
        $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', (string)$orphan['original_name'])
            ?: 'queued-package.bin';
        $queueName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safeName;
        $destination = $queueDirectory . DIRECTORY_SEPARATOR . $queueName;
        if (!@rename($orphan['path'], $destination)) {
            throw new RuntimeException('Could not move the physical storage orphan into the unverified queue.');
        }

        $reason = "Storage audit queue\n"
            . "Physical package was found in verified storage with no ue_files catalog record.\n"
            . 'Original path: ' . $orphan['storage_relative_path'] . "\n"
            . 'MD5: ' . ($orphan['md5'] !== '' ? $orphan['md5'] : 'unavailable') . "\n"
            . 'Queued for review before import.';
        if (@file_put_contents($destination . '.txt', $reason) === false) {
            @rename($destination, $orphan['path']);
            throw new RuntimeException('Could not write the audit reason file; physical package was restored.');
        }

        return [
            'original_name' => (string)$orphan['original_name'],
            'game_name' => (string)$orphan['game']['name'],
        ];
    }

    /** @return array{games:list<array<string,mixed>>,orphans:list<array<string,mixed>>,missing_catalog:list<array<string,mixed>>,scanned_files:int} */
    public function run(?int $gameId = null): array
    {
        $games = \catalog_all(
            $this->db,
            'SELECT id,name,slug FROM ue_games' . ($gameId !== null ? ' WHERE id=?' : '') . ' ORDER BY name',
            $gameId !== null ? [$gameId] : []
        );
        $storageRoot = $this->storageRoot();
        $orphans = [];
        $missingCatalog = [];
        $scannedFiles = 0;

        foreach ($games as $game) {
            $rows = \catalog_all(
                $this->db,
                'SELECT id,original_name,package_name,relative_path,md5,file_size,scan_status '
                . 'FROM ue_files WHERE game_id=? ORDER BY id',
                [(int)$game['id']]
            );
            $catalogByRelative = [];
            $catalogByMd5 = [];
            foreach ($rows as $row) {
                $relativeKey = self::normalizeRelative((string)$row['relative_path']);
                if ($relativeKey !== '') {
                    $catalogByRelative[$relativeKey] = $row;
                }
                $md5 = strtolower(trim((string)$row['md5']));
                if ($md5 !== '') {
                    $catalogByMd5[$md5] ??= [];
                    $catalogByMd5[$md5][] = $row;
                }
            }

            $verifiedDirectory = $this->verifiedDirectory($game);
            $physicalRelativeKeys = [];
            if (is_dir($verifiedDirectory) && is_readable($verifiedDirectory)) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($verifiedDirectory, FilesystemIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $entry) {
                    if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                        continue;
                    }
                    $path = $entry->getPathname();
                    if (!self::pathInside($path, $verifiedDirectory)) {
                        continue;
                    }
                    $scannedFiles++;
                    $realPath = realpath($path) ?: $path;
                    $storageRelative = self::databaseRelativePath($storageRoot, $realPath);
                    $storageKey = self::normalizeRelative($storageRelative);
                    $physicalRelativeKeys[$storageKey] = true;
                    if (isset($catalogByRelative[$storageKey])) {
                        continue;
                    }

                    $md5 = @md5_file($path);
                    $md5 = is_string($md5) ? strtolower($md5) : '';
                    $sameGameMatches = $md5 !== '' ? ($catalogByMd5[$md5] ?? []) : [];
                    $normalizedReal = str_replace('\\', '/', $realPath);
                    $normalizedVerified = rtrim(str_replace('\\', '/', $verifiedDirectory), '/');
                    $relativeWithinVerified = ltrim(
                        substr($normalizedReal, strlen($normalizedVerified)),
                        '/'
                    );
                    $orphans[] = [
                        'token' => self::token((int)$game['id'], $relativeWithinVerified),
                        'game' => $game,
                        'path' => $realPath,
                        'storage_relative_path' => $storageRelative,
                        'relative_path' => $relativeWithinVerified,
                        'original_name' => basename($realPath),
                        'size' => (int)($entry->getSize() ?: 0),
                        'modified_at' => (int)($entry->getMTime() ?: 0),
                        'md5' => $md5,
                        'same_game_md5_matches' => $sameGameMatches,
                    ];
                }
            }

            foreach ($rows as $row) {
                $relativeKey = self::normalizeRelative((string)$row['relative_path']);
                if ($relativeKey === '' || isset($physicalRelativeKeys[$relativeKey])) {
                    continue;
                }
                $missingCatalog[] = ['game' => $game, 'file' => $row];
            }
        }

        usort($orphans, static fn(array $left, array $right): int => strcmp(
            (string)$left['game']['name'] . '/' . $left['relative_path'],
            (string)$right['game']['name'] . '/' . $right['relative_path']
        ));
        usort($missingCatalog, static fn(array $left, array $right): int => strcmp(
            (string)$left['game']['name'] . '/' . $left['file']['original_name'],
            (string)$right['game']['name'] . '/' . $right['file']['original_name']
        ));

        return [
            'games' => $games,
            'orphans' => $orphans,
            'missing_catalog' => $missingCatalog,
            'scanned_files' => $scannedFiles,
        ];
    }
}
