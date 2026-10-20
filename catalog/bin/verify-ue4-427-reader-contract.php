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

$stream = fopen('php://temp', 'w+b');
fwrite($stream, pack('V2', 0xFFFFFFFF, 0xFFFFFFFF) . pack('V2', 0x00000000, 0x80000000));
rewind($stream);
$reader = new UE4BinaryReader($stream, 16);
$check(
    'signed_int64_extremes_are_exact',
    $reader->i64() === -1 && $reader->i64() === PHP_INT_MIN,
    'Signed int64 decoding must not use floating-point arithmetic or lose precision at INT64_MIN.'
);
fclose($stream);

$source = file_get_contents(dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php');
$check(
    'import_package_name_obeys_filter_editor_only',
    is_string($source)
        && str_contains($source, '$version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly')
        && str_contains($source, '$version = (int)$this->header[\'version\'];'),
    'UE4.27.2 serializes FObjectImport::PackageName only at VER_UE4_NON_OUTER_PACKAGE_IMPORT+ when editor-only data is not filtered.'
);
// The CURRENT Epic 4.27 writers emit FName references; the reader also
// retains an earlier-format branch. This check only guards that behavior
// and does not certify the historical format from 4.27 writer source.
$check(
    'soft_package_references_preserve_explicit_format_branches',
    is_string($source)
        && str_contains($source, '$legacyStrings =')
        && str_contains($source, '? $r->fstring()')
        && str_contains($source, ': $this->fnameText($this->readFName($r));'),
    'Current Epic SavePackage/SavePackage2 use FName; the historical FString branch requires version-specific Epic evidence.'
);

// Compare the actual reader constant values with a JSON reference generated
// exclusively from pinned Epic ObjectVersion.h (never PHP-shaped expectations).
$epicEnumFile = dirname(__DIR__, 2) . '/specs/epic-source/ue4-4.27.2/package-loading/epic-version-enum.json';
$epicEnum = is_file($epicEnumFile) ? json_decode((string)file_get_contents($epicEnumFile), true) : null;
$versionMap = [
    'VER_SERIALIZE_TEXT_IN_PACKAGES' => 'VER_UE4_SERIALIZE_TEXT_IN_PACKAGES',
    'VER_ADD_STRING_ASSET_REFERENCES_MAP' => 'VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP',
    'VER_ADDED_SEARCHABLE_NAMES' => 'VER_UE4_ADDED_SEARCHABLE_NAMES',
    'VER_ENGINE_VERSION_OBJECT' => 'VER_UE4_ENGINE_VERSION_OBJECT',
    'VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION' => 'VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION',
    'VER_WORLD_LEVEL_INFO' => 'VER_UE4_WORLD_LEVEL_INFO',
    'VER_WORLD_LEVEL_INFO_UPDATED' => 'VER_UE4_WORLD_LEVEL_INFO_UPDATED',
    'VER_WORLD_LEVEL_INFO_LOD_LIST' => 'VER_UE4_WORLD_LEVEL_INFO_LOD_LIST',
    'VER_WORLD_LEVEL_INFO_ZORDER' => 'VER_UE4_WORLD_LEVEL_INFO_ZORDER',
    'VER_WORLD_LAYER_ENABLE_DISTANCE_STREAMING' => 'VER_UE4_WORLD_LAYER_ENABLE_DISTANCE_STREAMING',
    'VER_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE' => 'VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE',
    'VER_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS' => 'VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS',
    'VER_ASSETREGISTRY_DEPENDENCYFLAGS' => 'VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS',
    'VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS' => 'VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS',
    'VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS' => 'VER_UE4_TemplateIndex_IN_COOKED_EXPORTS',
    'VER_LOAD_FOR_EDITOR_GAME' => 'VER_UE4_LOAD_FOR_EDITOR_GAME',
    'VER_COOKED_ASSETS_IN_EDITOR_SUPPORT' => 'VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT',
    'VER_64BIT_EXPORTMAP_SERIALSIZES' => 'VER_UE4_64BIT_EXPORTMAP_SERIALSIZES',
    'VER_NAME_HASHES_SERIALIZED' => 'VER_UE4_NAME_HASHES_SERIALIZED',
    'VER_ADDED_SOFT_OBJECT_PATH' => 'VER_UE4_ADDED_SOFT_OBJECT_PATH',
    'VER_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID' => 'VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID',
    'VER_ADDED_PACKAGE_OWNER' => 'VER_UE4_ADDED_PACKAGE_OWNER',
    'VER_NON_OUTER_PACKAGE_IMPORT' => 'VER_UE4_NON_OUTER_PACKAGE_IMPORT',
    'VER_OLDEST_LOADABLE_PACKAGE' => 'VER_UE4_OLDEST_LOADABLE_PACKAGE',
    'DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION' => 'VER_UE4_AUTOMATIC_VERSION',
];
$reflection = new ReflectionClass(UnrealPackageReader4::class);
$expectedReaderConstants = [];
foreach ($reflection->getReflectionConstants() as $constant) {
    if (preg_match('/^(?:VER_[A-Z_0-9]+|DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION)$/', $constant->getName()) === 1) {
        $expectedReaderConstants[] = $constant->getName();
    }
}
sort($expectedReaderConstants);
$mappedConstants = array_keys($versionMap);
sort($mappedConstants);
$sourceErrors = [];
if (!is_array($epicEnum)
    || ($epicEnum['epic_sha256'] ?? '') !== '378C49E3AF3542C87C95D7259B81FCB77A98077D438CCCFA227B2F90870A000A'
    || $mappedConstants !== $expectedReaderConstants) {
    $sourceErrors[] = 'Pinned Epic enum or complete UE4 reader constant inventory unavailable/mismatched.';
} else {
    foreach ($versionMap as $phpConstant => $epicName) {
        $value = $epicEnum['members'][$epicName]['value'] ?? null;
        $reflectionConstant = $reflection->getReflectionConstant($phpConstant);
        if (!is_int($value)
            || $reflectionConstant === false
            || $reflectionConstant->getValue() !== $value) {
            $sourceErrors[] = $phpConstant . ' does not match Epic ' . $epicName . '.';
        }
    }
}
$check(
    'ue4_427_version_gates_match_source_exactly',
    $sourceErrors === [],
    $sourceErrors === []
        ? count($versionMap) . ' PHP constant values equal pinned Epic C++ enum values, not PHP string assertions.'
        : implode(' ', $sourceErrors)
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

$check(
    'binary_reads_use_overflow_safe_remaining_check',
    is_string($source)
        && str_contains($source, '$count > $this->len - $this->pos')
        && !str_contains($source, '$this->pos + $count > $this->len'),
    'Binary read bounds are checked without adding attacker-controlled lengths to the current offset.'
);
$check(
    'package_summary_enforces_ue4_minimum_size',
    is_string($source)
        && str_contains($source, '$this->fileSize < 32'),
    'UE4.27.2 FPackageFileSummary rejects packages smaller than 32 bytes before summary parsing.'
);
$check(
    'table_minimum_sizes_follow_version_gates',
    is_string($source)
        && str_contains($source, '$nameMinimum = $version >= self::VER_NAME_HASHES_SERIALIZED ? 8 : 4;')
        && str_contains($source, '$importMinimum = 28 +')
        && str_contains($source, '$exportMinimum = 64;')
        && str_contains($source, 'VER_64BIT_EXPORTMAP_SERIALSIZES) $exportMinimum += 8;')
        && str_contains($source, "'stringAssetReferencesOffset', 8]"),
    'Minimum serialized table widths follow the UE4 version gates instead of imposing one fixed export size on every loadable UE4 version.'
);

$check(
    'serialized_version_identity_is_retained',
    is_string($source)
        && str_contains($source, "'serializedUE4Version'")
        && str_contains($source, "'serializedLicenseeVersion'")
        && str_contains($source, "'serializedSignature'")
        && str_contains($source, "'byteSwapping'"),
    'UE4 source-shaped migration retains serialized tag/version identity separately from effective unversioned parser state.'
);

$ok = !in_array(false, array_column($checks, 'ok'), true);
echo json_encode(['ok' => $ok, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);
