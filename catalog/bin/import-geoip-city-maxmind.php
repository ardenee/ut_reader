#!/usr/bin/env php
<?php
/**
 * Import an extracted MaxMind GeoLite2/GeoIP2 City CSV bundle into UnrealDB.
 *
 * The import is built in a staging table and swapped atomically when complete,
 * so live lookups continue using the previous dataset while the new dataset is
 * loading.
 *
 * Usage:
 *   php catalog/bin/import-geoip-city-maxmind.php C:\path\to\GeoLite2-City-CSV_YYYYMMDD
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/CatalogSupport.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command is CLI-only.\n");
    exit(1);
}

$root = trim((string)($argv[1] ?? ''));
if ($root === '' || in_array($root, ['-h', '--help'], true)) {
    echo "Usage: php catalog/bin/import-geoip-city-maxmind.php <extracted-city-csv-directory>\n";
    echo "Expected files: *-City-Blocks-IPv4.csv, *-City-Blocks-IPv6.csv and *-City-Locations-en.csv\n";
    exit($root === '' ? 1 : 0);
}
if (!is_dir($root)) {
    fwrite(STDERR, "GeoIP City directory was not found: {$root}\n");
    exit(1);
}

/** @return array<string,int> */
function geoip_city_header($handle, string $path): array
{
    $row = fgetcsv($handle, null, ',', '"', '');
    if (!is_array($row) || $row === []) {
        throw new RuntimeException("CSV header is missing: {$path}");
    }

    $header = [];
    foreach ($row as $index => $name) {
        $name = strtolower(trim((string)$name));
        if ($name !== '') {
            $header[$name] = (int)$index;
        }
    }
    return $header;
}

function geoip_city_cell(array $row, array $header, string $name): string
{
    $index = $header[strtolower($name)] ?? null;
    return $index === null ? '' : trim((string)($row[$index] ?? ''));
}

function geoip_city_open(string $path)
{
    $streamPath = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path;
    $handle = @fopen($streamPath, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException("Could not open GeoIP City CSV: {$path}");
    }
    return $handle;
}

function geoip_city_find(string $root, string $pattern): string
{
    $matches = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }
        $name = $file->getFilename();
        if (preg_match($pattern, $name) === 1) {
            $matches[] = $file->getPathname();
        }
    }
    sort($matches, SORT_NATURAL | SORT_FLAG_CASE);
    if ($matches === []) {
        throw new RuntimeException("Required GeoIP City file was not found under {$root}: {$pattern}");
    }
    return $matches[0];
}

/** @return array{0:string,1:string,2:int} */
function geoip_city_cidr_bounds(string $network): array
{
    $parts = explode('/', trim($network), 2);
    if (count($parts) !== 2) {
        throw new InvalidArgumentException("Invalid CIDR network: {$network}");
    }

    $packed = @inet_pton(trim($parts[0]));
    if (!is_string($packed) || !in_array(strlen($packed), [4, 16], true)) {
        throw new InvalidArgumentException("Invalid CIDR address: {$network}");
    }

    $bits = strlen($packed) * 8;
    $prefix = filter_var($parts[1], FILTER_VALIDATE_INT);
    if ($prefix === false || $prefix < 0 || $prefix > $bits) {
        throw new InvalidArgumentException("Invalid CIDR prefix: {$network}");
    }

    $bytes = array_values(unpack('C*', $packed));
    $start = [];
    $end = [];
    foreach ($bytes as $index => $byte) {
        $remaining = $prefix - ($index * 8);
        if ($remaining >= 8) {
            $mask = 0xFF;
        } elseif ($remaining <= 0) {
            $mask = 0;
        } else {
            $mask = (0xFF << (8 - $remaining)) & 0xFF;
        }
        $networkByte = $byte & $mask;
        $start[] = $networkByte;
        $end[] = $networkByte | ((~$mask) & 0xFF);
    }

    return [
        pack('C*', ...$start),
        pack('C*', ...$end),
        strlen($packed) === 4 ? 4 : 6,
    ];
}

function geoip_city_nullable_text(string $value, int $maximum): ?string
{
    $value = trim($value);
    return $value === '' ? null : substr($value, 0, $maximum);
}

