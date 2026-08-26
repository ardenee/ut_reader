<?php
/**
 * Backfill persisted GeoIP country snapshots for historical download audit rows.
 *
 * Uses only the locally imported ue_geoip_country_ranges table. No network
 * lookups are performed. Rows with private, reserved, unknown or uncovered IPs
 * remain blank intentionally.
 *
 * Historical rows are resolved with the same indexed nearest-range lookup used
 * by live download ingestion. Do not join audit rows directly to every GeoIP
 * range: MySQL can choose a pathological range-join plan for that shape.
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

/**
 * Persist one resolved country for the supplied row ids. Chunk the IN list so
 * this stays bounded even when a large audit batch contains one dominant IP.
 *
 * @param list<int> $ids
 */
function geoip_backfill_update_ids(
    PDO $db,
    string $table,
    array $ids,
    string $countryCode,
    string $countryName
): int {
    $updated = 0;
    foreach (array_chunk($ids, 1000) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '?'));
        $statement = $db->prepare(
            'UPDATE ' . $table . ' SET country_code=?,country_name=? '
            . 'WHERE id IN (' . $placeholders . ') '
            . 'AND (country_code IS NULL OR country_code="" OR country_name IS NULL OR country_name="")'
        );
        $statement->execute(array_merge([$countryCode, $countryName], $chunk));
        $updated += $statement->rowCount();
    }
    return $updated;
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
    $uniqueLookups = 0;
    $started = microtime(true);

    $select = $db->prepare(
        'SELECT id,INET6_NTOA(' . $ipColumn . ') ip_text FROM ' . $table . ' '
        . 'WHERE id>? AND ' . $ipColumn . ' IS NOT NULL AND ' . $blankPredicate . ' '
        . 'ORDER BY id LIMIT ' . $batchSize
    );

    while (true) {
        $select->execute([$cursor]);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            break;
        }

        /** @var array<string,list<int>> $idsByIp */
        $idsByIp = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            $ipText = trim((string)($row['ip_text'] ?? ''));
            if ($id < 1) {
                continue;
            }
            $cursor = max($cursor, $id);
            if ($ipText !== '') {
                $idsByIp[$ipText][] = $id;
            }
        }

        /** @var array<string,array{code:string,name:string,ids:list<int>}> $idsByCountry */
        $idsByCountry = [];
        foreach ($idsByIp as $ipText => $ids) {
            $country = $resolver->resolve($ipText);
            $uniqueLookups++;
            $countryCode = strtoupper(trim((string)($country['country_code'] ?? '')));
            $countryName = trim((string)($country['country_name'] ?? ''));
            if ($countryCode === '' || $countryName === '') {
                continue;
            }
            $key = $countryCode . "\0" . $countryName;
            if (!isset($idsByCountry[$key])) {
                $idsByCountry[$key] = [
                    'code' => $countryCode,
                    'name' => $countryName,
                    'ids' => [],
                ];
            }
            array_push($idsByCountry[$key]['ids'], ...$ids);
        }

        try {
            $db->beginTransaction();
            foreach ($idsByCountry as $group) {
                $updated += geoip_backfill_update_ids(
                    $db,
                    $table,
                    $group['ids'],
                    $group['code'],
                    $group['name']
                );
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        $processed += count($rows);
        $elapsed = max(0.001, microtime(true) - $started);
        echo '  inspected ' . number_format($processed) . '/' . number_format($candidateCount)
            . '; resolved ' . number_format($updated)
            . '; unique IP lookups ' . number_format($uniqueLookups)
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
