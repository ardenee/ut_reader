<?php
/**
 * Purpose: Physically removes the four expanded metadata tables after the compact/staging cutover is complete.
 * Why: Verified files are format-2-only and unverified files now use ue_unverified_metadata; retaining empty legacy tables wastes schema surface and permits accidental regressions.
 * Role: Final guarded destructive migration for metadata-table retirement.
 */
declare(strict_types=1);

return [
    'version' => '202608090002',
    'description' => 'Drop empty retired Names/Imports/Exports/Dependencies tables after compact metadata cutover.',
    'up' => static function (
        PDO $db,
        \UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector $schema
    ): void {
        foreach ([
            'ue_files',
            'ue_file_metadata',
            'ue_terms',
            'ue_export_lookup',
            'ue_dependency_links',
            'ue_unverified_metadata',
        ] as $requiredTable) {
            $schema->requireTable($requiredTable);
        }

        $scalar = static function (PDO $db, string $sql, array $args = []): int {
            $statement = $db->prepare($sql);
            $statement->execute($args);
            return (int)($statement->fetchColumn() ?: 0);
        };

        $verifiedWithoutFormat2 = $scalar(
            $db,
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="verified" '
            . 'AND (m.file_id IS NULL OR m.format_version<>2)'
        );
        if ($verifiedWithoutFormat2 !== 0) {
            throw new RuntimeException(
                'Legacy metadata tables cannot be dropped: '
                . $verifiedWithoutFormat2 . ' verified file(s) are missing format-2 metadata.'
            );
        }

        $verifiedCountMismatches = $scalar(
            $db,
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND ('
            . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
        );
        if ($verifiedCountMismatches !== 0) {
            throw new RuntimeException(
                'Legacy metadata tables cannot be dropped: '
                . $verifiedCountMismatches . ' verified format-2 count mismatch(es) remain.'
            );
        }

        $missingUnverifiedStaging = $scalar(
            $db,
            'SELECT COUNT(*) FROM ue_files f '
            . 'LEFT JOIN ue_unverified_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="unverified" AND m.file_id IS NULL'
        );
        if ($missingUnverifiedStaging !== 0) {
            throw new RuntimeException(
                'Legacy metadata tables cannot be dropped: '
                . $missingUnverifiedStaging . ' unverified file(s) lack compressed staging metadata.'
            );
        }

        $unverifiedCountMismatches = $scalar(
            $db,
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_unverified_metadata m ON m.file_id=f.id '
            . 'WHERE f.scan_status="unverified" AND ('
            . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
        );
        if ($unverifiedCountMismatches !== 0) {
            throw new RuntimeException(
                'Legacy metadata tables cannot be dropped: '
                . $unverifiedCountMismatches . ' unverified staging count mismatch(es) remain.'
            );
        }

        if ($schema->tableExists('ue_background_jobs')) {
            // Queued jobs are safe: when they are eventually claimed they execute
            // the current compact-only worker code. Only a job already running may
            // still have an older PHP process/code image in memory.
            $runningJobs = $scalar(
                $db,
                'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
            );
            if ($runningJobs !== 0) {
                throw new RuntimeException(
                    'Legacy metadata tables cannot be dropped while ' . $runningJobs
                    . ' background job(s) are currently running.'
                );
            }
        }

        $legacyTables = [
            'ue_dependencies',
            'ue_imports',
            'ue_exports',
            'ue_names',
        ];
        foreach ($legacyTables as $table) {
            if (!$schema->tableExists($table)) {
                continue;
            }
            $rows = $scalar($db, 'SELECT COUNT(*) FROM `' . $table . '`');
            if ($rows !== 0) {
                throw new RuntimeException(
                    'Legacy metadata table ' . $table . ' is not empty (' . $rows . ' row(s)); refusing destructive retirement.'
                );
            }
        }

        $externalReferences = $db->prepare(
            'SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME '
            . 'FROM information_schema.KEY_COLUMN_USAGE '
            . 'WHERE TABLE_SCHEMA=DATABASE() '
            . 'AND REFERENCED_TABLE_NAME IN ("ue_dependencies","ue_imports","ue_exports","ue_names") '
            . 'AND TABLE_NAME NOT IN ("ue_dependencies","ue_imports","ue_exports","ue_names") '
            . 'ORDER BY TABLE_NAME,COLUMN_NAME'
        );
        $externalReferences->execute();
        $references = $externalReferences->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($references !== []) {
            $labels = array_map(
                static fn(array $row): string => (string)$row['TABLE_NAME'] . '.' . (string)$row['COLUMN_NAME']
                    . ' -> ' . (string)$row['REFERENCED_TABLE_NAME'],
                $references
            );
            throw new RuntimeException(
                'Legacy metadata tables still have external foreign-key references: ' . implode(', ', $labels) . '.'
            );
        }

        // Dependency rows reference Imports/Exports, so remove that table first.
        foreach ($legacyTables as $table) {
            if ($schema->tableExists($table)) {
                $db->exec('DROP TABLE `' . $table . '`');
            }
        }

        $remaining = [];
        foreach ($legacyTables as $table) {
            if ($schema->tableExists($table)) {
                $remaining[] = $table;
            }
        }
        if ($remaining !== []) {
            throw new RuntimeException(
                'Legacy metadata table retirement was incomplete: ' . implode(', ', $remaining) . '.'
            );
        }
    },
];
