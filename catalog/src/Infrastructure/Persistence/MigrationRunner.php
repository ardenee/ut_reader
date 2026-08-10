<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `MigrationRunner` for migration runner.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    private const TABLE = 'ue_schema_migrations';
    private const BASELINE_VERSION = '202608090002';

    /** @var array<string,callable(PDO,SchemaInspector,array<string,mixed>):void> */
    private array $executionOverrides;

    /**
     * @param array<string,callable(PDO,SchemaInspector,array<string,mixed>):void> $executionOverrides
     */
    public function __construct(
        private readonly PDO $db,
        private readonly string $migrationDirectory,
        private readonly int $lockTimeoutSeconds = 30,
        array $executionOverrides = []
    ) {
        $this->executionOverrides = $executionOverrides;
    }

    /** @return list<array{version:string,name:string,description:string,checksum:string,path:string,up:callable}> */
    public function discover(): array
    {
        if (!is_dir($this->migrationDirectory) || !is_readable($this->migrationDirectory)) {
            throw new RuntimeException('Migration directory is unavailable: ' . $this->migrationDirectory);
        }

        $paths = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($paths, SORT_STRING);
        $migrations = [];
        $versions = [];

        foreach ($paths as $path) {
            $filename = basename($path);
            if (preg_match('/^(\d{12,20})_([a-z0-9_]+)\.php$/', $filename, $match) !== 1) {
                throw new RuntimeException('Invalid migration filename: ' . $filename);
            }

            $definition = require $path;
            if (!is_array($definition)) {
                throw new RuntimeException('Migration must return an array: ' . $filename);
            }

            $version = (string)$match[1];
            $name = (string)$match[2];
            $declaredVersion = (string)($definition['version'] ?? $version);
            $description = trim((string)($definition['description'] ?? str_replace('_', ' ', $name)));
            $up = $definition['up'] ?? null;
            $checksum = hash_file('sha256', $path);

            if ($declaredVersion !== $version) {
                throw new RuntimeException('Migration version does not match its filename: ' . $filename);
            }
            if ($description === '' || !is_callable($up) || !is_string($checksum)) {
                throw new RuntimeException('Migration definition is incomplete: ' . $filename);
            }
            if (isset($versions[$version])) {
                throw new RuntimeException('Duplicate migration version: ' . $version);
            }
            $versions[$version] = true;
            $migrations[] = [
                'version' => $version,
                'name' => $name,
                'description' => $description,
                'checksum' => $checksum,
                'path' => $path,
                'up' => $up,
            ];
        }

        return $migrations;
    }

    /** @return list<array<string,mixed>> */
    public function status(): array
    {
        $migrations = $this->discover();
        $applied = $this->appliedMigrations();
        $status = [];
        $known = [];

        foreach ($migrations as $migration) {
            $version = $migration['version'];
            $known[$version] = true;
            $row = $applied[$version] ?? null;
            $state = 'pending';
            if (is_array($row)) {
                $state = hash_equals((string)$row['checksum'], $migration['checksum'])
                    ? 'applied'
                    : 'checksum_mismatch';
            }
            $status[] = $migration + [
                'state' => $state,
                'batch' => is_array($row) ? (int)$row['batch'] : null,
                'execution_ms' => is_array($row) ? (int)$row['execution_ms'] : null,
                'applied_at' => is_array($row) ? (string)$row['applied_at'] : null,
            ];
        }

        foreach ($applied as $version => $row) {
            if (isset($known[$version])) {
                continue;
            }
            $archived = self::isBaselineVersion((string)$version);
            $status[] = [
                'version' => $version,
                'name' => (string)$row['migration'],
                'description' => $archived
                    ? 'Migration is included in the consolidated install.sql baseline.'
                    : 'Applied migration file is missing from this release.',
                'checksum' => (string)$row['checksum'],
                'path' => '',
                'up' => null,
                'state' => $archived ? 'archived' : 'orphaned',
                'batch' => (int)$row['batch'],
                'execution_ms' => (int)$row['execution_ms'],
                'applied_at' => (string)$row['applied_at'],
            ];
        }

        usort($status, static fn(array $left, array $right): int => strcmp((string)$left['version'], (string)$right['version']));
        return $status;
    }

    /** @return list<array<string,mixed>> */
    public function migrate(bool $dryRun = false): array
    {
        $initialStatus = $this->status();
        $this->assertNoDrift($initialStatus);
        $pending = array_values(array_filter(
            $initialStatus,
            static fn(array $row): bool => $row['state'] === 'pending'
        ));
        if ($dryRun || $pending === []) {
            return $pending;
        }

        $lockName = $this->lockName();
        $this->acquireLock($lockName);
        try {
            $this->ensureMetadataTable();
            $lockedStatus = $this->status();
            $this->assertNoDrift($lockedStatus);
            $pendingVersions = [];
            foreach ($lockedStatus as $row) {
                if ($row['state'] === 'pending') {
                    $pendingVersions[(string)$row['version']] = true;
                }
            }
            if ($pendingVersions === []) {
                return [];
            }

            $batch = $this->nextBatch();
            $appliedNow = [];
            $inspector = new SchemaInspector($this->db);
            $insert = $this->db->prepare(
                'INSERT INTO ' . self::TABLE
                . ' (version, migration, description, checksum, batch, execution_ms, applied_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );

            foreach ($this->discover() as $migration) {
                if (!isset($pendingVersions[$migration['version']])) {
                    continue;
                }

                $started = hrtime(true);
                try {
                    $override = $this->executionOverrides[$migration['version']] ?? null;
                    if (is_callable($override)) {
                        $override($this->db, $inspector, $migration);
                    } else {
                        ($migration['up'])($this->db, $inspector);
                    }
                } catch (Throwable $error) {
                    throw new RuntimeException(
                        'Migration ' . $migration['version'] . ' failed: ' . $error->getMessage(),
                        0,
                        $error
                    );
                }
                $executionMs = max(0, (int)round((hrtime(true) - $started) / 1_000_000));
                $insert->execute([
                    $migration['version'],
                    $migration['name'],
                    $migration['description'],
                    $migration['checksum'],
                    $batch,
                    $executionMs,
                ]);
                $appliedNow[] = $migration + [
                    'state' => 'applied',
                    'batch' => $batch,
                    'execution_ms' => $executionMs,
                    'applied_at' => date('Y-m-d H:i:s'),
                ];
            }

            return $appliedNow;
        } finally {
            $this->releaseLock($lockName);
        }
    }

    /** @param list<array<string,mixed>> $status */
    public function assertNoDrift(array $status): void
    {
        foreach ($status as $row) {
            if ($row['state'] === 'checksum_mismatch') {
                throw new RuntimeException('Applied migration checksum changed: ' . $row['version']);
            }
            if ($row['state'] === 'orphaned') {
                throw new RuntimeException('Applied migration file is missing: ' . $row['version']);
            }
        }
    }

    private static function isBaselineVersion(string $version): bool
    {
        $normalized = str_pad($version, 20, '0', STR_PAD_LEFT);
        $baseline = str_pad(self::BASELINE_VERSION, 20, '0', STR_PAD_LEFT);
        return strcmp($normalized, $baseline) <= 0;
    }

    public function ensureMetadataTable(): void
    {
        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'version VARCHAR(32) NOT NULL,'
            . 'migration VARCHAR(190) NOT NULL,'
            . 'description VARCHAR(255) NOT NULL,'
            . 'checksum CHAR(64) NOT NULL,'
            . 'batch INT UNSIGNED NOT NULL,'
            . 'execution_ms INT UNSIGNED NOT NULL DEFAULT 0,'
            . 'applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (version),'
            . 'KEY idx_ue_schema_migrations_batch (batch),'
            . 'KEY idx_ue_schema_migrations_applied (applied_at)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /** @return array<string,array<string,mixed>> */
    private function appliedMigrations(): array
    {
        $inspector = new SchemaInspector($this->db);
        if (!$inspector->tableExists(self::TABLE)) {
            return [];
        }

        $rows = $this->db->query(
            'SELECT version, migration, description, checksum, batch, execution_ms, applied_at '
            . 'FROM ' . self::TABLE . ' ORDER BY version'
        )->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[(string)$row['version']] = $row;
        }
        return $out;
    }

    private function nextBatch(): int
    {
        return (int)$this->db->query('SELECT COALESCE(MAX(batch), 0) + 1 FROM ' . self::TABLE)->fetchColumn();
    }

    private function lockName(): string
    {
        $database = (string)$this->db->query('SELECT DATABASE()')->fetchColumn();
        return 'unrealdb:migrations:' . substr(hash('sha256', $database), 0, 32);
    }

    private function acquireLock(string $lockName): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $statement->execute([$lockName, max(0, $this->lockTimeoutSeconds)]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire the database migration lock.');
        }
    }

    private function releaseLock(string $lockName): void
    {
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([$lockName]);
        } catch (Throwable) {
            // The database connection also releases advisory locks when it closes.
        }
    }
}
