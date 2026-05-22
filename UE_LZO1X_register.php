<?php
/**
 * Registers LZO1X decoder support for UE3 package chunks.
 *
 * TUnrealPackage.php defines UE_Decompress and UE_LZO1X. This file is included
 * after TUnrealPackage.php and registers codec id 2.
 *
 * Priority:
 *   1) Native/FFI lzo1x_decompress() from lzo_runtime.php, if available.
 *   2) PHP extension/class LZO, if available.
 *   3) Bundled pure-PHP UE_LZO1X fallback from TUnrealPackage.php.
 */

if (!class_exists('UE_Decompress')) {
    throw new RuntimeException('UE_Decompress must be loaded before UE_LZO1X_register.php');
}

$runtime = __DIR__ . '/lzo_runtime.php';
if (is_file($runtime)) {
    require_once $runtime;
}

UE_Decompress::register(2, function (string $data, int $expected, array $ctx): string {
    if (function_exists('lzo1x_decompress')) {
        $out = lzo1x_decompress($data, $expected);
        if (!is_string($out)) {
            throw new RuntimeException('LZO: lzo1x_decompress() failed');
        }
        if ($expected > 0 && strlen($out) !== $expected) {
            throw new RuntimeException('LZO: decoded size mismatch, expected ' . $expected . ', got ' . strlen($out));
        }
        return $out;
    }

    if (class_exists('\\LZO') && method_exists('\\LZO', 'decompress')) {
        $out = \LZO::decompress($data, $expected);
        if (!is_string($out)) {
            throw new RuntimeException('LZO: \\LZO::decompress() failed');
        }
        if ($expected > 0 && strlen($out) !== $expected) {
            throw new RuntimeException('LZO: \\LZO decoded size mismatch, expected ' . $expected . ', got ' . strlen($out));
        }
        return $out;
    }

    if (class_exists('UE_LZO1X') && method_exists('UE_LZO1X', 'decompress')) {
        $out = UE_LZO1X::decompress($data, $expected);
        if (!is_string($out)) {
            throw new RuntimeException('LZO: UE_LZO1X::decompress() failed');
        }
        if ($expected > 0 && strlen($out) !== $expected) {
            throw new RuntimeException('LZO: UE_LZO1X decoded size mismatch, expected ' . $expected . ', got ' . strlen($out));
        }
        return $out;
    }

    if (class_exists('OldLZO') && method_exists('OldLZO', 'decode')) {
        $out = OldLZO::decode($data, $expected);
        if (!is_string($out)) {
            throw new RuntimeException('LZO: OldLZO::decode() failed');
        }
        if ($expected > 0 && strlen($out) !== $expected) {
            throw new RuntimeException('LZO: OldLZO decoded size mismatch, expected ' . $expected . ', got ' . strlen($out));
        }
        return $out;
    }

    throw new RuntimeException('LZO codec required (2), but no LZO decoder is available.');
});
