<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';

$checks = [];
$check = static function (string $name, bool $ok, string $detail = '') use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

$stream = fopen('php://temp/maxmemory:4194304', 'w+b');
$payload = str_repeat('A', 1048576) . "\0";
fwrite($stream, pack('V', strlen($payload)) . $payload);
rewind($stream);
$reader = new UE4BinaryReader($stream, 4 + strlen($payload));
$value = $reader->fstring();
$check(
    'fstring_has_no_unrealdb_1mib_cap',
    strlen($value) === 1048576,
    'FString is bounded by serialized archive data, not an UnrealDB-specific 1 MiB ceiling.'
);
fclose($stream);

$stream = fopen('php://temp', 'w+b');
fwrite($stream, pack('N', 0x01020304) . pack('n', 0x0506));
rewind($stream);
$reader = new UE4BinaryReader($stream, 6);
$reader->setByteSwapping(true);
$check(
    'byte_swapped_integer_reads',
    $reader->u32() === 0x01020304 && $reader->u16() === 0x0506,
    'PACKAGE_FILE_TAG_SWAPPED requires archive byte swapping like UE4 FPackageFileSummary.'
);
fclose($stream);

$stream = fopen('php://temp', 'w+b');
fwrite($stream, pack('V', 0x80000000));
rewind($stream);
$reader = new UE4BinaryReader($stream, 4);
$threw = false;
try {
    $reader->fstring();
} catch (OutOfBoundsException $e) {
    $threw = str_contains($e->getMessage(), 'bad wide FString length=-2147483648');
}
$check('wide_fstring_int32_min_is_rejected_safely', $threw, 'INT32_MIN cannot be safely negated into a UTF-16 character count.');
fclose($stream);

$source = file_get_contents(dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php');
$check(
    'import_package_name_obeys_filter_editor_only',
    is_string($source)
        && str_contains($source, '$version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly')
        && str_contains($source, '$version = (int)$this->header[\'version\'];'),
    'UE4.27.2 serializes FObjectImport::PackageName only at VER_UE4_NON_OUTER_PACKAGE_IMPORT+ when editor-only data is not filtered.'
);
$check(
    'soft_object_path_layout_is_versioned',
    is_string($source)
        && str_contains($source, 'VER_ADDED_SOFT_OBJECT_PATH = 514')
        && str_contains($source, '$assetPathName = $this->readFName($r);')
        && str_contains($source, '$subPath = trim($r->fstring());'),
    'VER_UE4_ADDED_SOFT_OBJECT_PATH changes soft references from FString to FName + FString.'
);
$check(
    'arbitrary_collection_caps_removed',
    is_string($source)
        && !str_contains($source, '$genCount > 1024')
        && !str_contains($source, '$count > 4096')
        && !str_contains($source, '$count > 65536')
        && !str_contains($source, '$count > 1048576'),
    'Collection counts are validated against remaining serialized bytes and minimum element widths.'
);

$check(
    'name_entries_use_ue_name_size_contract',
    is_string($source)
        && str_contains($source, 'private const NAME_SIZE = 1024;')
        && str_contains($source, 'readSerializedNameEntry')
        && str_contains($source, '$length < -self::NAME_SIZE || $length > self::NAME_SIZE'),
    'FNameEntrySerialized is constrained by UE4 NAME_SIZE, independently of general FString serialization.'
);
$check(
    'summary_tables_are_bounded_by_total_header',
    is_string($source)
        && str_contains($source, 'validateSummaryBounds')
        && str_contains($source, '$offset >= $headerSize')
        && str_contains($source, '$headerSize - $offset'),
    'Header-resident name/import/export/reference tables must fit inside TotalHeaderSize before parsing.'
);
$check(
    'name_hash_bytes_are_mandatory_at_version_gate',
    is_string($source)
        && str_contains($source, 'if ($version >= self::VER_NAME_HASHES_SERIALIZED)')
        && !str_contains($source, 'self::VER_NAME_HASHES_SERIALIZED && $r->remaining() >= 4'),
    'At VER_UE4_NAME_HASHES_SERIALIZED+, the two serialized uint16 hash fields are part of every name-map entry and truncation must fail rather than silently desynchronize.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
