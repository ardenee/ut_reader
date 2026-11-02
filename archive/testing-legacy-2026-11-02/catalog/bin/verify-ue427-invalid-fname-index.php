<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';
$root = 'H:/Epic Games/UE_4.27/Engine/Content';
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$sample = null; $header = [];
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'uasset') continue;
    $r = new UnrealPackageReader4($file->getPathname());
    $h = $r->getHeader();
    if ($r->validatePackage() === [] && ($h['importCount'] ?? 0) > 0 && ($h['nameCount'] ?? 0) > 0) {
        $sample = $file->getPathname(); $header = $h; break;
    }
}
if ($sample === null) throw new RuntimeException('No real asset with an import map available');
$raw = file_get_contents($sample);
$offset = (int)$header['importOffset'];
if ($offset <= 0 || $offset + 4 > strlen($raw)) throw new RuntimeException('Invalid real import offset');
$path = tempnam(sys_get_temp_dir(), 'ue427-name-idx-');
$results = [];
try {
    foreach ([['valid', null, true], ['negative_one', 0xFFFFFFFF, false], ['out_of_range', 0x7FFFFFFF, false]] as [$label,$value,$shouldPass]) {
        $modified = $value === null ? $raw : substr_replace($raw, pack('V', $value), $offset, 4);
        file_put_contents($path,$modified);
        $reader = new UnrealPackageReader4($path);
        $issues = $reader->validatePackage();
        $pass = $issues === [];
        $containsIndex = $pass || (bool)preg_grep('/UE4 FName index .* outside NameMap count/', $issues);
        $results[$label] = ['ok' => $pass === $shouldPass && $containsIndex, 'accepted'=>$pass, 'issues'=>array_slice($issues,0,2)];
    }
} finally {
    @unlink($path);
}
$ok = !in_array(false, array_column($results,'ok'),true);
echo json_encode(['ok'=>$ok,'genuine_source_asset'=>$sample,'name_count'=>$header['nameCount'],
    'import_count'=>$header['importCount'],'serialized_import_offset'=>$offset,'tests'=>$results],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($ok ? 0 : 2);
