<?php
/**
 * Import a local IP-to-country range dataset for download audit enrichment.
 *
 * Accepted CSV rows:
 *   start_ip,end_ip,country_code
 *   start_ip,end_ip,country_code,country_name
 *
 * Plain CSV and .gz files are supported. Three-column files require ext-intl
 * so the ISO country code can be expanded to a persisted English country name.
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
    echo "Usage: php catalog/bin/import-geoip-country-csv.php <country-ranges.csv[.gz]>\n";
    echo "CSV columns: start_ip,end_ip,country_code[,country_name]\n";
    exit($path === '' ? 1 : 0);
}
if (!is_file($path)) {
    fwrite(STDERR, "GeoIP CSV file was not found: {$path}\n");
    exit(1);
}

function geoip_country_name(string $code, string $provided): string
{
    $provided = trim($provided);
    if ($provided !== '') {
        return substr($provided, 0, 120);
    }
    if (class_exists(Locale::class)) {
        $name = trim((string)Locale::getDisplayRegion('-' . $code, 'en'));
        if ($name !== '' && strtoupper($name) !== $code) {
            return substr($name, 0, 120);
        }
    }
    throw new RuntimeException(
        'Country name is missing for ' . $code
        . '. Supply a fourth country_name CSV column or enable PHP ext-intl.'
    );
}

$config = catalog_config();
$db = catalog_db($config);
$tableExists = $db->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='ue_geoip_country_ranges'"
);
if (!$tableExists || (int)$tableExists->fetchColumn() !== 1) {
    fwrite(STDERR, "GeoIP storage is not installed. Run: php catalog/bin/migrate.php migrate\n");
    exit(1);
}

$streamPath = str_ends_with(strtolower($path), '.gz') ? 'compress.zlib://' . $path : $path;
$handle = @fopen($streamPath, 'rb');
if (!is_resource($handle)) {
    fwrite(STDERR, "Could not open GeoIP CSV: {$path}\n");
    exit(1);
}

$count = 0;
$line = 0;
$started = microtime(true);
try {
    $db->beginTransaction();
    $db->exec('DELETE FROM ue_geoip_country_ranges');
    $insert = $db->prepare(
        'INSERT INTO ue_geoip_country_ranges(ip_version,range_start,range_end,country_code,country_name) '
        . 'VALUES(?,?,?,?,?) '
        . 'ON DUPLICATE KEY UPDATE range_end=VALUES(range_end),country_code=VALUES(country_code),country_name=VALUES(country_name)'
    );

    while (($row = fgetcsv($handle)) !== false) {
        $line++;
        if (!is_array($row) || count($row) < 3) {
            continue;
        }
        $startText = trim((string)($row[0] ?? ''));
        $endText = trim((string)($row[1] ?? ''));
        $code = strtoupper(trim((string)($row[2] ?? '')));

        // Permit a conventional header row without requiring a command option.
        if ($line === 1 && preg_match('/(?:start|from|ip)/i', $startText) === 1 && @inet_pton($startText) === false) {
            continue;
        }
        if (preg_match('/^[A-Z]{2}$/', $code) !== 1) {
            throw new RuntimeException("Invalid country code on CSV line {$line}: {$code}");
        }
        $start = @inet_pton($startText);
        $end = @inet_pton($endText);
        if (!is_string($start) || !is_string($end) || !in_array(strlen($start), [4, 16], true) || strlen($start) !== strlen($end)) {
            throw new RuntimeException("Invalid/mixed IP range on CSV line {$line}: {$startText} - {$endText}");
        }
        if (strcmp($start, $end) > 0) {
            throw new RuntimeException("Reversed IP range on CSV line {$line}: {$startText} - {$endText}");
        }
        $name = geoip_country_name($code, (string)($row[3] ?? ''));

        $insert->bindValue(1, strlen($start) === 4 ? 4 : 6, PDO::PARAM_INT);
        $insert->bindValue(2, $start, PDO::PARAM_LOB);
        $insert->bindValue(3, $end, PDO::PARAM_LOB);
        $insert->bindValue(4, $code, PDO::PARAM_STR);
        $insert->bindValue(5, $name, PDO::PARAM_STR);
        $insert->execute();
        $count++;

        if ($count % 50000 === 0) {
            echo number_format($count) . " ranges imported...\n";
        }
    }
    if ($count < 1) {
        throw new RuntimeException('The GeoIP CSV contained no usable ranges.');
    }
    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fclose($handle);
    fwrite(STDERR, "GeoIP import failed on/near line {$line}: {$error->getMessage()}\n");
    exit(1);
}
fclose($handle);

$seconds = max(0.001, microtime(true) - $started);
echo 'GeoIP country import complete: ' . number_format($count) . ' ranges in '
    . number_format($seconds, 2) . "s.\n";
