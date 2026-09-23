#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$legacyPath = $root . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$ue3Path = $root . '/parsers/EpicUE3PackageReader.php';
$legacy = (string)@file_get_contents($legacyPath);
$ue3 = (string)@file_get_contents($ue3Path);

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check'=>$name,'ok'=>$ok,'detail'=>$detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'legacy_pre68_uses_heritage_table',
    str_contains($legacy, "\$reader->seek(\$heritageOffset)")
        && str_contains($legacy, "for (\$index = 0; \$index < \$heritageCount; \$index++)")
        && str_contains($legacy, "\$reader->seek(\$saved)")
        && !str_contains($legacy, 'spaceBeforeNames'),
    'UE1/UE2 <68 must follow Epic HeritageOffset seek/read/restore serialization.'
);
$record(
    'legacy_ar_index_stays_compact',
    str_contains($legacy, 'return $this->compactIndex();'),
    'UE1/UE2 AR_INDEX fields must remain FCompactIndex for the full legacy package family.'
);
$record(
    'legacy_has_no_project_string_or_table_ceiling',
    !preg_match('/(?:>|>=)\\s*65536\\b/', $legacy)
        && !preg_match('/(?:>|>=)\\s*32768\\b/', $legacy)
        && !preg_match('/(?:>|>=)\\s*2000000\\b/', $legacy)
        && !preg_match('/(?:>|>=)\\s*100000\\b/', $legacy),
    'Validity must be derived from serialized ranges/file bounds rather than project-only count ceilings.'
);
$record(
    'ue3_summary_has_texture_preallocation',
    str_contains($ue3, 'VER_TEXTURE_PREALLOCATION=767')
        && str_contains($ue3, "TextureAllocations.TextureTypes.Count")
        && str_contains($ue3, "TexCreateFlags")
        && str_contains($ue3, "ExportIndices.Count"),
    'UE3 >=767 must deserialize FTextureAllocations after AdditionalPackagesToCook.'
);
$record(
    'ue3_compression_does_not_zero_fill_mapping_holes',
    !str_contains($ue3, 'str_repeat("\\0",$uOff-$cursor)')
        && !str_contains($ue3, 'str_repeat("\\0",$logicalSize-$cursor)')
        && str_contains($ue3, 'ue3.compression_map_gap'),
    'SetCompressionMap semantics must not be approximated by manufacturing zero-filled logical ranges.'
);
$record(
    'ue3_object_tables_follow_epic_layout',
    str_contains($ue3, 'ArchetypeIndex')
        && str_contains($ue3, 'GenerationNetObjectCount.Count')
        && str_contains($ue3, 'PackageGuid')
        && str_contains($ue3, 'ComponentMap.Count'),
    'UE3 export parsing must retain Epic field order and the pre-543 component-map gate.'
);

foreach ([$legacyPath,$ue3Path] as $file) {
    $pipes=[];
    $process=@proc_open([PHP_BINARY,'-l',$file],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $ok=false; $detail='';
    if (is_resource($process)) {
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $ok=proc_close($process)===0;
        $detail=trim((string)$err.' '.(string)$out);
    }
    $record('php_syntax_' . basename($file),$ok,$detail);
}

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures===[] ? 0 : 1);
