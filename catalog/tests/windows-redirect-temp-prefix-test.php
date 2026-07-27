<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogNonBlockingImportJobHandler;

require_once __DIR__ . '/../bootstrap/autoload.php';

function windows_redirect_temp_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$temporaryRoot = realpath(sys_get_temp_dir());
windows_redirect_temp_expect(is_string($temporaryRoot) && $temporaryRoot !== '', 'System temporary directory is unavailable.');

$source = tempnam(sys_get_temp_dir(), 'ue_');
windows_redirect_temp_expect(is_string($source) && is_file($source), 'Could not create redirect-prefix test file.');
file_put_contents($source, 'redirect-payload');

$reflection = new ReflectionClass(CatalogNonBlockingImportJobHandler::class);
$handler = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('normalizePreparedTemporaryPath');
$method->setAccessible(true);

$result = $method->invoke($handler, [
    'path' => $source,
    'filename' => 'Example.utx',
    'bytes' => 16,
]);
$prepared = (string)($result['path'] ?? '');

try {
    windows_redirect_temp_expect($prepared !== '' && is_file($prepared), 'Prepared redirect file was not retained.');
    windows_redirect_temp_expect(
        str_starts_with(basename($prepared), 'ue_redirect_'),
        'Prepared redirect file does not use the full controlled prefix.'
    );
    windows_redirect_temp_expect(
        realpath(dirname($prepared)) === $temporaryRoot,
        'Prepared redirect file moved outside the system temporary directory.'
    );
    windows_redirect_temp_expect(
        file_get_contents($prepared) === 'redirect-payload',
        'Prepared redirect file contents changed during prefix normalization.'
    );
    if ($prepared !== $source) {
        windows_redirect_temp_expect(!file_exists($source), 'Original short-prefix temporary file was not renamed.');
    }
} finally {
    if ($prepared !== '' && is_file($prepared)) {
        @unlink($prepared);
    }
    if (is_file($source)) {
        @unlink($source);
    }
}

fwrite(STDOUT, "Windows redirect temporary-prefix test passed.\n");