function geoip_city_coordinate(string $value, float $minimum, float $maximum): ?string
{
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
function geoip_city_flush(PDO $db, array &$batch): void
{
    if ($batch === []) {
        return;
    }

    $columns = 12;
    $rowPlaceholder = '(' . implode(',', array_fill(0, $columns, '?')) . ')';
    $sql = 'INSERT INTO ue_geoip_country_ranges_import('
        . 'ip_version,range_start,range_end,country_code,country_name,subdivision_code,subdivision_name,city_name,postal_code,latitude,longitude,accuracy_radius_km'
        . ') VALUES ' . implode(',', array_fill(0, count($batch), $rowPlaceholder));

    $args = [];
    foreach ($batch as $row) {
        foreach ($row as $value) {
            $args[] = $value;
        }
    }

    $statement = $db->prepare($sql);
    $statement->execute($args);
    $batch = [];
}

$locationsPath = geoip_city_find($root, '/(?:GeoIP2|GeoLite2)-City-Locations-en\.csv(?:\.gz)?$/i');
$ipv4Path = geoip_city_find($root, '/(?:GeoIP2|GeoLite2)-City-Blocks-IPv4\.csv(?:\.gz)?$/i');
$ipv6Path = geoip_city_find($root, '/(?:GeoIP2|GeoLite2)-City-Blocks-IPv6\.csv(?:\.gz)?$/i');

echo "Locations: {$locationsPath}\n";
echo "IPv4: {$ipv4Path}\n";
echo "IPv6: {$ipv6Path}\n";

$locations = [];
$locationHandle = geoip_city_open($locationsPath);
try {
    $header = geoip_city_header($locationHandle, $locationsPath);
    if (!isset($header['geoname_id'], $header['country_iso_code'], $header['country_name'])) {
        throw new RuntimeException('MaxMind Locations-en CSV is missing required columns.');
    }

    while (($row = fgetcsv($locationHandle, null, ',', '"', '')) !== false) {
        if (!is_array($row)) {
            continue;
        }
        $id = (int)geoip_city_cell($row, $header, 'geoname_id');
        if ($id < 1) {
            continue;
        }
        $locations[$id] = [
            'country_code' => strtoupper(geoip_city_cell($row, $header, 'country_iso_code')),
            'country_name' => geoip_city_cell($row, $header, 'country_name'),
            'subdivision_code' => geoip_city_cell($row, $header, 'subdivision_1_iso_code'),
            'subdivision_name' => geoip_city_cell($row, $header, 'subdivision_1_name'),
            'city_name' => geoip_city_cell($row, $header, 'city_name'),
        ];
    }
} finally {
    fclose($locationHandle);
}
if ($locations === []) {
    fwrite(STDERR, "No usable location rows were loaded.\n");
    exit(1);
}
echo 'Loaded ' . number_format(count($locations)) . " MaxMind location records.\n";

$config = catalog_config();
$db = catalog_db($config);
$lock = $db->prepare('SELECT GET_LOCK(?,0)');
$lock->execute(['unrealdb_geoip_city_import']);
if ((int)$lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another GeoIP City import is already running.\n");
    exit(1);
}

$stageCreated = false;
$imported = 0;
$skipped = 0;
$started = microtime(true);

try {
    $requiredColumns = $db->query(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_geoip_country_ranges" '
        . 'AND COLUMN_NAME IN ("subdivision_code","subdivision_name","city_name","postal_code","latitude","longitude","accuracy_radius_km")'
    );
    if ($requiredColumns === false || (int)$requiredColumns->fetchColumn() !== 7) {
        throw new RuntimeException(
            'GeoIP City schema is not installed. Run: php catalog/bin/migrate.php migrate'
        );
    }

    $db->exec('DROP TABLE IF EXISTS ue_geoip_country_ranges_import');
    $db->exec('CREATE TABLE ue_geoip_country_ranges_import LIKE ue_geoip_country_ranges');
    $stageCreated = true;

    foreach ([$ipv4Path, $ipv6Path] as $blocksPath) {
        $handle = geoip_city_open($blocksPath);
        try {
            $header = geoip_city_header($handle, $blocksPath);
            if (!isset($header['network'])) {
                throw new RuntimeException("MaxMind blocks CSV is missing network column: {$blocksPath}");
            }

            $batch = [];
            $line = 1;
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $line++;
                if (!is_array($row)) {
                    continue;
                }

                $network = geoip_city_cell($row, $header, 'network');
                if ($network === '') {
                    continue;
                }

                try {
                    [$rangeStart, $rangeEnd, $ipVersion] = geoip_city_cidr_bounds($network);
                } catch (Throwable $error) {
                    throw new RuntimeException(
                        basename($blocksPath) . " line {$line}: " . $error->getMessage(),
                        0,
                        $error
                    );
                }

                $geoId = (int)geoip_city_cell($row, $header, 'geoname_id');
                $representedId = (int)geoip_city_cell($row, $header, 'represented_country_geoname_id');
                $registeredId = (int)geoip_city_cell($row, $header, 'registered_country_geoname_id');

                $place = $geoId > 0 ? ($locations[$geoId] ?? null) : null;
                $countryLocation = $representedId > 0 ? ($locations[$representedId] ?? null) : null;
                if (!is_array($countryLocation) && $registeredId > 0) {
                    $countryLocation = $locations[$registeredId] ?? null;
                }
                if (!is_array($countryLocation)) {
                    $countryLocation = $place;
                }
                if (!is_array($place)) {
                    $place = $countryLocation;
                }

                $countryCode = strtoupper(trim((string)($place['country_code'] ?? $countryLocation['country_code'] ?? '')));
                $countryName = trim((string)($place['country_name'] ?? $countryLocation['country_name'] ?? ''));
                if (preg_match('/^[A-Z]{2}$/', $countryCode) !== 1 || $countryName === '') {
                    $skipped++;
                    continue;
                }

                $latitude = geoip_city_coordinate(geoip_city_cell($row, $header, 'latitude'), -90.0, 90.0);
                $longitude = geoip_city_coordinate(geoip_city_cell($row, $header, 'longitude'), -180.0, 180.0);
                if ($latitude === null || $longitude === null) {
                    $latitude = null;
                    $longitude = null;
                }

                $accuracyText = geoip_city_cell($row, $header, 'accuracy_radius');
                $accuracy = $accuracyText !== '' && ctype_digit($accuracyText)
                    ? max(0, min(100000, (int)$accuracyText))
                    : null;

                $batch[] = [
                    $ipVersion,
                    $rangeStart,
                    $rangeEnd,
                    $countryCode,
                    substr($countryName, 0, 120),
                    geoip_city_nullable_text((string)($place['subdivision_code'] ?? ''), 32),
                    geoip_city_nullable_text((string)($place['subdivision_name'] ?? ''), 120),
                    geoip_city_nullable_text((string)($place['city_name'] ?? ''), 120),
                    geoip_city_nullable_text(geoip_city_cell($row, $header, 'postal_code'), 32),
                    $latitude,
                    $longitude,
                    $accuracy,
                ];

                if (count($batch) >= 500) {
                    geoip_city_flush($db, $batch);
                }
                $imported++;
                if ($imported % 100000 === 0) {
                    echo number_format($imported) . " city ranges staged...\n";
                }
            }
            geoip_city_flush($db, $batch);
        } finally {
            fclose($handle);
        }
    }

    if ($imported < 1) {
        throw new RuntimeException('The MaxMind City bundle contained no usable IP ranges.');
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
    echo 'GeoIP City import complete: ' . number_format($imported) . ' ranges in '
        . number_format($elapsed, 2) . 's';
    if ($skipped > 0) {
        echo '; skipped ' . number_format($skipped) . ' unresolved range(s)';
    }
    echo ".\n";
} catch (Throwable $error) {
    if ($stageCreated) {
        try {
            $db->exec('DROP TABLE IF EXISTS ue_geoip_country_ranges_import');
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'GeoIP City import failed: ' . $error->getMessage() . "\n");
    exitCode:
    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
    $release->execute(['unrealdb_geoip_city_import']);
    exit(1);
}

$release = $db->prepare('SELECT RELEASE_LOCK(?)');
$release->execute(['unrealdb_geoip_city_import']);
