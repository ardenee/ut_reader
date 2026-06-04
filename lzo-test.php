<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/TUnrealPackage.php';
require __DIR__ . '/UE_LZO1X_register.php';

echo 'PHP version: ' . PHP_VERSION . "<br>";
echo 'FFI extension: ' . (extension_loaded('FFI') ? 'loaded' : 'not loaded') . "<br>";
echo 'ffi.enable: ' . ini_get('ffi.enable') . "<br>";
echo 'native lzo1x_decompress: ' . (function_exists('lzo1x_decompress') ? 'available' : 'missing') . "<br>";
echo 'bundled UE_LZO1X: ' . (class_exists('UE_LZO1X') && method_exists('UE_LZO1X', 'decompress') ? 'available' : 'missing') . "<br>";
echo 'codec registry loaded: ' . (class_exists('UE_Decompress') ? 'yes' : 'no') . "<br>";
