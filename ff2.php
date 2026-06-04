<?php

$file = 'test.utx';    // Example filename (replace with your actual .utx file)
//$file = 'oldtest.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}
//$file = 'oldtest2.utx'; // guid {1E90ACA4-ED66-11D1-9727-444553540000}

$fp = fopen($file, 'rb');
if (!$fp) {
    die("Failed to open file: $file");
}

echo "<pre>\n";

// Read signature
$sig = readDWORD($fp);
if ($sig !== 0x9E2A83C1) {
    die('Not an Unreal File.');
} else {
    echo 'Unreal File found. (0x' . strtoupper(dechex($sig)) . ')' . PHP_EOL;
}

echo "\n";

// Read basic header information
$version      = readWORD($fp);
$licensemode  = readWORD($fp);
$pflags       = readDWORD($fp);
$namecount    = readDWORD($fp);
$nameoffset   = readDWORD($fp);
$exportcount  = readDWORD($fp);
$exportoffset = readDWORD($fp);
$importcount  = readDWORD($fp);
$importoffset = readDWORD($fp);

echo 'Version:          ' . $version      . PHP_EOL;
echo 'License mode:     ' . $licensemode  . PHP_EOL;
echo 'Package flags:    ' . $pflags       . ' - ' . GetPackageFlags($pflags) . PHP_EOL;
echo 'Name count:       ' . $namecount    . PHP_EOL;
echo 'Name offset:      ' . $nameoffset   . PHP_EOL;
echo 'Export count:     ' . $exportcount  . PHP_EOL;
echo 'Export offset:    ' . $exportoffset . PHP_EOL;
echo 'Import count:     ' . $importcount  . PHP_EOL;
echo 'Import offset:    ' . $importoffset . PHP_EOL;
echo "\n";

// Handle import table
echo "<b>******* Import Table ($importcount:$importoffset)</b>\n";
fseek($fp, $importoffset);
for ($nc = 0; $nc < $importcount; $nc++) {
    $classPackage = readINDEX($fp);
    $className    = readINDEX($fp);
    $package      = readINDEX($fp);
    $name         = readINDEX($fp);

    echo "Import $nc:\n";
    echo "  Class Package: $classPackage\n";
    echo "  Class Name:    $className\n";
    echo "  Package:       $package\n";
    echo "  Name:          $name\n";
    echo "\n";
}

// Close file handle
fclose($fp);

echo "</pre>\n";

// Function to read INDEX type (used for import table)
function readINDEX($fp) {
    $bytes  = fread($fp, 4);
    $parsed = unpack('V', $bytes);

    return $parsed[1];
}

// Function to read DWORD type
function readDWORD($fp) {
    $bytes  = fread($fp, 4);
    $parsed = unpack('V', $bytes);

    return $parsed[1];
}

// Function to read WORD type
function readWORD($fp) {
    $bytes  = fread($fp, 2);
    $parsed = unpack('v', $bytes);

    return $parsed[1];
}

// Function to get package flags
function GetPackageFlags($val) {
    $flags = '';
    if ($val & 0x0001) $flags .= "PKG_AllowDownload, ";
    if ($val & 0x0002) $flags .= "PKG_ClientOptional, ";
    if ($val & 0x0004) $flags .= "PKG_ServerSideOnly, ";
    if ($val & 0x0008) $flags .= "PKG_BrokenLinks, ";
    if ($val & 0x0010) $flags .= "PKG_Unsecure, ";
    if ($val & 0x8000) $flags .= "PKG_Need, ";

    return rtrim($flags, ', ');
}
?>
