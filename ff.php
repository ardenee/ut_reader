<?php

$file_path = 'test.utx';  // Replace with your actual file path
$folderName = "";

// Open the file for binary reading
$handle = fopen($file_path, 'rb');

if ($handle === false) {
    die("Failed to open file: $file_path");
}

// Define constants based on Unreal Engine 3 specifications
define('UE3_SIGNATURE', 0x9E2A83C1);

// Read the signature (DWORD)
$signature = unpack('V', fread($handle, 4))[1];

// Verify the signature to ensure it's a valid Unreal file
if ($signature !== UE3_SIGNATURE) {
    fclose($handle);
    die("Not a valid Unreal Engine 3 file.");
}

// Read the version (WORD/WORD)
$version = unpack('v', fread($handle, 2))[1];
$licenseeVersion = unpack('v', fread($handle, 2))[1];

// Read HeaderSize (Int32)
$headerSize = unpack('V', fread($handle, 4))[1];

// Read FolderName (String) if version >= 269
if ($version >= 269) {
    $stringLength = unpack('V', fread($handle, 4))[1];
    $folderName = fread($handle, $stringLength);
}

// Read PackageFlags (DWORD)
$packageFlags = unpack('V', fread($handle, 4))[1];

// Read NameCount, NameOffset, ExportCount, ExportOffset, ImportCount, ImportOffset (DWORD)
$nameCount = unpack('V', fread($handle, 4))[1];
$nameOffset = unpack('V', fread($handle, 4))[1];
$exportCount = unpack('V', fread($handle, 4))[1];
$exportOffset = unpack('V', fread($handle, 4))[1];
$importCount = unpack('V', fread($handle, 4))[1];
$importOffset = unpack('V', fread($handle, 4))[1];

// Depending on version >= 415, read DependsOffset (DWORD)
if ($version >= 415) {
    $dependsOffset = unpack('V', fread($handle, 4))[1];
}

// Close the file handle when done
fclose($handle);

// Display or use the read information as needed
echo "Signature: 0x" . dechex($signature) . "<br>";
echo "Version: $version<br>";
echo "Licensee Version: $licenseeVersion<br>";
echo "Header Size: $headerSize bytes<br>";
echo "Folder Name: $folderName<br>";  // Display or process the folder name if available
echo "Package Flags: $packageFlags<br>";
echo "Name Count: $nameCount<br>";
echo "Name Offset: $nameOffset<br>";
echo "Export Count: $exportCount<br>";
echo "Export Offset: $exportOffset<br>";
echo "Import Count: $importCount<br>";
echo "Import Offset: $importOffset<br>";

// Additional fields based on Unreal Engine 3 specifications can be parsed similarly

?>
