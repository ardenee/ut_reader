<?php
/**
 * Widen the compact term dictionary and every durable term reference after the
 * historical duplicate-heavy INSERT IGNORE path exhausted the UINT32 ID space.
 *
 * IMPORTANT: these ALTER TABLE operations can rebuild very large lookup tables.
 * Background workers must be stopped before applying this migration.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\SchemaInspector;

return [
    'version' => '202609040001',
    'description' => 'Widen compact term IDs and all term-reference columns to BIGINT UNSIGNED.',
    'up' => static function (\PDO $db, SchemaInspector $schema): void {
        $running = $db->query(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        );
        if ((int)($running->fetchColumn() ?: 0) > 0) {
            throw new \RuntimeException(
                'Stop all Background Jobs workers before widening compact term IDs.'
            );
        }

        /**
         * Widen references first. A partially applied migration therefore leaves
         * BIGINT reference columns pointing at the still-INT dictionary, which is
         * valid and safe to resume. Widening ue_terms first would allow a writer
         * to allocate an ID that an unconverted reference column cannot store.
         *
         * @var array<string,array<string,bool>> $referenceColumns
         */
        $referenceColumns = [
            'ue_name_lookup' => [
                'name_term_id' => false,
            ],
            'ue_export_lookup' => [
                'object_term_id' => false,
                'class_term_id' => true,
                'local_path_term_id' => true,
            ],
            'ue_dependency_links' => [
                'required_package_term_id' => false,
                'required_object_term_id' => true,
                'import_class_package_term_id' => true,
                'import_class_name_term_id' => true,
                'import_object_term_id' => true,
                'resolution_source_term_id' => true,
                'resolution_confidence_term_id' => true,
            ],
        ];

        $isBigintUnsigned = static function (array $column): bool {
            $type = strtolower(trim((string)($column['COLUMN_TYPE'] ?? '')));
            return str_starts_with($type, 'bigint') && str_contains($type, 'unsigned');
        };
        $isIntUnsigned = static function (array $column): bool {
            $type = strtolower(trim((string)($column['COLUMN_TYPE'] ?? '')));
            return str_starts_with($type, 'int') && str_contains($type, 'unsigned');
        };

        foreach ($referenceColumns as $table => $columns) {
            $schema->requireTable($table);
            $clauses = [];
            foreach ($columns as $columnName => $nullable) {
                $column = $schema->column($table, $columnName);
                if (!is_array($column)) {
                    throw new \RuntimeException(
                        'Required compact term reference is missing: ' . $table . '.' . $columnName
                    );
                }
                if ($isBigintUnsigned($column)) {
                    continue;
                }
                if (!$isIntUnsigned($column)) {
                    throw new \RuntimeException(
                        'Unexpected type for ' . $table . '.' . $columnName . ': '
                        . (string)($column['COLUMN_TYPE'] ?? 'unknown')
                    );
                }
                $clauses[] = 'MODIFY COLUMN ' . $columnName . ' BIGINT UNSIGNED '
                    . ($nullable ? 'NULL' : 'NOT NULL');
            }

            if ($clauses !== []) {
                $db->exec('ALTER TABLE ' . $table . ' ' . implode(',', $clauses));
            }
        }

        $schema->requireTable('ue_terms');
        $idColumn = $schema->column('ue_terms', 'id');
        if (!is_array($idColumn)) {
            throw new \RuntimeException('Required compact term dictionary column is missing: ue_terms.id');
        }
        if (!$isBigintUnsigned($idColumn)) {
            if (!$isIntUnsigned($idColumn)) {
                throw new \RuntimeException(
                    'Unexpected type for ue_terms.id: ' . (string)($idColumn['COLUMN_TYPE'] ?? 'unknown')
                );
            }
            $db->exec(
                'ALTER TABLE ue_terms MODIFY COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
            );
        }

        $maxId = (int)($db->query('SELECT COALESCE(MAX(id),0) FROM ue_terms')->fetchColumn() ?: 0);
        $nextId = $maxId + 1;
        if ($nextId < 1) {
            throw new \RuntimeException('Could not determine the next BIGINT term ID.');
        }
        $db->exec('ALTER TABLE ue_terms AUTO_INCREMENT=' . $nextId);

        // DDL auto-commits and cannot be rolled back as one unit, so verify every
        // widened column before MigrationRunner records this version as applied.
        foreach ($referenceColumns as $table => $columns) {
            foreach ($columns as $columnName => $_nullable) {
                $column = $schema->column($table, $columnName);
                if (!is_array($column) || !$isBigintUnsigned($column)) {
                    throw new \RuntimeException(
                        'BIGINT term migration verification failed for ' . $table . '.' . $columnName
                    );
                }
            }
        }
        $idColumn = $schema->column('ue_terms', 'id');
        if (!is_array($idColumn) || !$isBigintUnsigned($idColumn)) {
            throw new \RuntimeException('BIGINT term migration verification failed for ue_terms.id');
        }
    },
];
