<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs schema-aware OPTIMIZE TABLE maintenance after Game Manager reset/delete operations.
 * Why: information_schema probes and database maintenance result parsing should not live in lifecycle orchestration.
 * Role: Infrastructure PDO collaborator for current catalogue tables, progress and warning behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use RuntimeException;
use Throwable;

final class PdoCatalogGameTableMaintenance
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function exists(string $table): bool
    {
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    /** @return list<string> */
    public static function tableList(bool $deleteGame): array
    {
        $tables = [
            'ue_asset_registry_tags',
            'ue_asset_registry_dependencies',
            'ue_asset_registry_assets',
            'ue_dependency_package_summaries',
            'ue_dependency_links',
            'ue_export_lookup',
            'ue_file_metadata',
            'ue_unverified_metadata',
            'ue_package_providers',
            'ue_external_mirror_jobs',
            'ue_external_download_links',
            'ue_source_file_fingerprints',
            'ue_file_locations',
            'ue_file_package_aliases',
            'ue_pak_entries',
            'ue_pak_archives',
            'ue_game_catalog_stats',
            'ue_files',
        ];
        if ($deleteGame) {
            $tables[] = 'ue_base_game_files';
            $tables[] = 'ue_sources';
            $tables[] = 'ue_federation_peer_files';
            $tables[] = 'ue_games';
        }
        return array_values(array_unique($tables));
    }

    /**
     * @param list<string> $tables
     * @param null|callable(array<string,mixed>):void $progress
     * @return array{optimised:list<string>,failed:array<string,string>}
     */
    public function optimiseTables(array $tables, ?callable $progress, int $startPercent, int $endPercent): array
    {
        $existing = [];
        foreach ($tables as $table) {
            if ($this->exists($table)) $existing[] = $table;
        }

        $total = max(1, count($existing));
        $optimised = [];
        $failed = [];
        foreach ($existing as $index => $table) {
            $before = $startPercent + (int)floor((($endPercent - $startPercent) * $index) / $total);
            CatalogGameLifecycleProgress::emit(
                $progress,
                'optimise',
                $index,
                $total,
                $before,
                'Optimising database table ' . ($index + 1) . '/' . $total . ': ' . $table
            );
            try {
                $this->optimiseTable($table);
                $optimised[] = $table;
            } catch (Throwable $error) {
                $failed[$table] = $error->getMessage();
                error_log('[UnrealDB game lifecycle] OPTIMIZE TABLE ' . $table . ' failed: ' . $error->getMessage());
            }
        }

        CatalogGameLifecycleProgress::emit(
            $progress,
            'optimise',
            count($existing),
            $total,
            $endPercent,
            $failed === []
                ? 'Database table optimisation complete.'
                : 'Database optimisation completed with ' . count($failed) . ' warning(s).'
        );
        return ['optimised' => $optimised, 'failed' => $failed];
    }

    private function optimiseTable(string $table): void
    {
        $statement = $this->db->query('OPTIMIZE TABLE `' . str_replace('`', '``', $table) . '`');
        if ($statement === false) {
            throw new RuntimeException('OPTIMIZE TABLE returned no result.');
        }
        $reportedErrors = [];
        do {
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $type = strtolower(trim((string)($row['Msg_type'] ?? $row['msg_type'] ?? '')));
                if ($type === 'error') {
                    $reportedErrors[] = trim((string)($row['Msg_text'] ?? $row['msg_text'] ?? 'Unknown database optimisation error.'));
                }
            }
        } while ($statement->nextRowset());
        $statement->closeCursor();
        if ($reportedErrors !== []) {
            throw new RuntimeException(implode('; ', array_unique($reportedErrors)));
        }
    }
}
