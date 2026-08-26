<?php
/**
 * Backfill persisted GeoIP country snapshots for historical download audit rows.
 *
 * Uses only the locally imported ue_geoip_country_ranges table. No network
 * lookups are performed. Rows with private, reserved, unknown or uncovered IPs
 * remain blank intentionally.
 *
 * Historical rows are resolved with the same indexed nearest-range lookup used
 * by live download ingestion. Audit rows are updated one primary key at a time
 * in autocommit mode so one live/locked row cannot stall an entire batch.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

$batchSize = (int)($argv[1] ?? 500);
$batchSize = max(100, min(50000, $batchSize));

$config = catalog_config();
$db = catalog_db($config);
$db->exec('SET SESSION innodb_lock_wait_timeout=1');
$resolver = new \UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeoIpCountryResolver($db);

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

function geoip_backfill_is_lock_conflict(Throwable $error): bool
{
    if (!$error instanceof PDOException) {
        return false;
    }
    $driverCode = (int)($error->errorInfo[1] ?? 0);
    return in_array($driverCode, [1205, 1213], true);
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
echo 'Backfill batch size: ' . number_format($batchSize) . ".\n";
echo "Audit-row lock wait: 1 second; locked rows are skipped and can be picked up by a later rerun.\n\n";

$targets = [
    ['table' => 'ue_download_audit', 'ip' => 'ip_address', 'label' => 'download audit'],
    ['table' => 'ue_generated_package_audit', 'ip' => 'request_ip', 'label' => 'generated-package audit'],
];

$overallCandidates = 0;
$overallUpdated = 0;
$overallLocked = 0;
$overallStarted = microtime(true);

/** @var array<string,array{country_code:string,country_name:string}> $countryCache */
$countryCache = [];

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
    $locked = 0;
    $uniqueLookups = 0;
    $started = microtime(true);

    $select = $db->prepare(
        'SELECT id,INET6_NTOA(' . $ipColumn . ') ip_text FROM ' . $table . ' '
        . 'WHERE id>? AND ' . $ipColumn . ' IS NOT NULL AND ' . $blankPredicate . ' '
        . 'ORDER BY id LIMIT ' . $batchSize
    );
    $update = $db->prepare(
        'UPDATE ' . $table . ' SET country_code=?,country_name=? '
        . 'WHERE id=? AND (country_code IS NULL OR country_code="" OR country_name IS NULL OR country_name="")'
    );

    while (true) {
        $select->execute([$cursor]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $ipText = trim((string)($row['ip_text'] ?? ''));
            if ($id < 1) {
                continue;
            }
            $cursor = max($cursor, $id);
            if ($ipText === '') {
                continue;
            }

            if (!array_key_exists($ipText, $countryCache)) {
                $countryCache[$ipText] = $resolver->resolve($ipText);
                $uniqueLookups++;
            }
            $country = $countryCache[$ipText];
            $countryCode = strtoupper(trim((string)($country['country_code'] ?? '')));
            $countryName = trim((string)($country['country_name'] ?? ''));
            if ($countryCode === '' || $countryName === '') {
                continue;
            }

            try {
                $update->execute([$countryCode, $countryName, $id]);
                $updated += $update->rowCount();
            } catch (Throwable $error) {
                if (geoip_backfill_is_lock_conflict($error)) {
                    $locked++;
                    continue;
                }
                throw $error;
            }
        }

        $processed += count($rows);
        $elapsed = max(0.001, microtime(true) - $started);
        echo '  inspected ' . number_format($processed) . '/' . number_format($candidateCount)
            . '; resolved ' . number_format($updated)
            . '; locked/skipped ' . number_format($locked)
            . '; new unique IP lookups ' . number_format($uniqueLookups)
            . '; ' . number_format($processed / $elapsed, 0) . " rows/s\n";
    }

    $leftBlank = max(0, $candidateCount - $updated);
    $overallUpdated += $updated;
    $overallLocked += $locked;
    echo ucfirst($label) . ' complete: ' . number_format($updated) . ' resolved';
    if ($leftBlank > 0) {
        echo '; ' . number_format($leftBlank) . ' left blank';
        if ($locked > 0) {
            echo ' (' . number_format($locked) . ' temporarily locked; remainder private/reserved/unknown/uncovered)';
        } else {
            echo ' (private/reserved/unknown/uncovered IPs)';
        }
    }
    echo ".\n\n";
}

$seconds = max(0.001, microtime(true) - $overallStarted);
echo 'GeoIP audit backfill complete: ' . number_format($overallUpdated) . ' of '
    . number_format($overallCandidates) . ' historical row(s) resolved in '
    . number_format($seconds, 2) . 's';
if ($overallLocked > 0) {
    echo '; ' . number_format($overallLocked) . ' row(s) were locked and can be retried by running this command again';
}
echo ".\n";
