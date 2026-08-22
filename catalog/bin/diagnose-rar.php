#!/usr/bin/env php
<?php
/**
 * Read-only PECL-rar diagnostic for retained RAR archives.
 *
 * Uses only the in-process PHP rar extension. No shell command or external
 * WinRAR/7-Zip executable is launched.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

function rar_diag_usage(): never
{
    fwrite(STDERR, "Usage: php catalog/bin/diagnose-rar.php <archive-path>\n");
    exit(2);
}

function rar_diag_warning(callable $callback, string &$warning): mixed
{
    $warning = '';
    set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
        $warning = trim($message);
        return true;
    });
    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

function rar_diag_method(object $entry, string $method, mixed $default = null): mixed
{
    if (!method_exists($entry, $method)) {
        return $default;
    }
    try {
        return $entry->{$method}();
    } catch (Throwable) {
        return $default;
    }
}

function rar_diag_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    return get_class($error) . ($message !== '' ? ': ' . $message : '');
}

function rar_diag_magic(string $prefix): string
{
    if (strlen($prefix) >= 4 && substr($prefix, 0, 4) === "\xC1\x83\x2A\x9E") {
        return 'UE package magic C1832A9E';
    }
    return $prefix === '' ? 'empty' : 'prefix=' . strtoupper(bin2hex($prefix));
}

function rar_diag_stream(object $entry, int $expected): array
{
    $warning = '';
    $stream = rar_diag_warning(static fn() => $entry->getStream(), $warning);
    if (!is_resource($stream)) {
        throw new RuntimeException(
            'getStream() returned no resource' . ($warning !== '' ? '; warning=' . $warning : '')
        );
    }

    $bytes = 0;
    $prefix = '';
    $hash = hash_init('sha256');
    try {
        while ($expected > 0 ? $bytes < $expected : !feof($stream)) {
            $take = 1024 * 1024;
            if ($expected > 0) {
                $take = min($take, $expected - $bytes);
            }
            $readWarning = '';
            $chunk = rar_diag_warning(static fn() => fread($stream, $take), $readWarning);
            if (!is_string($chunk)) {
                throw new RuntimeException(
                    'fread() failed after ' . number_format($bytes) . ' bytes'
                    . ($readWarning !== '' ? '; warning=' . $readWarning : '')
                );
            }
            if ($chunk === '') {
                if ($expected < 1 && feof($stream)) {
                    break;
                }
                throw new RuntimeException(
                    'RAR stream stopped after ' . number_format($bytes) . ' bytes'
                    . ($readWarning !== '' ? '; warning=' . $readWarning : '')
                );
            }
            if (strlen($prefix) < 16) {
                $prefix .= substr($chunk, 0, 16 - strlen($prefix));
            }
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
        }
    } finally {
        fclose($stream);
    }

    return [
        'bytes' => $bytes,
        'sha256' => hash_final($hash),
        'prefix' => $prefix,
    ];
}

function rar_diag_extract(object $entry): array
{
    $temporary = tempnam(sys_get_temp_dir(), 'unrealdb-rar-diag-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Could not allocate temporary RAR diagnostic file.');
    }
    @unlink($temporary);
    $warning = '';
    try {
        $result = rar_diag_warning(static fn() => $entry->extract('', $temporary), $warning);
        if ($result !== true) {
            throw new RuntimeException(
                'RarEntry::extract() returned failure' . ($warning !== '' ? '; warning=' . $warning : '')
            );
        }
        if (!is_file($temporary) || is_link($temporary)) {
            throw new RuntimeException('RarEntry::extract() did not create a regular file.');
        }
        $size = filesize($temporary);
        if ($size === false) {
            throw new RuntimeException('Extracted RAR member size is unavailable.');
        }
        $handle = fopen($temporary, 'rb');
        $prefix = is_resource($handle) ? fread($handle, 16) : '';
        if (is_resource($handle)) {
            fclose($handle);
        }
        return [
            'bytes' => (int)$size,
            'sha256' => (string)hash_file('sha256', $temporary),
            'prefix' => is_string($prefix) ? $prefix : '',
            'warning' => $warning,
        ];
    } finally {
        @unlink($temporary);
    }
}

$args = array_slice($argv, 1);
if (count($args) !== 1 || str_starts_with((string)$args[0], '--')) {
    rar_diag_usage();
}
$resolved = realpath((string)$args[0]);
if (!is_string($resolved) || !is_file($resolved) || !is_readable($resolved)) {
    fwrite(STDERR, "RAR archive is not a readable regular file.\n");
    exit(1);
}
if (!class_exists(\RarArchive::class)) {
    fwrite(STDERR, "[FAIL] PHP rar extension (RarArchive) is not loaded.\n");
    exit(1);
}

$openWarning = '';
$archive = rar_diag_warning(static fn() => \RarArchive::open($resolved), $openWarning);
if (!$archive instanceof \RarArchive) {
    fwrite(STDERR, '[FAIL] RarArchive::open() failed'
        . ($openWarning !== '' ? ': ' . $openWarning : '') . PHP_EOL);
    exit(1);
}

try {
    $entries = $archive->getEntries();
    if (!is_array($entries)) {
        throw new RuntimeException('RarArchive::getEntries() failed.');
    }

    echo 'Archive: ' . $resolved . PHP_EOL;
    echo 'Size: ' . number_format((int)filesize($resolved)) . ' bytes' . PHP_EOL;
    echo 'SHA256: ' . hash_file('sha256', $resolved) . PHP_EOL;
    echo 'Entries: ' . number_format(count($entries)) . PHP_EOL;
    echo str_repeat('-', 100) . PHP_EOL;

    $ok = 0;
    $failed = 0;
    foreach ($entries as $index => $entry) {
        if (!is_object($entry)) {
            continue;
        }
        $name = (string)rar_diag_method($entry, 'getName', '');
        $directory = (bool)rar_diag_method($entry, 'isDirectory', false);
        $encrypted = (bool)rar_diag_method($entry, 'isEncrypted', false);
        $unpacked = max(0, (int)rar_diag_method($entry, 'getUnpackedSize', 0));
        $packed = max(0, (int)rar_diag_method($entry, 'getPackedSize', 0));
        $method = rar_diag_method($entry, 'getMethod', null);
        $crc = rar_diag_method($entry, 'getCrc', null);

        echo '[' . (int)$index . '] ' . $name . PHP_EOL;
        echo '    directory=' . ($directory ? 'yes' : 'no')
            . ' encrypted=' . ($encrypted ? 'yes' : 'no')
            . ' packed=' . number_format($packed)
            . ' unpacked=' . number_format($unpacked)
            . ($method !== null ? ' method=' . (string)$method : '')
            . ($crc !== null ? ' crc=' . (string)$crc : '')
            . PHP_EOL;

        if ($directory) {
            echo "    SKIP directory\n";
            continue;
        }
        if ($encrypted) {
            echo "    SKIP encrypted\n";
            continue;
        }

        try {
            $result = rar_diag_stream($entry, $unpacked);
            $sizeOk = $unpacked < 1 || (int)$result['bytes'] === $unpacked;
            echo '    STREAM ' . ($sizeOk ? 'OK' : 'SIZE-MISMATCH')
                . ' actual=' . number_format((int)$result['bytes'])
                . ' sha256=' . (string)$result['sha256']
                . ' ' . rar_diag_magic((string)$result['prefix']) . PHP_EOL;
            if ($sizeOk && (int)$result['bytes'] > 0) {
                $ok++;
                continue;
            }
            throw new RuntimeException('Stream output did not match the declared non-empty member.');
        } catch (Throwable $streamError) {
            echo '    STREAM FAIL ' . rar_diag_error($streamError) . PHP_EOL;
        }

        try {
            $fallback = rar_diag_extract($entry);
            $sizeOk = $unpacked < 1 || (int)$fallback['bytes'] === $unpacked;
            echo '    EXTRACT ' . ($sizeOk ? 'OK' : 'SIZE-MISMATCH')
                . ' actual=' . number_format((int)$fallback['bytes'])
                . ' sha256=' . (string)$fallback['sha256']
                . ' ' . rar_diag_magic((string)$fallback['prefix'])
                . ((string)$fallback['warning'] !== '' ? ' warning=' . (string)$fallback['warning'] : '')
                . PHP_EOL;
            if ($sizeOk && (int)$fallback['bytes'] > 0) {
                $ok++;
            } else {
                $failed++;
            }
        } catch (Throwable $extractError) {
            echo '    EXTRACT FAIL ' . rar_diag_error($extractError) . PHP_EOL;
            $failed++;
        }
    }

    echo str_repeat('-', 100) . PHP_EOL;
    echo 'Summary: ' . number_format($ok) . ' readable member(s), '
        . number_format($failed) . ' failed member(s).' . PHP_EOL;
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . rar_diag_error($error) . PHP_EOL);
    exit(1);
} finally {
    $archive->close();
}
