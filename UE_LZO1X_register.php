<?php
/**
 * Registers the project-local pure PHP LZO1X decoder for UE3 package chunks.
 *
 * TUnrealPackage.php already defines UE_Decompress and UE_LZO1X. This file is
 * included after TUnrealPackage.php so it can override/register codec id 2.
 */

if (!class_exists('UE_Decompress')) {
    throw new RuntimeException('UE_Decompress must be loaded before UE_LZO1X_register.php');
}

UE_Decompress::register(2, function (string $data, int $expected, array $ctx): string {
    // Prefer native PHP extension if the server has one installed.
    if (function_exists('lzo1x_decompress')) {
        $out = lzo1x_decompress($data, $expected);
        if (!is_string($out)) {
            throw new RuntimeException('LZO: native lzo1x_decompress() failed');
        }
        if ($expected > 0 && strlen($out) !== $expected) {
            throw new RuntimeException('LZO: native decoded size mismatch, expected ' . $expected . ', got ' . strlen($out));
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

    // Use the bundled pure-PHP decoder already present in TUnrealPackage.php.
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

    // Optional old external class, if it is later brought over.
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
