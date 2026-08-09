<?php
/**
 * Purpose: Removes legacy metadata rows owned exclusively by retired duplicate file records.
 * Why: Pre-format-2 duplicate retirements left historical Names/Imports/Exports/Dependencies behind after the
 *      active verified catalogue moved to compact metadata.
 * Role: One-time maintenance service for the final legacy-table retirement sequence.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use PDO;
use RuntimeException;
use Throwable;

final class RetiredDuplicateLegacyMetadataPurger
{
    public const CONFIRMATION = 'PURGE_RETIRED_DUPLICATE_LEGACY_METADATA';
    private const LOCK_NAME = 'unrealdb_retired_duplicate_legacy_purge_v1';
    private const TABLES = [
        'ue_dependencies',
        'ue_imports',
        'ue_exports',
        'ue_names',
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed> */
    public function preflight(): array
    {
        foreach (self::TABLES as $table) {
            if (!$this->tableExists($table)) {
                throw new RuntimeException('Required legacy table is missing: ' . $table . '.');
            }
        }

        $verifiedWithoutFormat2 = $this->scalar(
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" '
            . 'AND (m.file_id IS NULL OR m.format_version<>2)'
        );
        $verifiedCountMismatches = $this->scalar(
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND ('
            . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
        );

        $runningJobs = 0;
        try {
            $runningJobs = $this->scalar(
                'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
            );
        } catch (Throwable) {
            // Older/partial installations may not have the durable jobs table.
        }

        $tableRows = [];
        $totalRows = 0;
        $nonDuplicateRows = 0;
        $orphanRows = 0;
        foreach (self::TABLES as $table) {
            $total = $this->scalar('SELECT COUNT(*) FROM ' . $table);
            $duplicate = $total === 0 ? 0 : $this->scalar(
                'SELECT COUNT(*) FROM ' . $table . ' legacy '
                . 'JOIN ue_files f ON f.id=legacy.file_id '
                . 'WHERE f.scan_status="duplicate"'
            );
            $orphan = $total === 0 ? 0 : $this->scalar(
                'SELECT COUNT(*) FROM ' . $table . ' legacy '
                . 'LEFT JOIN ue_files f ON f.id=legacy.file_id WHERE f.id IS NULL'
            );
            $other = $total - $duplicate - $orphan;
            $tableRows[$table] = [
                'total' => $total,
                'duplicate' => $duplicate,
                'non_duplicate' => $other,
                'orphan' => $orphan,
            ];
            $totalRows += $total;
            $nonDuplicateRows += $other;
            $orphanRows += $orphan;
        }

        $duplicateFileIdsSql = $this->duplicateOwnerIdsSql();
        $duplicateOwners = $this->scalar('SELECT COUNT(*) FROM (' . $duplicateFileIdsSql . ') owners');
        $duplicateOwnersWithoutCanonical = $duplicateOwners === 0 ? 0 : $this->scalar(
            'SELECT COUNT(*) FROM (' . $duplicateFileIdsSql . ') owners '
            . 'JOIN ue_files d ON d.id=owners.file_id '
            . 'WHERE NOT EXISTS ('
            . 'SELECT 1 FROM ue_files canonical '
            . 'WHERE canonical.id<>d.id '
            . 'AND canonical.game_id=d.game_id '
            . 'AND canonical.scan_status="verified" '
            . 'AND canonical.package_guid=d.package_guid '
            . 'AND canonical.package_guid IS NOT NULL '
            . 'AND canonical.package_guid<>""'
            . ')'
        );

        $blockers = [];
        if ($verifiedWithoutFormat2 !== 0) {
            $blockers[] = $verifiedWithoutFormat2 . ' verified file(s) still lack format-2 metadata.';
        }
        if ($verifiedCountMismatches !== 0) {
            $blockers[] = $verifiedCountMismatches . ' verified format-2 count mismatch(es) remain.';
        }
        if ($runningJobs !== 0) {
            $blockers[] = $runningJobs . ' background job(s) are running.';
        }
        if ($nonDuplicateRows !== 0) {
            $blockers[] = $nonDuplicateRows . ' legacy row(s) belong to non-duplicate file records.';
        }
        if ($orphanRows !== 0) {
            $blockers[] = $orphanRows . ' orphan legacy row(s) remain.';
        }
        if ($duplicateOwnersWithoutCanonical !== 0) {
            $blockers[] = $duplicateOwnersWithoutCanonical
                . ' retired duplicate file(s) do not have an active verified canonical file with the same game/GUID.';
        }

        return [
            'safe_to_apply' => $blockers === [],
            'blockers' => $blockers,
            'verified_without_format2' => $verifiedWithoutFormat2,
            'verified_count_mismatches' => $verifiedCountMismatches,
            'running_background_jobs' => $runningJobs,
            'legacy_rows_total' => $totalRows,
            'legacy_rows' => $tableRows,
            'retired_duplicate_files' => $duplicateOwners,
            'duplicates_without_active_canonical' => $duplicateOwnersWithoutCanonical,
            'ue_files_rows_will_be_deleted' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public function purge(string $confirmation): array
    {
        if (!hash_equals(self::CONFIRMATION, trim($confirmation))) {
            throw new RuntimeException('Incorrect apply token. No rows were changed.');
        }

        $before = $this->preflight();
        if (empty($before['safe_to_apply'])) {
            throw new RuntimeException('Preflight failed: ' . implode(' ', (array)$before['blockers']));
        }

        $this->acquireLock();
        $started = microtime(true);
        $removed = array_fill_keys(self::TABLES, 0);
        try {
            // Re-run the preflight while holding the advisory lock so this operation
            // cannot race another copy of itself.
            $lockedPreflight = $this->preflight();
            if (empty($lockedPreflight['safe_to_apply'])) {
                throw new RuntimeException(
                    'Locked preflight failed: ' . implode(' ', (array)$lockedPreflight['blockers'])
                );
            }

            $duplicateRowsBefore = $this->scalar('SELECT COUNT(*) FROM ue_files WHERE scan_status="duplicate"');

            $this->db->beginTransaction();
            try {
                foreach (self::TABLES as $table) {
                    $statement = $this->db->prepare(
                        'DELETE legacy FROM ' . $table . ' legacy '
                        . 'JOIN ue_files f ON f.id=legacy.file_id '
                        . 'WHERE f.scan_status="duplicate"'
                    );
                    $statement->execute();
                    $removed[$table] = $statement->rowCount();
                }

                foreach (self::TABLES as $table) {
                    if ($this->scalar('SELECT COUNT(*) FROM ' . $table) !== 0) {
                        throw new RuntimeException(
                            'Legacy table ' . $table . ' is not empty after duplicate metadata purge.'
                        );
                    }
                }

                $duplicateRowsAfter = $this->scalar('SELECT COUNT(*) FROM ue_files WHERE scan_status="duplicate"');
                if ($duplicateRowsAfter !== $duplicateRowsBefore) {
                    throw new RuntimeException(
                        'Duplicate ue_files row count changed unexpectedly: before=' . $duplicateRowsBefore
                        . ', after=' . $duplicateRowsAfter . '.'
                    );
                }

                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $error;
            }

            $after = $this->preflight();
            return [
                'ok' => true,
                'removed_rows' => $removed,
                'removed_rows_total' => array_sum($removed),
                'retired_duplicate_files_preserved' => (int)$before['retired_duplicate_files'],
                'ue_files_rows_deleted' => 0,
                'legacy_rows_remaining' => (int)$after['legacy_rows_total'],
                'elapsed_seconds' => round(microtime(true) - $started, 2),
            ];
        } finally {
            $this->releaseLock();
        }
    }

    private function duplicateOwnerIdsSql(): string
    {
        $parts = [];
        foreach (self::TABLES as $table) {
            $parts[] = 'SELECT file_id FROM ' . $table;
        }
        return 'SELECT DISTINCT file_id FROM (' . implode(' UNION ALL ', $parts) . ') legacy_owner_ids';
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
        );
        $statement->execute([$table]);
        return (bool)$statement->fetchColumn();
    }

    /** @param array<int,mixed> $args */
    private function scalar(string $sql, array $args = []): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return (int)($statement->fetchColumn() ?: 0);
    }

    private function acquireLock(): void
    {
        $statement = $this->db->prepare('SELECT GET_LOCK(?,0)');
        $statement->execute([self::LOCK_NAME]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another retired-duplicate legacy purge is already running.');
        }
    }

    private function releaseLock(): void
    {
        try {
            $statement = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $statement->execute([self::LOCK_NAME]);
        } catch (Throwable) {
            // Closing the connection also releases the advisory lock.
        }
    }
}
