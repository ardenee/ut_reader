<?php
/**
 * Import a local IP-to-country range dataset for download audit enrichment.
 *
 * Accepted CSV rows:
 *   start_ip,end_ip,country_code
 *   start_ip,end_ip,country_code,country_name
 *
 * Plain CSV and .gz files are supported. Country names are resolved from the
 * optional fourth column, PHP intl when available, or the built-in ISO map.
 * DB-IP's ZZ (unknown/unassigned) ranges are intentionally skipped so they do
 * not produce a fake country flag in the audit log.
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

/** @return array<string,string> */
function geoip_iso_country_names(): array
{
    static $names = null;
    if (is_array($names)) {
        return $names;
    }

    return $names = [
        'AD'=>'Andorra','AE'=>'United Arab Emirates','AF'=>'Afghanistan','AG'=>'Antigua and Barbuda','AI'=>'Anguilla',
        'AL'=>'Albania','AM'=>'Armenia','AO'=>'Angola','AQ'=>'Antarctica','AR'=>'Argentina','AS'=>'American Samoa',
        'AT'=>'Austria','AU'=>'Australia','AW'=>'Aruba','AX'=>'Åland Islands','AZ'=>'Azerbaijan','BA'=>'Bosnia and Herzegovina',
        'BB'=>'Barbados','BD'=>'Bangladesh','BE'=>'Belgium','BF'=>'Burkina Faso','BG'=>'Bulgaria','BH'=>'Bahrain',
        'BI'=>'Burundi','BJ'=>'Benin','BL'=>'Saint Barthélemy','BM'=>'Bermuda','BN'=>'Brunei','BO'=>'Bolivia',
        'BQ'=>'Caribbean Netherlands','BR'=>'Brazil','BS'=>'Bahamas','BT'=>'Bhutan','BV'=>'Bouvet Island','BW'=>'Botswana',
        'BY'=>'Belarus','BZ'=>'Belize','CA'=>'Canada','CC'=>'Cocos (Keeling) Islands','CD'=>'Democratic Republic of the Congo',
        'CF'=>'Central African Republic','CG'=>'Republic of the Congo','CH'=>'Switzerland','CI'=>'Côte d’Ivoire','CK'=>'Cook Islands',
        'CL'=>'Chile','CM'=>'Cameroon','CN'=>'China','CO'=>'Colombia','CR'=>'Costa Rica','CU'=>'Cuba','CV'=>'Cabo Verde',
        'CW'=>'Curaçao','CX'=>'Christmas Island','CY'=>'Cyprus','CZ'=>'Czechia','DE'=>'Germany','DJ'=>'Djibouti','DK'=>'Denmark',
        'DM'=>'Dominica','DO'=>'Dominican Republic','DZ'=>'Algeria','EC'=>'Ecuador','EE'=>'Estonia','EG'=>'Egypt','EH'=>'Western Sahara',
        'ER'=>'Eritrea','ES'=>'Spain','ET'=>'Ethiopia','FI'=>'Finland','FJ'=>'Fiji','FK'=>'Falkland Islands','FM'=>'Micronesia',
        'FO'=>'Faroe Islands','FR'=>'France','GA'=>'Gabon','GB'=>'United Kingdom','GD'=>'Grenada','GE'=>'Georgia','GF'=>'French Guiana',
        'GG'=>'Guernsey','GH'=>'Ghana','GI'=>'Gibraltar','GL'=>'Greenland','GM'=>'Gambia','GN'=>'Guinea','GP'=>'Guadeloupe',
        'GQ'=>'Equatorial Guinea','GR'=>'Greece','GS'=>'South Georgia and the South Sandwich Islands','GT'=>'Guatemala','GU'=>'Guam',
        'GW'=>'Guinea-Bissau','GY'=>'Guyana','HK'=>'Hong Kong','HM'=>'Heard Island and McDonald Islands','HN'=>'Honduras','HR'=>'Croatia',
        'HT'=>'Haiti','HU'=>'Hungary','ID'=>'Indonesia','IE'=>'Ireland','IL'=>'Israel','IM'=>'Isle of Man','IN'=>'India',
        'IO'=>'British Indian Ocean Territory','IQ'=>'Iraq','IR'=>'Iran','IS'=>'Iceland','IT'=>'Italy','JE'=>'Jersey','JM'=>'Jamaica',
        'JO'=>'Jordan','JP'=>'Japan','KE'=>'Kenya','KG'=>'Kyrgyzstan','KH'=>'Cambodia','KI'=>'Kiribati','KM'=>'Comoros',
        'KN'=>'Saint Kitts and Nevis','KP'=>'North Korea','KR'=>'South Korea','KW'=>'Kuwait','KY'=>'Cayman Islands','KZ'=>'Kazakhstan',
        'LA'=>'Laos','LB'=>'Lebanon','LC'=>'Saint Lucia','LI'=>'Liechtenstein','LK'=>'Sri Lanka','LR'=>'Liberia','LS'=>'Lesotho',
        'LT'=>'Lithuania','LU'=>'Luxembourg','LV'=>'Latvia','LY'=>'Libya','MA'=>'Morocco','MC'=>'Monaco','MD'=>'Moldova',
        'ME'=>'Montenegro','MF'=>'Saint Martin','MG'=>'Madagascar','MH'=>'Marshall Islands','MK'=>'North Macedonia','ML'=>'Mali',
        'MM'=>'Myanmar','MN'=>'Mongolia','MO'=>'Macao','MP'=>'Northern Mariana Islands','MQ'=>'Martinique','MR'=>'Mauritania',
        'MS'=>'Montserrat','MT'=>'Malta','MU'=>'Mauritius','MV'=>'Maldives','MW'=>'Malawi','MX'=>'Mexico','MY'=>'Malaysia',
        'MZ'=>'Mozambique','NA'=>'Namibia','NC'=>'New Caledonia','NE'=>'Niger','NF'=>'Norfolk Island','NG'=>'Nigeria','NI'=>'Nicaragua',
        'NL'=>'Netherlands','NO'=>'Norway','NP'=>'Nepal','NR'=>'Nauru','NU'=>'Niue','NZ'=>'New Zealand','OM'=>'Oman','PA'=>'Panama',
        'PE'=>'Peru','PF'=>'French Polynesia','PG'=>'Papua New Guinea','PH'=>'Philippines','PK'=>'Pakistan','PL'=>'Poland',
        'PM'=>'Saint Pierre and Miquelon','PN'=>'Pitcairn Islands','PR'=>'Puerto Rico','PS'=>'Palestine','PT'=>'Portugal','PW'=>'Palau',
        'PY'=>'Paraguay','QA'=>'Qatar','RE'=>'Réunion','RO'=>'Romania','RS'=>'Serbia','RU'=>'Russia','RW'=>'Rwanda','SA'=>'Saudi Arabia',
        'SB'=>'Solomon Islands','SC'=>'Seychelles','SD'=>'Sudan','SE'=>'Sweden','SG'=>'Singapore','SH'=>'Saint Helena',
        'SI'=>'Slovenia','SJ'=>'Svalbard and Jan Mayen','SK'=>'Slovakia','SL'=>'Sierra Leone','SM'=>'San Marino','SN'=>'Senegal',
        'SO'=>'Somalia','SR'=>'Suriname','SS'=>'South Sudan','ST'=>'São Tomé and Príncipe','SV'=>'El Salvador','SX'=>'Sint Maarten',
        'SY'=>'Syria','SZ'=>'Eswatini','TC'=>'Turks and Caicos Islands','TD'=>'Chad','TF'=>'French Southern Territories','TG'=>'Togo',
        'TH'=>'Thailand','TJ'=>'Tajikistan','TK'=>'Tokelau','TL'=>'Timor-Leste','TM'=>'Turkmenistan','TN'=>'Tunisia','TO'=>'Tonga',
        'TR'=>'Türkiye','TT'=>'Trinidad and Tobago','TV'=>'Tuvalu','TW'=>'Taiwan','TZ'=>'Tanzania','UA'=>'Ukraine','UG'=>'Uganda',
        'UM'=>'U.S. Outlying Islands','US'=>'United States','UY'=>'Uruguay','UZ'=>'Uzbekistan','VA'=>'Vatican City','VC'=>'Saint Vincent and the Grenadines',
        'VE'=>'Venezuela','VG'=>'British Virgin Islands','VI'=>'U.S. Virgin Islands','VN'=>'Vietnam','VU'=>'Vanuatu','WF'=>'Wallis and Futuna',
        'WS'=>'Samoa','XK'=>'Kosovo','YE'=>'Yemen','YT'=>'Mayotte','ZA'=>'South Africa','ZM'=>'Zambia','ZW'=>'Zimbabwe',
    ];
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
    $name = geoip_iso_country_names()[$code] ?? '';
    if ($name !== '') {
        return $name;
    }
    throw new RuntimeException(
        'Country name is missing for unsupported code ' . $code
        . '. Supply a fourth country_name CSV column.'
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
$skippedUnknown = 0;
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

    // Pass an explicit empty escape string for PHP 8.4+; relying on the old
    // backslash default is deprecated and changes CSV semantics in a future PHP.
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
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

        // DB-IP uses ZZ for unassigned/unknown address space. Do not persist it
        // as a country because the audit UI would otherwise render a misleading
        // pseudo-flag and tooltip.
        if ($code === 'ZZ') {
            $skippedUnknown++;
            continue;
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
        throw new RuntimeException('The GeoIP CSV contained no usable country ranges.');
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
    . number_format($seconds, 2) . 's';
if ($skippedUnknown > 0) {
    echo '; skipped ' . number_format($skippedUnknown) . ' unknown/unassigned ZZ range(s)';
}
echo ".\n";
