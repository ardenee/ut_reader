<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Performs schema-aware maintenance for game lifecycle cleanup tables.
 * Why: information_schema probes and OPTIMIZE TABLE behavior should not be embedded in reset/delete orchestration.
 * Role: Infrastructure PDO collaborator for game lifecycle maintenance.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;

final class PdoCatalogGameTableMaintenance
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function exists(string $table): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
        );
        $statement->execute([$table]);
        return (int)$statement->fetchColumn() > 0;
    }

    /** @return array{table:string,optimized:bool,message:string} */
    public function compact(string $table): array
    {
        if (!$this->exists($table)) {
            return [
                'table' => $table,
                'optimized' => false,
                'message' => 'Table does not exist.',
            ];
        }
        try {
            $this->db->exec('OPTIMIZE TABLE `' . str_replace('`', '``', $table) . '`');
            return [
                'table' => $table,
                'optimized' => true,
                'message' => 'Table optimized.',
            ];
        } catch (\Throwable $error) {
            error_log('[UnrealDB compact ' . $table . '] ' . $error->getMessage());
            return [
                'table' => $table,
                'optimized' => false,
                'message' => $error->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed>|null */
    public function compactHistory(): ?array
    {
        foreach (['ue_scan_run_files', 'ue_uploads', 'ue_scan_runs'] as $table) {
            if (!$this->exists($table)) {
                continue;
            }
            $result = $this->compact($table);
            $result['table'] = $table;
            return $result;
        }
        return null;
    }
}
