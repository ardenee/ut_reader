<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes catalog file maintenance use cases outside the HTTP controller.
 * Why: Identity writes require global serialization, while dependency-only refreshes should use the narrower per-file
 *      compact dependency lock and must not stall behind unrelated catalog maintenance.
 * Role: Infrastructure orchestration over compact file-maintenance and dependency services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogDependencyRebuilder;

final class CatalogFileMaintenanceActionService
{
    private const WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';
    private const LOCK_WAIT_SECONDS = 45;
    private const DEADLOCK_RETRIES = 3;

    /**
     * @param array<string,mixed> $config
     * @param null|callable(array<string,mixed>):void $progress
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly ?int $userId,
        private readonly mixed $progress = null
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogFileMaintenance.php';
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function execute(string $operation, array $input): array
    {
        if ($operation === 'sync_game') {
            throw new RuntimeException('Full Sync now runs in short package-by-package requests. Refresh the Full Sync page and start it again.');
        }

        $fileId = $this->fileId($input);

        if ($operation === 'sync_reimport') {
            $result = $this->withWriteLock(
                fn(): array => $this->reimportWithIdentityRefresh($fileId, $operation, $input)
            );
            return [
                'ok' => true,
                'status' => $result['status'],
                'file_id' => $result['file_id'],
                'game_id' => $result['game_id'],
                'original_name' => $result['original_name'],
                'message' => $result['message'],
            ];
        }

        if ($operation === 'sync_refresh_dependencies') {
            // Full Sync has already completed every package identity write before
            // entering this phase. Do not acquire the global identity-write lock
            // 50k+ times; PdoCatalogDependencyRebuilder serializes only the file
            // whose compact dependency snapshot is actually being changed.
            $file = $this->retryDeadlock(function () use ($fileId, $input): array {
                [$file] = $this->currentFile(
                    $fileId,
                    $input,
                    'The re-imported package is no longer present in the catalog. Refresh Full Sync to rebuild its package list.'
                );
                (new PdoCatalogDependencyRebuilder($this->db, $this->config))->rebuild(
                    (int)$file['id'],
                    $this->progress,
                    0,
                    100,
                    'Final dependency refresh for ' . $file['original_name'],
                    false
                );
                return $file;
            });
            return [
                'ok' => true,
                'file_id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'original_name' => (string)$file['original_name'],
                'message' => 'Refreshed dependencies for ' . $file['original_name']
                    . '; package summary deferred to Full Sync finalization.',
            ];
        }

        if ($operation === 'reimport' || $operation === 'rebuild') {
            $result = $this->withWriteLock(
                fn(): array => $this->reimportWithIdentityRefresh($fileId, $operation, $input)
            );
            return [
                'ok' => true,
                'status' => $result['status'],
                'message' => $result['status'] === 'reimported'
                    ? 'Rebuilt ' . $result['original_name'] . ' from its preserved source path. ' . $result['message']
                    : $result['message'],
                'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
            ];
        }

        if ($operation === 'remove') {
            $result = $this->withWriteLock(
                fn(): array => \catalog_file_maintenance_remove($this->db, $this->config, $fileId, $this->progress)
            );
            \catalog_package_aliases_ensure($this->db);
            $this->db->prepare('DELETE FROM ue_file_package_aliases WHERE file_id=?')->execute([$fileId]);
            return [
                'ok' => true,
                'message' => 'Removed ' . $result['original_name'] . ' from storage and the catalog.' . $result['warning'],
                'return_url' => 'game-files.php?id=' . (int)$result['game_id'],
            ];
        }

        throw new RuntimeException('Unknown maintenance operation.');
    }

    /** @param array<string,mixed> $input */
    private function fileId(array $input): int
    {
        $raw = $input['file_id'] ?? null;
        $fileId = filter_var($raw, FILTER_VALIDATE_INT);
        if ($fileId === false || $fileId === null || $fileId < 1) {
            throw new RuntimeException('A valid file ID is required.');
        }
        return (int)$fileId;
    }

