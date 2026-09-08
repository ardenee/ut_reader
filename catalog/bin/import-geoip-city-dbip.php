#!/usr/bin/env php
<?php
/**
 * Import DB-IP City Lite CSV into UnrealDB's local GeoIP range table.
 *
 * Expected DB-IP City Lite columns:
 *   ip_start,ip_end,continent,country,stateprov,city,latitude,longitude
 *
 * Plain CSV and .gz are supported. A staging table is built first and swapped
 * atomically so live lookups continue using the previous dataset while loading.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

$path = trim((string)($argv[1] ?? ''));
if ($path === '' || in_array($path, ['-h', '--help'], true)) {
    echo "Usage: php catalog/bin/import-geoip-city-dbip.php <dbip-city-lite-YYYY-MM.csv[.gz]>\n";
    exit($path === '' ? 1 : 0);
}
if (!is_file($path)) {
    fwrite(STDERR, "DB-IP City CSV was not found: {$path}\n");
    exit(1);
}

function geoip_dbip_city_coordinate(string $value, float $minimum, float $maximum): ?string
{
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < $minimum || $number > $maximum) {
        return null;
    }
    return number_format($number, 6, '.', '');
}

/**
 * @param list<array{0:int,1:string,2:string,3:string,4:string,5:?string,6:?string,7:?string,8:?string,9:?string,10:?string,11:?int}> $batch
 */
function geoip_dbip_city_flush(PDO $db, array &$batch): void
{
    if ($batch === []) {
        return;
    }

    $rowPlaceholder = '(' . implode(',', array_fill(0, 12, '?')) . ')';
    $statement = $db->prepare(
        'INSERT INTO ue_geoip_country_ranges_import('
        . 'ip_version,range_start,range_end,country_code,country_name,subdivision_code,subdivision_name,city_name,postal_code,latitude,longitude,accuracy_radius_km'
        . ') VALUES ' . implode(',', array_fill(0, count($batch), $rowPlaceholder))
    );

    $args = [];
    foreach ($batch as $row) {
        foreach ($row as $value) {
            $args[] = $value;
        }
    }
    $statement->execute($args);
    $batch = [];
}

$config = catalog_config();
$db = catalog_db($config);

$requiredColumns = $db->query(
    'SELECT COUNT(*) FROM information_schema.COLUMNS '
    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_geoip_country_ranges" '
    . 'AND COLUMN_NAME IN ("subdivision_code","subdivision_name","city_name","postal_code","latitude","longitude","accuracy_radius_km")'
);
if ($requiredColumns === false || (int)$requiredColumns->fetchColumn() !== 7) {
    fwrite(STDERR, "GeoIP City schema is not installed. Run: php catalog/bin/migrate.php migrate\n");
    exit(1);
}

// Preserve the human-readable country names already loaded by the country
// importer. DB-IP City Lite supplies ISO codes but not country names.
$countryNames = [];
try {
    $statement = $db->query(
        'SELECT country_code,MAX(country_name) country_name '
        . 'FROM ue_geoip_country_ranges WHERE country_code<>"" GROUP BY country_code'
    );
    if ($statement !== false) {
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $code = strtoupper(trim((string)($row['country_code'] ?? '')));
            $name = trim((string)($row['country_name'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code) === 1 && $name !== '') {
                $countryNames[$code] = substr($name, 0, 120);
            }
        }
    }
} catch (Throwable) {
}

$streamPath = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path;
$handle = @fopen($streamPath, 'rb');
if (!is_resource($handle)) {
    fwrite(STDERR, "Could not open DB-IP City CSV: {$path}\n");
    exit(1);
}

$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['unrealdb_geoip_city_import']);
if ((int)$lock->fetchColumn() !== 1) {
    fclose($handle);
    fwrite(STDERR, "Another GeoIP City import is already running.\n");
    exit(1);
}

$stageCreated = false;
$count = 0;
$skipped = 0;
$line = 0;
$started = microtime(true);

