<?php
/**
 * Backfill persisted GeoIP country snapshots for historical download audit rows.
 *
 * Uses only the locally imported ue_geoip_country_ranges table. No network
 * lookups are performed. Rows with private, reserved, unknown or uncovered IPs
 * remain blank intentionally.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

$batchSize = (int)($argv[1] ?? 5000);
$batchSize = max(100, min(50000, $batchSize));

$config = catalog_config();
$db = catalog_db($config);

function geoip_backfill_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() === 1;
}

function geoip_backfill_column_exists(PDO $db, string $table, string $column): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?'
    );
    $statement->execute([$table, $column]);
    return (int)$statement->fetchColumn() === 1;
}

if (!geoip_backfill_table_exists($db, 'ue_geoip_country_ranges')) {
    fwrite(STDERR, "GeoIP range storage is missing. Run database migrations first.\n");
    exit(1);
}

$rangeCount = (int)$db->query('SELECT COUNT(*) FROM ue_geoip_country_ranges')->fetchColumn();
if ($rangeCount < 1) {
    fwrite(STDERR, "GeoIP country ranges are empty. Import the DB-IP country CSV before backfilling logs.\n");
    exit(1);
}

echo 'GeoIP ranges available: ' . number_format($rangeCount) . ".\n";
echo 'Backfill batch size: ' . number_format($batchSize) . ".\n\n";

$targets = [
    ['table' => 'ue_download_audit', 'ip' => 'ip_address', 'label' => 'download audit'],
    ['table' => 'ue_generated_package_audit', 'ip' => 'request_ip', 'label' => 'generated-package audit'],
];

$overallCandidates = 0;
$overallUpdated = 0;
$overallStarted = microtime(true);

foreach ($targets as $target) {
    $table = $target['table'];
    $ipColumn = $target['ip'];
    $label = $target['label'];

    if (!geoip_backfill_table_exists($db, $table)) {
        echo "Skipping {$label}: table {$table} is not installed.\n";
        continue;
    }
    foreach ([$ipColumn, 'country_code', 'country_name'] as $column) {
        if (!geoip_backfill_column_exists($db, $table, $column)) {
            fwrite(STDERR, "Cannot backfill {$label}: {$table}.{$column} is missing. Run migrations first.\n");
            exit(1);
        }
    }

    $blankPredicate = '(country_code IS NULL OR country_code="" OR country_name IS NULL OR country_name="")';
    $countStatement = $db->query(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $ipColumn . ' IS NOT NULL AND ' . $blankPredicate
    );
    $candidateCount = (int)$countStatement->fetchColumn();
    $overallCandidates += $candidateCount;

    if ($candidateCount < 1) {
        echo ucfirst($label) . ": no historical rows need country backfill.\n";
        continue;
    }

    echo ucfirst($label) . ': ' . number_format($candidateCount) . " historical row(s) to inspect.\n";

    $cursor = 0;
    $processed = 0;
    $updated = 0;
    $started = microtime(true);

    $select = $db->prepare(
        'SELECT id FROM ' . $table . ' '
        . 'WHERE id>? AND ' . $ipColumn . ' IS NOT NULL AND ' . $blankPredicate . ' '
        . 'ORDER BY id LIMIT ' . $batchSize
    );
    $update = $db->prepare(
        'UPDATE ' . $table . ' a '
        . 'JOIN ue_geoip_country_ranges r ON '
        . 'r.ip_version=CASE OCTET_LENGTH(a.' . $ipColumn . ') WHEN 4 THEN 4 WHEN 16 THEN 6 ELSE 0 END '
        . 'AND r.range_start<=a.' . $ipColumn . ' AND r.range_end>=a.' . $ipColumn . ' '
        . 'SET a.country_code=r.country_code,a.country_name=r.country_name '
        . 'WHERE a.id>? AND a.id<=? AND a.' . $ipColumn . ' IS NOT NULL '
        . 'AND (a.country_code IS NULL OR a.country_code="" OR a.country_name IS NULL OR a.country_name="")'
    );

    while (true) {
        $select->execute([$cursor]);
        $ids = $select->fetchAll(PDO::FETCH_COLUMN);
        if ($ids === []) {
            break;
        }

        $endId = (int)end($ids);
        $update->execute([$cursor, $endId]);
        $updated += $update->rowCount();
        $processed += count($ids);
        $cursor = $endId;

        $elapsed = max(0.001, microtime(true) - $started);
        echo '  inspected ' . number_format($processed) . '/' . number_format($candidateCount)
            . '; resolved ' . number_format($updated)
            . '; ' . number_format($processed / $elapsed, 0) . " rows/s\n";
    }

    $unresolved = max(0, $candidateCount - $updated);
    $overallUpdated += $updated;
    echo ucfirst($label) . ' complete: ' . number_format($updated) . ' resolved';
    if ($unresolved > 0) {
        echo '; ' . number_format($unresolved) . ' left blank (private/reserved/unknown/uncovered IPs)';
    }
    echo ".\n\n";
}

$seconds = max(0.001, microtime(true) - $overallStarted);
echo 'GeoIP audit backfill complete: ' . number_format($overallUpdated) . ' of '
    . number_format($overallCandidates) . ' historical row(s) resolved in '
    . number_format($seconds, 2) . "s.\n";
