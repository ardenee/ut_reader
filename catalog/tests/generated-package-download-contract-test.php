<?php
declare(strict_types=1);

function generated_download_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$download = file_get_contents(__DIR__ . '/../generated-package-download.php');
$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');
$umod = file_get_contents(__DIR__ . '/../lib/LegacyUmodPackageBuilder.php');

foreach (compact('download', 'handler', 'umod') as $name => $source) {
    generated_download_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

generated_download_expect(
    str_contains($download, "\$_SERVER['HTTP_RANGE']")
        && str_contains($download, "http_response_code(206)")
        && str_contains($download, "http_response_code(416)")
        && str_contains($download, "header('Accept-Ranges: bytes')")
        && str_contains($download, "header('Content-Range: bytes ")
        && str_contains($download, "hash_file('sha256', \$path)")
        && str_contains($download, "apache_setenv('no-gzip', '1')")
        && str_contains($download, 'ob_end_clean()')
        && str_contains($download, "header('X-Accel-Buffering: no')"),
    'Generated package downloads are not resumable, integrity checked and binary safe.'
);

generated_download_expect(
    str_contains($handler, "LegacyUmodPackageBuilder.php")
        && str_contains($handler, 'modpkg_write_compatible_umod'),
    'Generated UMOD-family jobs are not using the compatible writer.'
);

generated_download_expect(
    str_contains($umod, '0x04C11DB7')
        && str_contains($umod, 'modpkg_unreal_mem_crc_stream')
        && str_contains($umod, "['system\\\\manifest.ini', 'system\\\\manifest.int']")
        && str_contains($umod, "'flags' => in_array")
        && str_contains($umod, "? 3 : 0"),
    'UMOD output does not use the legacy Unreal checksum and manifest flags.'
);

require_once __DIR__ . '/../lib/ModPackageBuilder.php';
require_once __DIR__ . '/../lib/LegacyUmodPackageBuilder.php';
generated_download_expect(
    modpkg_unreal_mem_crc('123456789') === 0xFC891918,
    'The legacy Unreal checksum does not match the established test vector.'
);

echo "Generated package download contract tests passed.\n";
