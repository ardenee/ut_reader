<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for unverified metadata repair.
 * Why: Existing procedural callers retain stable signatures while inventory and repair-job orchestration live under src/.
 * Role: Transitional legacy facade; new code should use CatalogUnverifiedMetadataRepairService directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/GameProfiles.php';

use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataRepairService;

/** @return list<array<string,mixed>> */
function catalog_unverified_metadata_inventory(PDO $db, array $config, int $sourceGameId = -1): array
{
    return (new CatalogUnverifiedMetadataRepairService($db, $config))->inventory($sourceGameId);
}

/** @param array<string,mixed>|null $row @return list<string> */
function catalog_unverified_metadata_missing_reasons(?array $row, int $physicalSize, string $path = ''): array
{
    // Historical standalone helper retained for compatibility. Keep the exact
    // completeness and tiny package-summary checks used before extraction.
    if ($row === null) {
        return ['Missing database inventory row'];
    }

    $reasons = [];
    $md5 = strtolower(trim((string)($row['md5'] ?? '')));
    $sha1 = strtolower(trim((string)($row['sha1'] ?? '')));
    $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? '')));
    $version = $row['detected_package_version'] ?? null;
    $notes = (string)($row['scan_notes'] ?? '');
    $alreadyAttempted = str_contains($notes, 'Metadata repair attempted:');

    if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) {
        $reasons[] = 'MD5 is missing';
    }
    if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
        $reasons[] = 'SHA-1 is missing';
    }
    if ($physicalSize < 1 || (int)($row['file_size'] ?? 0) !== $physicalSize) {
        $reasons[] = 'Stored size does not match the physical file';
    }
    if (trim((string)($row['package_name'] ?? '')) === '') {
        $reasons[] = 'Package name is missing';
    }
    if (trim((string)($row['extension'] ?? '')) === '') {
        $reasons[] = 'File extension is missing';
    }

    if ($path !== '' && is_file($path)) {
        $summary = gp_read_legacy_summary($path);
        if (!empty($summary['ok'])) {
            $headerEngine = strtoupper(trim((string)($summary['engine_hint'] ?? '')));
            $headerVersion = $summary['version'] ?? null;
            if ($headerEngine !== ''
                && $headerEngine !== 'UNKNOWN'
                && $engine !== ''
                && $engine !== 'UNKNOWN'
                && $headerEngine !== $engine) {
                $reasons[] = 'Stored engine ' . $engine . ' does not match package header ' . $headerEngine;
            }
            if (is_numeric($headerVersion)
                && is_numeric($version)
                && (int)$headerVersion !== (int)$version) {
                $reasons[] = 'Stored package version does not match the package header';
            }
        }
    }

    if (!$alreadyAttempted) {
        if ($engine === '' || $engine === 'UNKNOWN') {
            $reasons[] = 'Detected engine is missing';
        }
        if ($version === null || $version === '') {
            $reasons[] = 'Detected package version is missing';
        }
        if (in_array($engine, ['UE1', 'UE2', 'UE3'], true)
            && is_numeric($version)
            && (int)$version >= 68
            && trim((string)($row['package_guid'] ?? '')) === '') {
            $reasons[] = 'Package GUID is missing';
        }
    }

    $actualNameCount = (int)($row['actual_name_count'] ?? 0);
    $actualImportCount = (int)($row['actual_import_count'] ?? 0);
    $actualExportCount = (int)($row['actual_export_count'] ?? 0);
    foreach (['name', 'import', 'export'] as $table) {
        $declared = (int)($row[$table . '_count'] ?? 0);
        $actual = (int)($row['actual_' . $table . '_count'] ?? 0);
        if ($declared !== $actual) {
            $reasons[] = ucfirst($table) . ' count does not match stored rows';
        }
    }
    if (!$alreadyAttempted
        && in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)
        && $actualNameCount === 0
        && $actualImportCount === 0
        && $actualExportCount === 0) {
        $reasons[] = 'Package table inventory is empty';
    }

    return array_values(array_unique($reasons));
}

/** @return array{scope_count:int,candidate_count:int,job_ids:list<int>,queue:string} */
function catalog_queue_unverified_metadata_repairs(
    PDO $db,
    array $config,
    int $sourceGameId,
    ?int $createdBy = null
): array {
    return (new CatalogUnverifiedMetadataRepairService($db, $config))->queueRepairs(
        $sourceGameId,
        $createdBy
    );
}