    /**
     * @param array<string,mixed> $input
     * @return array{0:array<string,mixed>,1:bool}
     */
    private function currentFile(int $fileId, array $input, string $notFoundMessage): array
    {
        $file = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
        if ($file) {
            return [$file, false];
        }

        $gameId = filter_var($input['game_id'] ?? null, FILTER_VALIDATE_INT);
        $packageName = trim((string)($input['package_name'] ?? ''));
        $md5 = trim((string)($input['md5'] ?? ''));
        $packageGuid = trim((string)($input['package_guid'] ?? ''));
        if ($gameId === false || $gameId === null || $gameId < 1 || $packageName === '' || $md5 === '') {
            throw new RuntimeException($notFoundMessage);
        }

        $where = 'game_id=? AND package_name=? AND md5=? AND scan_status="verified"';
        $args = [(int)$gameId, $packageName, $md5];
        if ($packageGuid !== '') {
            $where .= ' AND package_guid=?';
            $args[] = $packageGuid;
        } else {
            $where .= ' AND (package_guid IS NULL OR package_guid="")';
        }

        $replacement = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE ' . $where . ' ORDER BY uploaded_at DESC, id DESC LIMIT 1',
            $args
        );
        if (!$replacement) {
            throw new RuntimeException($notFoundMessage);
        }
        return [$replacement, true];
    }

    /** @return list<array<string,mixed>> */
    private function aliasRows(int $fileId): array
    {
        \catalog_package_aliases_ensure($this->db);
        return \catalog_all($this->db, 'SELECT * FROM ue_file_package_aliases WHERE file_id=? ORDER BY id', [$fileId]);
    }

    /** @param list<array<string,mixed>> $aliases @return list<string> */
    private function packageNames(array $file, array $aliases): array
    {
        $names = [(string)($file['package_name'] ?? '')];
        foreach ($aliases as $alias) {
            $names[] = (string)($alias['package_name'] ?? '');
        }
        $names = array_map('trim', $names);
        $names = array_filter($names, static fn(string $name): bool => $name !== '');
        return array_values(array_unique($names));
    }

    /** @param list<string> $packageNames @return list<int> */
    private function referringFileIds(int $gameId, array $packageNames, int $excludeFileId = 0): array
    {
        $packageNames = array_values(array_unique(array_filter(
            array_map('trim', $packageNames),
            static fn(string $name): bool => $name !== ''
        )));
        if ($packageNames === []) {
            return [];
        }

        $conditions = [];
        $args = [$gameId];
        foreach ($packageNames as $packageName) {
            $conditions[] = '(t.value_hash=? AND t.value_length=? AND t.value_prefix=?)';
            $args[] = md5($packageName, true);
            $args[] = strlen($packageName);
            $args[] = substr($packageName, 0, 200);
        }

        $sql = 'SELECT DISTINCT l.file_id'
            . ' FROM ue_dependency_links l'
            . ' JOIN ue_terms t ON t.id=l.required_package_term_id'
            . ' JOIN ue_files owner ON owner.id=l.file_id'
            . ' WHERE owner.game_id=? AND (' . implode(' OR ', $conditions) . ')';
        if ($excludeFileId > 0) {
            $sql .= ' AND l.file_id<>?';
            $args[] = $excludeFileId;
        }

        return array_map(
            static fn(array $row): int => (int)$row['file_id'],
            \catalog_all($this->db, $sql, $args)
        );
    }

    /**
     * @param array<string,mixed> $input
     * @return array{status:string,file_id:?int,game_id:int,original_name:string,message:string}
     */
    private function reimportWithIdentityRefresh(int $fileId, string $operation, array $input): array
    {
        [$file, $resolvedFromStaleId] = $this->currentFile(
            $fileId,
            $input,
            'File no longer exists in the catalog. Refresh the file list before retrying.'
        );
        if ($resolvedFromStaleId) {
            return [
                'status' => 'stale_replaced',
                'file_id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'original_name' => (string)$file['original_name'],
                'message' => 'Package already has a current catalog record after an earlier re-import.',
            ];
        }

        $oldFileId = (int)$file['id'];
        $referringFileIds = [];
        if ($operation !== 'sync_reimport') {
            $oldAliases = $this->aliasRows($oldFileId);
            $oldPackageNames = $this->packageNames($file, $oldAliases);
            $referringFileIds = $this->referringFileIds(
                (int)$file['game_id'],
                $oldPackageNames,
                $oldFileId
            );
        }

        $storedPath = \catalog_file_maintenance_storage_path($this->config, $file);
        if ($storedPath === null || !is_file($storedPath)) {
            // Reimport/rebuild/Full Sync are reconciliation operations, not deletion
            // commands. Preserve the relational identity and its diagnostic evidence
            // so an operator can restore the package or explicitly remove it.
            throw new RuntimeException($this->missingStorageMessage($file));
        }

        $result = \catalog_file_maintenance_reimport(
            $this->db,
            $this->config,
            $oldFileId,
            $this->userId,
            $this->progress,
            $operation === 'sync_reimport'
        );
        $newFileId = (int)$result['file_id'];

        if ($newFileId !== $oldFileId) {
            throw new RuntimeException(
                'Maintenance re-import changed stable file identity from #'
                . $oldFileId . ' to #' . $newFileId . '.'
            );
        }

        if ($operation !== 'sync_reimport') {
            $newFile = \catalog_one($this->db, 'SELECT * FROM ue_files WHERE id=?', [$newFileId]);
            if (!$newFile) {
                throw new RuntimeException('Re-imported package disappeared before dependency refresh.');
            }
            $newAliases = $this->aliasRows($newFileId);
            $newPackageNames = $this->packageNames($newFile, $newAliases);
            $referringFileIds = array_merge(
                $referringFileIds,
                $this->referringFileIds((int)$newFile['game_id'], $newPackageNames, $newFileId),
                [$newFileId]
            );
            $referringFileIds = array_values(array_unique(array_map('intval', $referringFileIds)));
            $referringFileIds = array_values(array_filter(
                $referringFileIds,
                fn(int $id): bool => $id > 0
                    && (bool)\catalog_one($this->db, 'SELECT id FROM ue_files WHERE id=?', [$id])
            ));
            \catalog_file_maintenance_refresh_ids(
                $this->db,
                $this->config,
                $referringFileIds,
                $this->progress,
                70,
                100,
                'Refreshing old/new exact dependency identities'
            );
            $result['message'] .= '; dependency files refreshed=' . count($referringFileIds);
        }

        return [
            'status' => 'reimported',
            'file_id' => $newFileId,
            'game_id' => (int)$result['game_id'],
            'original_name' => (string)$result['original_name'],
            'message' => (string)$result['message'],
        ];
    }

    /** @param array<string,mixed> $file */
    private function missingStorageMessage(array $file): string
    {
        $relativePath = ltrim(str_replace('\\', '/', (string)($file['relative_path'] ?? '')), '/');
        $sourceRelativePath = trim((string)($file['source_relative_path'] ?? ''));
        $catalogRoot = dirname(__DIR__, 3);
        $expectedPath = $relativePath !== ''
            ? $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath)
            : '(relative_path is empty)';
        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));

        return 'Stored package is missing for verified file #' . (int)($file['id'] ?? 0)
            . ' (' . ((string)($file['original_name'] ?? '') !== '' ? (string)$file['original_name'] : 'unnamed package') . '). '
            . 'relative_path=' . ($relativePath !== '' ? $relativePath : '(empty)')
            . '; expected_path=' . $expectedPath
            . '; storage_root=' . ($storageRoot !== '' ? $storageRoot : '(not configured)')
            . '; source_relative_path=' . ($sourceRelativePath !== '' ? $sourceRelativePath : '(empty)')
            . '. Catalog record preserved; restore the package or remove it explicitly.';
    }

    private function isDeadlock(Throwable $error): bool
    {
        $code = (string)$error->getCode();
        $message = strtolower($error->getMessage());
        return $code === '40001'
            || str_contains($message, 'deadlock found')
            || str_contains($message, 'serialization failure')
            || str_contains($message, 'error: 1213');
    }

    private function retryDeadlock(callable $operation): mixed
    {
        for ($attempt = 1; $attempt <= self::DEADLOCK_RETRIES; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $error) {
                if (!$this->isDeadlock($error) || $attempt === self::DEADLOCK_RETRIES) {
                    throw $error;
                }
                if ($this->progress !== null) {
                    ($this->progress)([
                        'stage' => 'retrying_database_write',
                        'done' => 0,
                        'total' => 100,
                        'percent' => 0,
                        'message' => 'Database write conflict detected; retrying maintenance request ('
                            . $attempt . '/' . self::DEADLOCK_RETRIES . ').',
                    ]);
                }
                usleep(250000 * $attempt);
            }
        }
        throw new RuntimeException('Maintenance retry limit reached.');
    }

    private function withWriteLock(callable $operation): mixed
    {
        if ($this->progress !== null) {
            ($this->progress)([
                'stage' => 'waiting_for_catalog_lock',
                'done' => 0,
                'total' => 100,
                'percent' => 0,
                'message' => 'Waiting for another catalog maintenance write to finish.',
            ]);
        }

        $lock = \catalog_one(
            $this->db,
            'SELECT GET_LOCK(?, ?) acquired',
            [self::WRITE_LOCK, self::LOCK_WAIT_SECONDS]
        );
        if ((int)($lock['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Another catalog maintenance task is still running. Please retry this package shortly.');
        }

        try {
            return $this->retryDeadlock($operation);
        } finally {
            try {
                $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([self::WRITE_LOCK]);
            } catch (Throwable $releaseError) {
                error_log(
                    '[UnrealDB][' . \catalog_request_id()
                    . '] could not release catalog maintenance lock: ' . $releaseError->getMessage()
                );
            }
        }
    }
}
