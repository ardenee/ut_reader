<?php
require_once __DIR__ . '/TUnrealPackage.php';
echo "<pre>";
$path = "testde.ut3";

$pkg     = TPackageReader::open($path); // returns TUE1/TUE2/TUE3/TUE4

$pkg->annotateTablesWithText();
//$pkg->annotateTablesWithText();


$hdr     = $pkg->getHeader();
$names   = $pkg->getNames();
$chunks  = $pkg->chunkMeta;
$imports = $pkg->getImports();
$exports = $pkg->getExports();
// consistent helpers:
//echo $pkg->nameText($exports[0]['objectName']);


//echo "objectFlags - ".sprintf("0x%08X", $exports[0]['objectFlags']);
//echo "<br>";
//echo "exportFlags - ".sprintf("0x%08X", $exports[0]['exportFlags']);
//echo "<br>";

//$pkg->debugExport0U32s();
//print_r($hdr);
//print_r($chunks);
//print_r($names);
//print_r($imports);
print_r($exports);
echo "</pre>";
?>