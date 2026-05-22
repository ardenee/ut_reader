<?php
/**
 * Optional native LZO runtime bridge using PHP FFI.
 *
 * This file defines lzo1x_decompress() when PHP FFI is available and a usable
 * LZO shared library can be found. It is optional: UE_LZO1X_register.php will
 * fall back to the bundled pure PHP UE_LZO1X decoder if this cannot load.
 *
 * Configure with one of:
 *   putenv('LZO_DLL=/full/path/to/liblzo2.so');
 *   define('LZO_DLL', '/full/path/to/liblzo2.so');
 *   php.ini auto_prepend_file=/path/to/lzo_runtime.php
 */

if (function_exists('lzo1x_decompress')) {
    return;
}

if (!extension_loaded('FFI') || !class_exists('FFI')) {
    return;
}

function ut_reader_lzo_candidates(): array
{
    $candidates = [];

    if (defined('LZO_DLL') && is_string(LZO_DLL) && LZO_DLL !== '') {
        $candidates[] = LZO_DLL;
    }

    $env = getenv('LZO_DLL');
    if (is_string($env) && $env !== '') {
        $candidates[] = $env;
    }

    $candidates[] = __DIR__ . '/liblzo2.so';
    $candidates[] = __DIR__ . '/liblzo2.so.2';
    $candidates[] = __DIR__ . '/lzo2.dll';
    $candidates[] = __DIR__ . '/liblzo2-2.dll';

    if (DIRECTORY_SEPARATOR === '\\') {
        $candidates[] = 'D:/php8/ext/liblzo2-2.dll';
        $candidates[] = 'D:/php8/ext/lzo2.dll';
        $candidates[] = 'D:/php8/ext/lzo2.dll';
    } else {
        $candidates[] = '/usr/lib/liblzo2.so';
        $candidates[] = '/usr/lib/liblzo2.so.2';
        $candidates[] = '/usr/lib/x86_64-linux-gnu/liblzo2.so';
        $candidates[] = '/usr/lib/x86_64-linux-gnu/liblzo2.so.2';
        $candidates[] = '/usr/local/lib/liblzo2.so';
        $candidates[] = '/usr/local/lib/liblzo2.so.2';
        $candidates[] = '/volume1/web/ut_reader/liblzo2.so';
        $candidates[] = '/volume1/web/ut_reader/liblzo2.so.2';
    }

    return array_values(array_unique($candidates));
}

function ut_reader_lzo_handle()
{
    static $handle = false;

    if ($handle !== false) {
        return $handle;
    }

    $cdef = <<<'CDEF'
int lzo1x_decompress(const unsigned char *src,
                     unsigned long src_len,
                     unsigned char *dst,
                     unsigned long *dst_len,
                     void *wrkmem);
int lzo1x_decompress_safe(const unsigned char *src,
                          unsigned long src_len,
                          unsigned char *dst,
                          unsigned long *dst_len,
                          void *wrkmem);
CDEF;

    foreach (ut_reader_lzo_candidates() as $dll) {
        if (!is_string($dll) || $dll === '' || !is_file($dll)) {
            continue;
        }

        try {
            $handle = FFI::cdef($cdef, $dll);
            error_log('[LZO] FFI loaded: ' . $dll);
            return $handle;
        } catch (Throwable $e) {
            error_log('[LZO] FFI load failed for ' . $dll . ': ' . $e->getMessage());
        }
    }

    $handle = null;
    return null;
}

if (!function_exists('lzo1x_decompress')) {
    function lzo1x_decompress(string $bytes, int $expectedLen): string
    {
        if ($expectedLen <= 0) {
            throw new RuntimeException('expectedLen must be > 0');
        }

        $h = ut_reader_lzo_handle();
        if (!$h) {
            throw new RuntimeException('No usable LZO shared library found for PHP FFI. Set LZO_DLL or place liblzo2 beside the repo.');
        }

        $srcLen = strlen($bytes);
        $src = FFI::new("unsigned char[$srcLen]", false);
        FFI::memcpy($src, $bytes, $srcLen);

        $dst = FFI::new("unsigned char[$expectedLen]", false);
        $pDstLen = FFI::new('unsigned long[1]');
        $pDstLen[0] = $expectedLen;

        try {
            $rc = $h->lzo1x_decompress_safe($src, $srcLen, $dst, $pDstLen, null);
        } catch (Throwable $e) {
            $rc = $h->lzo1x_decompress($src, $srcLen, $dst, $pDstLen, null);
        }

        if ($rc !== 0) {
            throw new RuntimeException('LZO decompress failed rc=' . $rc);
        }

        $outLen = (int)$pDstLen[0];
        if ($outLen < 0 || $outLen > $expectedLen) {
            throw new RuntimeException('Unexpected decompressed size ' . $outLen . ' cap ' . $expectedLen);
        }

        return FFI::string($dst, $outLen);
    }
}