try {
    $db->exec('DROP TABLE IF EXISTS ue_geoip_country_ranges_import');
    $db->exec('CREATE TABLE ue_geoip_country_ranges_import LIKE ue_geoip_country_ranges');
    $stageCreated = true;

    $batch = [];
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        $line++;
        if (!is_array($row) || count($row) < 8) {
            continue;
        }

        $startText = trim((string)($row[0] ?? ''));
        $endText = trim((string)($row[1] ?? ''));
        $code = strtoupper(trim((string)($row[3] ?? '')));
        $region = trim((string)($row[4] ?? ''));
        $city = trim((string)($row[5] ?? ''));
        $latitude = geoip_dbip_city_coordinate((string)($row[6] ?? ''), -90.0, 90.0);
        $longitude = geoip_dbip_city_coordinate((string)($row[7] ?? ''), -180.0, 180.0);

        // Allow an optional conventional header row.
        if ($line === 1 && @inet_pton($startText) === false
            && preg_match('/(?:ip_?start|start|from)/i', $startText) === 1) {
            continue;
        }

        if ($code === 'ZZ') {
            $skipped++;
            continue;
        }
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new RuntimeException("Invalid country code on CSV line {$line}: {$code}");
        }

        $rangeStart = @inet_pton($startText);
        $rangeEnd = @inet_pton($endText);
        if (!is_string($rangeStart) || !is_string($rangeEnd)
            || !in_array(strlen($rangeStart), [4, 16], true)
            || strlen($rangeStart) !== strlen($rangeEnd)) {
            throw new RuntimeException(
                "Invalid/mixed IP range on CSV line {$line}: {$startText} - {$endText}"
            );
        }
        if (strcmp($rangeStart, $rangeEnd) > 0) {
            throw new RuntimeException(
                "Reversed IP range on CSV line {$line}: {$startText} - {$endText}"
            );
        }
        if ($latitude === null || $longitude === null) {
            $latitude = null;
            $longitude = null;
        }

        $countryName = $countryNames[$code] ?? '';
        if ($countryName === '' && class_exists(Locale::class)) {
            $display = trim((string)Locale::getDisplayRegion('-' . $code, 'en'));
            if ($display !== '' && strtoupper($display) !== $code) {
                $countryName = substr($display, 0, 120);
            }
        }
        if ($countryName === '') {
            $countryName = $code;
        }

        $batch[] = [
            strlen($rangeStart) === 4 ? 4 : 6,
            $rangeStart,
            $rangeEnd,
            $code,
            $countryName,
            null,
            $region !== '' ? substr($region, 0, 120) : null,
            $city !== '' ? substr($city, 0, 120) : null,
            null,
            $latitude,
            $longitude,
            null,
        ];

        $count++;
        if (count($batch) >= 500) {
            geoip_dbip_city_flush($db, $batch);
        }
        if ($count % 100000 === 0) {
            echo number_format($count) . " city ranges staged...\n";
        }
    }
    geoip_dbip_city_flush($db, $batch);
    fclose($handle);
    $handle = null;

    if ($count < 1) {
        throw new RuntimeException('The DB-IP City CSV contained no usable ranges.');
    }

    $db->exec('DROP TABLE IF EXISTS ue_geoip_country_ranges_previous');
    $db->exec(
        'RENAME TABLE '
        . 'ue_geoip_country_ranges TO ue_geoip_country_ranges_previous,'
        . 'ue_geoip_country_ranges_import TO ue_geoip_country_ranges'
    );
    $stageCreated = false;
    $db->exec('DROP TABLE ue_geoip_country_ranges_previous');

    $elapsed = max(0.001, microtime(true) - $started);
    echo 'DB-IP City import complete: ' . number_format($count) . ' ranges in '
        . number_format($elapsed, 2) . 's';
    if ($skipped > 0) {
        echo '; skipped ' . number_format($skipped) . ' unknown/unassigned ZZ range(s)';
    }
    echo ".\n";
} catch (Throwable $error) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    if ($stageCreated) {
        try {
            $db->exec('DROP TABLE IF EXISTS ue_geoip_country_ranges_import');
        } catch (Throwable) {
        }
    }
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['unrealdb_geoip_city_import']);
    fwrite(STDERR, 'DB-IP City import failed on/near line ' . $line . ': ' . $error->getMessage() . "\n");
    exit(1);
}

$release = $db->prepare('SELECT RELEASE_LOCK(?)');
$release->execute(['unrealdb_geoip_city_import']);
