#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return array<string,array<string,mixed>> */
function expected_dependency_summaries(PDO $db, int $fileId): array
{
    $statement = $db->prepare(
        'SELECT d.required_package,'
        . 'MIN(NULLIF(d.required_object_path,"")) example_required_object_path,'
        . 'COUNT(*) dependency_count,'
        . 'SUM(d.status="resolved") resolved_count,'
        . 'SUM(d.status="missing") missing_count,'
        . 'SUM(d.status="package_only") package_only_count,'
        . 'SUM(d.status="common") common_count,'
        . 'CASE '
        . 'WHEN SUM(d.status="missing")>0 THEN "missing" '
        . 'WHEN SUM(d.status="common")=COUNT(*) THEN "common" '
        . 'WHEN SUM(d.status="resolved")=COUNT(*) THEN "resolved" '
        . 'WHEN SUM(d.status IN ("resolved","package_only"))=COUNT(*) THEN "package_only" '
        . 'ELSE "mixed" END summary_status,'
        . 'CASE WHEN COUNT(DISTINCT d.resolved_file_id)=1 THEN MAX(d.resolved_file_id) ELSE NULL END provider_file_id '
        . 'FROM ue_dependencies d '
        . 'WHERE d.file_id=? AND d.required_package IS NOT NULL AND d.required_package<>"" '
        . 'GROUP BY d.required_package ORDER BY d.required_package'
    );
    $statement->execute([$fileId]);
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(string)$row['required_package']] = $row;
    }
    return $rows;
}

/** @return array<string,array<string,mixed>> */
function actual_dependency_summaries(PDO $db, int $fileId, bool $hasExamplePath): array
{
    $statement = $db->prepare(
        'SELECT required_package,'
        . ($hasExamplePath ? 'example_required_object_path,' : 'NULL example_required_object_path,')
        . 'dependency_count,resolved_count,missing_count,package_only_count,common_count,'
        . 'summary_status,provider_file_id '
        . 'FROM ue_dependency_package_summaries WHERE file_id=? ORDER BY required_package'
    );
    $statement->execute([$fileId]);
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $rows[(string)$row['required_package']] = $row;
    }
    return $rows;
}

function normalized_summary_row(array $row): array
{
    return [
        'required_package' => (string)($row['required_package'] ?? ''),
        'example_required_object_path' => $row['example_required_object_path'] !== null
            ? (string)$row['example_required_object_path']
            : null,
        'dependency_count' => (int)($row['dependency_count'] ?? 0),
        'resolved_count' => (int)($row['resolved_count'] ?? 0),
        'missing_count' => (int)($row['missing_count'] ?? 0),
        'package_only_count' => (int)($row['package_only_count'] ?? 0),
        'common_count' => (int)($row['common_count'] ?? 0),
        'summary_status' => (string)($row['summary_status'] ?? ''),
        'provider_file_id' => $row['provider_file_id'] !== null
            ? (int)$row['provider_file_id']
            : null,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $summary = new PdoDependencyPackageSummary($db);
    if (!$summary->available()) {
        throw new RuntimeException('ue_dependency_package_summaries is unavailable.');
    }

    $hasExamplePath = (bool)$db->query(
        'SELECT EXISTS('
        . 'SELECT 1 FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() '
        . 'AND TABLE_NAME="ue_dependency_package_summaries" '
        . 'AND COLUMN_NAME="example_required_object_path"'
        . ')'
    )->fetchColumn();

    $files = $db->query(
        'SELECT f.id,f.game_id,f.original_name '
        . 'FROM ue_file_metadata m JOIN ue_files f ON f.id=m.file_id '
        . 'WHERE m.format_version=2 AND f.scan_status="verified" ORDER BY f.id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $checkedFiles = 0;
    $checkedPackages = 0;
    $mismatchedFiles = 0;
    $mismatchedPackages = 0;
    $games = [];
    $details = [];

    foreach ($files as $file) {
        $fileId = (int)$file['id'];
        $expected = expected_dependency_summaries($db, $fileId);
        $summary->rebuildFile($fileId);
        $actual = actual_dependency_summaries($db, $fileId, $hasExamplePath);

        $checkedFiles++;
        $checkedPackages += count($expected);
        $games[(int)$file['game_id']] = true;
        $fileMismatch = false;
        $packages = array_values(array_unique(array_merge(array_keys($expected), array_keys($actual))));
        sort($packages, SORT_STRING);
        foreach ($packages as $package) {
            $expectedRow = isset($expected[$package]) ? normalized_summary_row($expected[$package]) : null;
            $actualRow = isset($actual[$package]) ? normalized_summary_row($actual[$package]) : null;
            if ($expectedRow !== $actualRow) {
                $fileMismatch = true;
                $mismatchedPackages++;
                if (count($details) < 20) {
                    $details[] = [
                        'file_id' => $fileId,
                        'original_name' => (string)$file['original_name'],
                        'required_package' => $package,
                        'expected' => $expectedRow,
                        'actual' => $actualRow,
                    ];
                }
            }
        }
        if ($fileMismatch) {
            $mismatchedFiles++;
        }
    }

    $stats = new PdoGameCatalogStats($db);
    $gamesRebuilt = 0;
    foreach (array_keys($games) as $gameId) {
        if ($stats->rebuildGame((int)$gameId) !== null) {
            $gamesRebuilt++;
        }
    }

    $result = [
        'checked_files' => $checkedFiles,
        'checked_packages' => $checkedPackages,
        'mismatched_files' => $mismatchedFiles,
        'mismatched_packages' => $mismatchedPackages,
        'games_rebuilt' => $gamesRebuilt,
        'details' => $details,
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($mismatchedFiles === 0 && $mismatchedPackages === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact summary refresh verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
