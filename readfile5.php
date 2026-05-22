<?php
function readUnrealPackage($file) {
    $handle = fopen($file, 'rb');
    if (!$handle) {
        die("Unable to open file.");
    }
	
	echo "<PRE>";
    
    // Read file signature (should be 0x9E2A83C1 for Unreal package)
    $signature = unpack('V', fread($handle, 4))[1];
    if ($signature !== 0x9E2A83C1) {
        die("This is not a valid Unreal package.");
    }

    // Read file version
    $version = unpack('V', fread($handle, 4))[1];
    echo "File version: $version\n";

    // Read package flags
    $flags = unpack('V', fread($handle, 4))[1];
    echo "Package flags: $flags\n";

    // Read name count and offset
    $nameCount = unpack('V', fread($handle, 4))[1];
    echo "Name count: $nameCount\n";
    $nameOffset = unpack('V', fread($handle, 4))[1];
    echo "Name table offset: $nameOffset\n";

    // Read export count and offset
    $exportCount = unpack('V', fread($handle, 4))[1];
    echo "Export count: $exportCount\n";
    $exportOffset = unpack('V', fread($handle, 4))[1];
    echo "Export table offset: $exportOffset\n";

    // Read import count and offset
    $importCount = unpack('V', fread($handle, 4))[1];
    echo "Import count: $importCount\n";
    $importOffset = unpack('V', fread($handle, 4))[1];
    echo "Import table offset: $importOffset\n";
    
    fclose($handle);
}

// Example usage
readUnrealPackage('test.utx');
?>
