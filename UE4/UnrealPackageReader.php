<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the standalone `UE4` Unreal package reader and its supporting binary/package structures.
 * Why: It decodes `UE4` package formats for parser development and for catalog reader bridges that explicitly load
 *      it.
 * Role: Engine-specific parser/reference implementation; not itself a catalog UI page.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
declare(strict_types=1);

final class UE4BinaryReader
{
    /** @var resource */
    private $handle;
    private int $len;
    private int $pos = 0;
    private bool $ownsHandle = false;
    private bool $byteSwapping = false;

    /** @param resource $source */
    public function __construct($source, ?int $length = null, bool $ownsHandle = false)
    {
        if (!is_resource($source)) throw new InvalidArgumentException('UE4BinaryReader requires an open seekable stream.');
        $meta = stream_get_meta_data($source);
        if (empty($meta['seekable'])) throw new InvalidArgumentException('UE4BinaryReader requires a seekable stream.');
        $this->handle = $source; $this->ownsHandle = $ownsHandle;
        $stat = $length === null ? fstat($source) : null;
        $this->len = $length ?? (is_array($stat) ? (int)($stat['size'] ?? -1) : -1);
        if ($this->len < 0) throw new RuntimeException('Could not determine UE4 package stream size.');
    }
    public static function open(string $path): self
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) throw new RuntimeException("Failed to open UE4 package: $path");
        $stat = fstat($handle); $length = is_array($stat) ? (int)($stat['size'] ?? -1) : -1;
        if ($length < 0) { fclose($handle); throw new RuntimeException("Failed to determine UE4 package size: $path"); }
        return new self($handle, $length, true);
    }
    public function __destruct() { if ($this->ownsHandle && is_resource($this->handle)) fclose($this->handle); }

    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { if ($pos < 0 || $pos > $this->len) throw new OutOfBoundsException("seek overrun pos=$pos len={$this->len}"); $this->pos = $pos; }
    public function remaining(): int { return $this->len - $this->pos; }
    public function size(): int { return $this->len; }
    public function setByteSwapping(bool $enabled): void { $this->byteSwapping = $enabled; }
    public function isByteSwapping(): bool { return $this->byteSwapping; }

    public function bytes(int $count): string
    {
        if ($count < 0 || $count > $this->len - $this->pos) {
            throw new OutOfBoundsException("read overrun need=$count pos={$this->pos} len={$this->len}");
        }
        if ($count === 0) return '';
        if (fseek($this->handle, $this->pos, SEEK_SET) !== 0) throw new RuntimeException("seek failed pos={$this->pos}");
        $out = ''; $remaining = $count;
        while ($remaining > 0) { $chunk = fread($this->handle, min($remaining, 1024 * 1024)); if ($chunk === false || $chunk === '') throw new RuntimeException("short read need=$count got=" . strlen($out) . " pos={$this->pos}"); $out .= $chunk; $remaining -= strlen($chunk); }
        $this->pos += $count; return $out;
    }

    public function u8(): int { return ord($this->bytes(1)); }
    public function u16(): int { return unpack($this->byteSwapping ? 'n' : 'v', $this->bytes(2))[1]; }
    public function u32(): int { return (int)unpack($this->byteSwapping ? 'N' : 'V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }
    public function f32(): float { return (float)unpack($this->byteSwapping ? 'G' : 'g', $this->bytes(4))[1]; }

    public function u64(): int
    {
        $raw = $this->bytes(8);
        $p = $this->byteSwapping ? unpack('Nhi/Nlo', $raw) : unpack('Vlo/Vhi', $raw);
        if (($p['hi'] & 0x80000000) !== 0) {
            throw new OverflowException('uint64 value exceeds PHP_INT_MAX.');
        }
        return ($p['hi'] << 32) | $p['lo'];
    }

    public function i64(): int
    {
        $raw = $this->bytes(8);
        $p = $this->byteSwapping ? unpack('Nhi/Nlo', $raw) : unpack('Vlo/Vhi', $raw);
        $signedHi = ($p['hi'] & 0x80000000) !== 0 ? $p['hi'] - 0x100000000 : $p['hi'];
        return ($signedHi << 32) | $p['lo'];
    }

    public function fstring(): string
    {
        $length = $this->i32();
        if ($length === 0) {
            return '';
        }
        if ($length > 0) {
            if ($length > $this->remaining()) {
                throw new OutOfBoundsException("bad FString length=$length pos={$this->pos}");
            }
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            return self::toUtf8($raw);
        }

        if ($length === -2147483648) {
            throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos}");
        }
        $chars = -$length;
        if ($chars > intdiv(PHP_INT_MAX, 2)) {
            throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos}");
        }
        $bytes = $chars * 2;
        if ($bytes > $this->remaining()) {
            throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos}");
        }
        $raw = $this->bytes($bytes);
        if (substr($raw, -2) === "\0\0") {
            $raw = substr($raw, 0, -2);
        }
        $out = @mb_convert_encoding($raw, 'UTF-8', $this->byteSwapping ? 'UTF-16BE' : 'UTF-16LE');
        return $out === false ? '' : $out;
    }

    public function guid(): string
    {
        $a = $this->u32();
        $b = $this->u32();
        $c = $this->u32();
        $d = $this->u32();
        return sprintf('%08X-%08X-%08X-%08X', $a, $b, $c, $d);
    }

    public static function toUtf8(string $raw): string
    {
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
        return $out === false ? $raw : $out;
    }
}

final class UnrealPackageReader4
{
    private string $path;
    private ?UE4BinaryReader $reader = null;
    private int $fileSize = 0;
    private array $header = [];
    private array $names = [];
    private array $imports = [];
    private array $exports = [];
    private array $issues = [];
    private array $rawHeaderFields = [];
    private array $readerOptions = [];
    private array $parserProfile = [];
    private array $stringAssetReferences = [];
    private array $preloadDependencies = [];
    private array $dependsMap = [];
    private array $thumbnailIndex = [];
    private array $assetRegistryObjects = [];
    private int $assetRegistryDependencyDataOffset = -1;
    private array $assetRegistryDependencyBits = [];
    private array $worldTileInfo = [];

    private const PACKAGE_FILE_TAG = 0x9E2A83C1;
    private const PACKAGE_FILE_TAG_SWAPPED = 0xC1832A9E;

    // Version gates matching Epic's EUnrealEngineObjectUE4Version exactly.
    // The enum contains the typoed real member VAR_UE4_ARRAY_PROPERTY_INNER_TAGS;
    // it must be counted when deriving every later numeric value.
    // UT4 clean-master ends at VER_UE4_64BIT_EXPORTMAP_SERIALSIZES (package v511).
    private const VER_SERIALIZE_TEXT_IN_PACKAGES = 459;
    private const VER_ADD_STRING_ASSET_REFERENCES_MAP = 384;
    private const VER_ADDED_SEARCHABLE_NAMES = 510;
    private const VER_ENGINE_VERSION_OBJECT = 336;
    private const VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION = 444;
    private const VER_WORLD_LEVEL_INFO = 224;
    private const VER_WORLD_LEVEL_INFO_UPDATED = 268;
    private const VER_WORLD_LEVEL_INFO_LOD_LIST = 316;
    private const VER_WORLD_LEVEL_INFO_ZORDER = 319;
    private const VER_WORLD_LAYER_ENABLE_DISTANCE_STREAMING = 329;
    private const FORTNITE_MAIN_BRANCH_VERSION_GUID = '601D1886-AC644F84-AA16D3DE-0DEAC7D6';
    private const FORTNITE_WORLD_TILE_3D_OFFSET_VERSION = 1;
    private const VER_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE = 278;
    private const VER_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS = 326;
    // Confirmed as 521 from compiled Epic UE4.27.2 ObjectVersion.h enum.
    private const VER_ASSETREGISTRY_DEPENDENCYFLAGS = 521;
    private const VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS = 507;
    private const VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS = 508;
    private const VER_LOAD_FOR_EDITOR_GAME = 365;
    private const VER_COOKED_ASSETS_IN_EDITOR_SUPPORT = 485;
    private const VER_64BIT_EXPORTMAP_SERIALSIZES = 511;
    private const VER_NAME_HASHES_SERIALIZED = 504;
    private const VER_ADDED_SOFT_OBJECT_PATH = 514;
    private const VER_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID = 516;
    private const VER_ADDED_PACKAGE_OWNER = 518;
    private const VER_NON_OUTER_PACKAGE_IMPORT = 520;
    private const VER_OLDEST_LOADABLE_PACKAGE = 214;
    private const DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION = 522;
    private const NAME_SIZE = 1024;

    public function __construct(string $path, array $options = [])
    {
        $this->path = $path;
        $this->readerOptions = $options ?: (function_exists('catalog_ue4_take_next_reader_options') ? catalog_ue4_take_next_reader_options() : []);
        $profile = $this->readerOptions['parser_profile'] ?? [];
        $this->parserProfile = is_array($profile) ? $profile : [];

        try {
            $this->reader = UE4BinaryReader::open($path);
            $this->fileSize = $this->reader->size();
            $this->parse();
        } catch (Throwable $e) {
            $this->issues[] = get_class($e) . ': ' . $e->getMessage() . ' File: ' . $e->getFile() . ':' . $e->getLine();
            if (!$this->header) {
                $this->header = $this->blankHeader();
                $this->attachParserProfileToHeader();
            }
        }
    }

    private function blankHeader(): array
    {
        return [
            'signature' => 0,
            'serializedSignature' => 0,
            'byteSwapping' => false,
            'legacyFileVersion' => 0,
            'legacyUE3Version' => 0,
            'version' => 0,
            'licenseeVersion' => 0,
            'serializedUE4Version' => 0,
            'serializedLicenseeVersion' => 0,
            'unversioned' => false,
            'customVersions' => [],
            'totalHeaderSize' => 0,
            'folderName' => '',
            'packageFlags' => 0,
            'nameCount' => 0,
            'nameOffset' => 0,
            'gatherableTextDataCount' => 0,
            'gatherableTextDataOffset' => 0,
            'exportCount' => 0,
            'exportOffset' => 0,
            'importCount' => 0,
            'importOffset' => 0,
            'dependsOffset' => 0,
            'stringAssetReferencesCount' => 0,
            'stringAssetReferencesOffset' => 0,
            'searchableNamesOffset' => 0,
            'thumbnailTableOffset' => 0,
            'guid' => '',
            'generations' => [],
            'savedByEngineVersion' => [],
            'compatibleWithEngineVersion' => [],
            'compressionFlags' => 0,
            'compressedChunks' => [],
            'packageSource' => 0,
            'additionalPackagesToCook' => [],
            'assetRegistryDataOffset' => 0,
            'bulkDataStartOffset' => 0,
            'worldTileInfoDataOffset' => 0,
            'chunkIDs' => [],
            'preloadDependencyCount' => 0,
            'preloadDependencyOffset' => 0,
            'uexpPath' => '',
            'hasUexp' => false,
            'parserProfile' => [],
            'parserProfileKey' => 'standard-ue4',
            'parserProfileLabel' => 'Standard UE4 package parser',
            'assumedUnversionedParserVersion' => self::DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION,
        ];
    }

    private function attachParserProfileToHeader(): void
    {
        $key = (string)($this->parserProfile['profile_key'] ?? 'standard-ue4');
        $label = (string)($this->parserProfile['label'] ?? 'Standard UE4 package parser');
        $this->header['parserProfile'] = $this->parserProfile;
        $this->header['parserProfileKey'] = $key !== '' ? $key : 'standard-ue4';
        $this->header['parserProfileLabel'] = $label !== '' ? $label : 'Standard UE4 package parser';
        $this->header['assumedUnversionedParserVersion'] = $this->assumedUnversionedVersion();
    }

    private function assumedUnversionedVersion(): int
    {
        $value = (int)($this->parserProfile['assumed_unversioned_parser_version'] ?? self::DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION);
        return $value > 0 ? $value : self::DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION;
    }

    private function parse(): void
    {
        $r = $this->rootReader();
        if ($this->fileSize < 32) {
            throw new RuntimeException('UE4 package is smaller than the 32-byte minimum package summary size. fileSize=' . $this->fileSize);
        }
        $tag = $r->u32();
        $serializedTag = $tag;
        if ($tag !== self::PACKAGE_FILE_TAG && $tag !== self::PACKAGE_FILE_TAG_SWAPPED) {
            throw new RuntimeException(sprintf('Bad UE4 package tag 0x%08X', $tag));
        }
        if ($tag === self::PACKAGE_FILE_TAG_SWAPPED) {
            // UE4 toggles archive byte swapping after detecting the swapped package tag.
            $r->setByteSwapping(true);
            $tag = self::PACKAGE_FILE_TAG;
        }

        $legacy = $r->i32();
        $this->header = $this->blankHeader();
        $this->attachParserProfileToHeader();
        $this->header['signature'] = $tag;
        $this->header['serializedSignature'] = $serializedTag;
        $this->header['byteSwapping'] = $r->isByteSwapping();
        $this->header['legacyFileVersion'] = $legacy;

        if ($legacy >= 0) {
            throw new RuntimeException('This looks like an older UE package, not a modern UE4 package. LegacyFileVersion=' . $legacy);
        }
        if ($legacy < -7) {
            throw new RuntimeException('Package summary format is newer than UE4.27.2 and is not supported by this reader. LegacyFileVersion=' . $legacy);
        }
        if ($legacy !== -4) {
            $this->header['legacyUE3Version'] = $r->i32();
        }

        $ue4Version = $r->i32();
        $licensee = $r->i32();
        $this->header['serializedUE4Version'] = $ue4Version;
        $this->header['serializedLicenseeVersion'] = $licensee;
        $this->header['version'] = $ue4Version;
        $this->header['licenseeVersion'] = $licensee;

        if ($legacy <= -2) {
            $this->header['customVersions'] = $this->readCustomVersions($r, $legacy);
        }
        if ($ue4Version === 0 && $licensee === 0) {
            // Epic PackageFileSummary.cpp returns early in WITH_EDITOR when
            // GAllowUnversionedContentInEditor == 0 (the stock default).
            // Explicit opt-in models the guarded editor/non-editor branch;
            // normal production readers do not silently accept such assets.
            $isUt4CleanMaster = (string)($this->parserProfile['profile_key'] ?? '') === 'ut4-alpha'
                && $this->assumedUnversionedVersion() === 511;
            $allowUnversioned = (bool)($this->readerOptions['allow_unversioned_content_in_editor']
                ?? $this->parserProfile['allow_unversioned_content_in_editor'] ?? false);
            if (!$isUt4CleanMaster && !$allowUnversioned) {
                throw new RuntimeException('UE4.27.2 editor rejects unversioned package (GAllowUnversionedContentInEditor=0).');
            }
            $ue4Version = $this->assumedUnversionedVersion();
            $this->header['unversioned'] = true;
            $this->header['version'] = $ue4Version;
            if ($isUt4CleanMaster) {
                // The separately pinned UT4 v511 profile retains its existing
                // informational notice consumed by Uedb5Ut4SnapshotBuilder.
                $this->issues[] = 'Package is unversioned; using assumed UE4 parser version ' . $ue4Version
                    . ' from parser profile ' . (string)$this->header['parserProfileKey'] . ' for table parsing.';
            } else {
                $this->header['unversionedEditorPolicy'] = 'explicitly_allowed';
                // UE4.27.2 substitutes FCurrentCustomVersions::GetAll() at runtime;
                // file bytes cannot independently establish those versions.
                $this->header['unversionedUsesRuntimeCustomVersions'] = true;
            }
        }
        // Epic 4.27.2 LinkerLoad.cpp refuses packages newer than the stock
        // engine's licensee version (VER_LIC_AUTOMATIC_VERSION == 0).
        if ($licensee > 0) {
            throw new RuntimeException('UE4 package licensee version ' . $licensee . ' exceeds stock UE4.27.2 licensee version 0.');
        }
        if ($ue4Version < self::VER_OLDEST_LOADABLE_PACKAGE || $ue4Version > self::DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION) {
            throw new RuntimeException('Unsupported UE4 package version ' . $ue4Version . '; supported range=' . self::VER_OLDEST_LOADABLE_PACKAGE . '..' . self::DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION);
        }

        $this->header['totalHeaderSize'] = $r->i32();
        $this->header['folderName'] = $r->fstring();
        $this->header['packageFlags'] = $r->u32();
        $this->header['nameCount'] = $r->i32();
        $this->header['nameOffset'] = $r->i32();
        $filterEditorOnly = (((int)$this->header['packageFlags']) & 0x80000000) !== 0;
        if (!$filterEditorOnly && $ue4Version >= self::VER_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID) {
            $this->header['localizationId'] = $r->fstring();
        }
        if ($ue4Version >= self::VER_SERIALIZE_TEXT_IN_PACKAGES) {
            $this->header['gatherableTextDataCount'] = $r->i32();
            $this->header['gatherableTextDataOffset'] = $r->i32();
        }
        $this->header['exportCount'] = $r->i32();
        $this->header['exportOffset'] = $r->i32();
        $this->header['importCount'] = $r->i32();
        $this->header['importOffset'] = $r->i32();
        $this->header['dependsOffset'] = $r->i32();
        if ($ue4Version >= self::VER_ADD_STRING_ASSET_REFERENCES_MAP) {
            $this->header['stringAssetReferencesCount'] = $r->i32();
            $this->header['stringAssetReferencesOffset'] = $r->i32();
        }
        if ($ue4Version >= self::VER_ADDED_SEARCHABLE_NAMES) {
            $this->header['searchableNamesOffset'] = $r->i32();
        }
        $this->header['thumbnailTableOffset'] = $r->i32();
        $this->header['guid'] = $r->guid();
        if (!$filterEditorOnly && $ue4Version >= self::VER_ADDED_PACKAGE_OWNER) {
            $this->header['persistentGuid'] = $r->guid();
            if ($ue4Version < self::VER_NON_OUTER_PACKAGE_IMPORT) {
                $this->header['ownerPersistentGuid'] = $r->guid();
            }
        }

        $genCount = $r->i32();
        $this->assertArrayCountFits($r, $genCount, 8, 'UE4 generation');
        for ($i = 0; $i < $genCount; $i++) {
            $this->header['generations'][] = ['exportCount' => $r->i32(), 'nameCount' => $r->i32()];
        }

        $this->header['savedByEngineVersion'] = $ue4Version >= self::VER_ENGINE_VERSION_OBJECT ? $this->readEngineVersion($r) : ['changelist' => $r->i32()];
        $this->header['compatibleWithEngineVersion'] = $ue4Version >= self::VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION ? $this->readEngineVersion($r) : $this->header['savedByEngineVersion'];
        $this->header['compressionFlags'] = $r->u32();
        // Epic FCompression::VerifyCompressionFlagsValid permits only the
        // deprecated format and option masks (0x0F | 0xF0).
        if (((int)$this->header['compressionFlags'] & ~0xFF) !== 0) {
            throw new RuntimeException('UE4 package has invalid compression flags 0x' . strtoupper(dechex((int)$this->header['compressionFlags'])));
        }
        $this->header['compressedChunks'] = $this->readCompressedChunks($r);
        // UE4.27.2 FPackageFileSummary aborts on old package-level
        // compression; decoding following offsets would be unsafe.
        if ($this->header['compressedChunks'] !== []) {
            throw new RuntimeException('UE4 package uses unsupported legacy package-level compressed chunks.');
        }
        $this->header['packageSource'] = $r->u32();
        $this->header['additionalPackagesToCook'] = $this->readStringArray($r);
        if ($legacy > -7) {
            $this->header['textureAllocationsRemoved'] = $r->i32();
        }
        $this->header['assetRegistryDataOffset'] = $r->i32();
        $this->header['bulkDataStartOffset'] = $r->i64();
        if ($ue4Version >= self::VER_WORLD_LEVEL_INFO) {
            $this->header['worldTileInfoDataOffset'] = $r->i32();
        }
        if ($ue4Version >= self::VER_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS) {
            $this->header['chunkIDs'] = $this->readIntArray($r);
        } elseif ($ue4Version >= self::VER_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE) {
            $chunkId = $r->i32();
            if ($chunkId >= 0) {
                $this->header['chunkIDs'][] = $chunkId;
            }
        }
        if ($ue4Version >= self::VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS) {
            $this->header['preloadDependencyCount'] = $r->i32();
            $this->header['preloadDependencyOffset'] = $r->i32();
        } else {
            $this->header['preloadDependencyCount'] = -1;
        }

        $this->header['uexpPath'] = $this->guessUexpPath();
        $this->header['hasUexp'] = $this->header['uexpPath'] !== '' && is_file($this->header['uexpPath']);

        $this->validateSummaryBounds();
        $this->readNames();
        $this->readImports();
        $this->readExports();
        $this->readDependsMap();
        $this->readStringAssetReferences();
        $this->readPreloadDependencies();
        $this->readThumbnailIndex();
        $this->readAssetRegistryData();
        $this->readAssetRegistryDependencies();
        $this->readWorldTileInfo();
    }

    private function readCustomVersions(UE4BinaryReader $r, int $legacy): array
    {
        $count = $r->i32();
        $minimumEntrySize = $legacy === -2 ? 8 : ($legacy >= -5 ? 24 : 20);
        $this->assertArrayCountFits($r, $count, $minimumEntrySize, 'custom version');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if ($legacy === -2) {
                // Epic CustomVersion.cpp::FEnumCustomVersion_DEPRECATED
                // converts its uint32 Tag into FGuid(0, 0, 0, Tag).
                // Preserve the wire tag AND its effective Epic GUID identity.
                $key = $r->u32();
                $version = $r->i32();
                $out[] = [
                    'format' => 'enum',
                    'key' => $key,
                    'guid' => sprintf('%08X-%08X-%08X-%08X', 0, 0, 0, $key),
                    'version' => $version,
                    'friendlyName' => 'EnumTag' . $key,
                ];
            } elseif ($legacy >= -5) {
                $out[] = ['format' => 'guid-deprecated', 'guid' => $r->guid(), 'version' => $r->i32(), 'friendlyName' => $r->fstring()];
            } else {
                $out[] = ['format' => 'optimized', 'guid' => $r->guid(), 'version' => $r->i32()];
            }
        }
        return $out;
    }

    private function readEngineVersion(UE4BinaryReader $r): array
    {
        return ['major' => $r->u16(), 'minor' => $r->u16(), 'patch' => $r->u16(), 'changelist' => $r->u32(), 'branch' => $r->fstring()];
    }

    private function readCompressedChunks(UE4BinaryReader $r): array
    {
        $count = $r->i32();
        $this->assertArrayCountFits($r, $count, 16, 'compressed chunk');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['uncompressedOffset' => $r->i32(), 'uncompressedSize' => $r->i32(), 'compressedOffset' => $r->i32(), 'compressedSize' => $r->i32()];
        }
        return $out;
    }

    private function readStringArray(UE4BinaryReader $r): array
    {
        $count = $r->i32();
        // Every serialized FString has at least its int32 length prefix.
        $this->assertArrayCountFits($r, $count, 4, 'string array');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $r->fstring();
        }
        return $out;
    }

    private function readIntArray(UE4BinaryReader $r): array
    {
        $count = $r->i32();
        $this->assertArrayCountFits($r, $count, 4, 'int array');
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $r->i32();
        }
        return $out;
    }

    private function assertArrayCountFits(UE4BinaryReader $r, int $count, int $minimumElementSize, string $label): void
    {
        if ($count < 0 || $minimumElementSize <= 0 || $count > intdiv($r->remaining(), $minimumElementSize)) {
            throw new RuntimeException('Bad ' . $label . ' count ' . $count . ' at ' . ($r->tell() - 4) . '; remaining=' . $r->remaining() . ' minElementSize=' . $minimumElementSize);
        }
    }

    private function rootReader(): UE4BinaryReader
    {
        if (!$this->reader instanceof UE4BinaryReader) throw new RuntimeException('UE4 package stream is not open.');
        $this->reader->seek(0); return $this->reader;
    }
    private function tableReader(int $offset): UE4BinaryReader
    {
        $r = $this->rootReader(); $r->seek($offset); return $r;
    }

    private function validateSummaryBounds(): void
    {
        $headerSize = (int)($this->header['totalHeaderSize'] ?? 0);
        if ($headerSize <= 0 || $headerSize > $this->fileSize) {
            throw new RuntimeException("Bad UE4 total header size $headerSize; fileSize={$this->fileSize}");
        }

        $version = (int)($this->header['version'] ?? 0);
        $filterEditorOnly = (((int)($this->header['packageFlags'] ?? 0)) & 0x80000000) !== 0;
        $nameMinimum = $version >= self::VER_NAME_HASHES_SERIALIZED ? 8 : 4;
        $importMinimum = 28 + (($version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly) ? 8 : 0);
        $exportMinimum = 64;
        if ($version >= self::VER_LOAD_FOR_EDITOR_GAME) $exportMinimum += 4;
        if ($version >= self::VER_COOKED_ASSETS_IN_EDITOR_SUPPORT) $exportMinimum += 4;
        if ($version >= self::VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS) $exportMinimum += 20;
        if ($version >= self::VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS) $exportMinimum += 4;
        if ($version >= self::VER_64BIT_EXPORTMAP_SERIALSIZES) $exportMinimum += 8;

        foreach ([['name', 'nameCount', 'nameOffset', $nameMinimum], ['import', 'importCount', 'importOffset', $importMinimum], ['export', 'exportCount', 'exportOffset', $exportMinimum]] as $table) {
            [$label, $countKey, $offsetKey, $minimumEntrySize] = $table;
            $count = (int)($this->header[$countKey] ?? 0);
            $offset = (int)($this->header[$offsetKey] ?? 0);
            if ($count < 0) {
                throw new RuntimeException("Bad $label count: $count");
            }
            if ($count > 0) {
                if ($offset <= 0 || $offset >= $headerSize || $offset >= $this->fileSize) {
                    throw new RuntimeException("Bad $label offset: $offset; totalHeaderSize=$headerSize fileSize={$this->fileSize}");
                }
                if ($count > intdiv($headerSize - $offset, $minimumEntrySize)) {
                    throw new RuntimeException("Bad $label count: $count cannot fit before totalHeaderSize=$headerSize from offset=$offset");
                }
            }
        }

        foreach ([
            ['soft package references', 'stringAssetReferencesCount', 'stringAssetReferencesOffset', 8],
            ['preload dependencies', 'preloadDependencyCount', 'preloadDependencyOffset', 4],
        ] as $table) {
            [$label, $countKey, $offsetKey, $minimumEntrySize] = $table;
            $count = (int)($this->header[$countKey] ?? 0);
            $offset = (int)($this->header[$offsetKey] ?? 0);
            if ($count < 0) {
                if ($label === 'preload dependencies' && $count === -1) continue;
                throw new RuntimeException("Bad $label count: $count");
            }
            if ($count > 0 && ($offset <= 0 || $offset >= $headerSize || $count > intdiv($headerSize - $offset, $minimumEntrySize))) {
                throw new RuntimeException("Bad $label table: count=$count offset=$offset totalHeaderSize=$headerSize");
            }
        }
    }

    private function readNames(): void
    {
        $count = (int)$this->header['nameCount'];
        $offset = (int)$this->header['nameOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= $this->fileSize) {
            return;
        }
        $r = $this->tableReader($offset);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < $count; $i++) {
            $entryOffset = $r->tell();
            $name = $this->readSerializedNameEntry($r);
            $nonCaseHash = null;
            $caseHash = null;
            if ($version >= self::VER_NAME_HASHES_SERIALIZED) {
                $nonCaseHash = $r->u16();
                $caseHash = $r->u16();
            }
            $this->names[] = ['index' => $i, 'name' => $name, 'offset' => $entryOffset, 'nonCaseHash' => $nonCaseHash, 'caseHash' => $caseHash];
        }
    }

    private function readSerializedNameEntry(UE4BinaryReader $r): string
    {
        $length = $r->i32();
        if ($length === 0 || $length < -self::NAME_SIZE || $length > self::NAME_SIZE) {
            throw new RuntimeException('Bad UE4 name entry length ' . $length . ' at ' . ($r->tell() - 4) . '; NAME_SIZE=' . self::NAME_SIZE);
        }

        if ($length > 0) {
            if ($length > $r->remaining()) {
                throw new OutOfBoundsException("UE4 name entry overrun length=$length pos={$r->tell()} remaining={$r->remaining()}");
            }
            $raw = $r->bytes($length);
            if (substr($raw, -1) === "\0") $raw = substr($raw, 0, -1);
            return UE4BinaryReader::toUtf8($raw);
        }

        if ($length === -2147483648) {
            throw new RuntimeException('Bad UE4 wide name entry length INT32_MIN');
        }
        $chars = -$length;
        $bytes = $chars * 2;
        if ($bytes > $r->remaining()) {
            throw new OutOfBoundsException("UE4 wide name entry overrun chars=$chars pos={$r->tell()} remaining={$r->remaining()}");
        }
        $raw = $r->bytes($bytes);
        if (substr($raw, -2) === "\0\0") $raw = substr($raw, 0, -2);
        // Epic Core/Private/UObject/UnrealNames.cpp FNameEntrySerialized::operator<<
        // swaps the signed StringLen via Archive but reads UCS2CHAR bytes using
        // Ar.Serialize() without element byte-swapping, even when the package
        // tag requested ForceByteSwapping on a little-endian host.
        // FNameEntrySerialized therefore differs from an archive FString.
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        if ($out === false) throw new RuntimeException('Failed to decode UE4 wide name entry.');
        return $out;
    }

    private function readFName(UE4BinaryReader $r, bool $enforceLinkerNameMapIndex = true): array
    {
        $idx = $r->i32();
        $num = $r->i32();
        // Epic LinkerLoad.h::operator<<(FName&) checks NameMap.IsValidIndex
        // and raises a critical archive error on an invalid serialized index.
        // Silently inventing an empty FName loses source-defined corruption.
        if ($enforceLinkerNameMapIndex && !isset($this->names[$idx])) {
            throw new RuntimeException('UE4 FName index ' . $idx . ' is outside NameMap count ' . count($this->names));
        }
        return ['index' => $idx, 'number' => $num, 'text' => $this->nameByIndex($idx, $num)];
    }

    private function fnameText(array $name): string
    {
        return (string)($name['text'] ?? '');
    }

    private function readImports(): void
    {
        $count = (int)$this->header['importCount'];
        $offset = (int)$this->header['importOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= $this->fileSize) {
            return;
        }
        $r = $this->tableReader($offset);
        $version = (int)$this->header['version'];
        $filterEditorOnly = (((int)$this->header['packageFlags']) & 0x80000000) !== 0;
        for ($i = 0; $i < $count; $i++) {
            $start = $r->tell();
            $classPackage = $this->readFName($r);
            $className = $this->readFName($r);
            $outerIndex = $r->i32();
            $objectName = $this->readFName($r);
            $packageNamePresent = $version >= self::VER_NON_OUTER_PACKAGE_IMPORT && !$filterEditorOnly;
            $packageName = $packageNamePresent
                ? $this->readFName($r)
                : ['index' => 0, 'number' => 0, 'text' => ''];
            $this->imports[] = [
                'index' => $i,
                'ref' => -($i + 1),
                'offset' => $start,
                'classPackage' => $classPackage,
                'ClassPackage' => $classPackage,
                'classPackageText' => $this->fnameText($classPackage),
                'className' => $className,
                'ClassName' => $className,
                'classNameText' => $this->fnameText($className),
                'outerIndex' => $outerIndex,
                'OuterIndex' => $outerIndex,
                'outerName' => $this->displayNameFromRef($outerIndex),
                'objectName' => $objectName,
                'ObjectName' => $objectName,
                'objectNameText' => $this->fnameText($objectName),
                'packageNamePresent' => $packageNamePresent,
                'packageName' => $packageName,
                'PackageName' => $packageName,
                'packageNameText' => $this->fnameText($packageName),
            ];
        }
    }

    private function readExports(): void
    {
        $count = (int)$this->header['exportCount'];
        $offset = (int)$this->header['exportOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= $this->fileSize) {
            return;
        }
        $r = $this->tableReader($offset);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < $count; $i++) {
            $start = $r->tell();
            $classIndex = $r->i32();
            $superIndex = $r->i32();
            $templateIndexPresent = $version >= self::VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS;
            $templateIndex = 0;
            if ($templateIndexPresent) {
                $templateIndex = $r->i32();
            }
            $outerIndex = $r->i32();
            $objectName = $this->readFName($r);
            $serializedObjectFlags = $r->u32();
            // Epic ObjectResource.cpp::operator<<(FObjectExport&) loads only
            // Save & RF_Load into E.ObjectFlags. The wire value is kept
            // separately for byte-level audit and diagnostics.
            $objectFlags = $serializedObjectFlags & 0x02D4003B;
            $serialSizeWidthBits = $version >= self::VER_64BIT_EXPORTMAP_SERIALSIZES ? 64 : 32;
            if ($serialSizeWidthBits === 64) {
                $serialSize = $r->i64();
                $serialOffset = $r->i64();
            } else {
                $serialSize = $r->i32();
                $serialOffset = $r->i32();
            }
            $forcedExport = $r->i32() !== 0;
            $notForClient = $r->i32() !== 0;
            $notForServer = $r->i32() !== 0;
            $packageGuid = $r->guid();
            $packageFlags = $r->u32();
            $notForEditorGamePresent = $version >= self::VER_LOAD_FOR_EDITOR_GAME;
            $isAssetPresent = $version >= self::VER_COOKED_ASSETS_IN_EDITOR_SUPPORT;
            $notForEditorGame = $notForEditorGamePresent ? $r->i32() !== 0 : null;
            $isAsset = $isAssetPresent ? $r->i32() !== 0 : null;
            $preloadPresent = $version >= self::VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS;
            $preload = [];
            if ($preloadPresent) {
                $preload = [
                    'firstExportDependency' => $r->i32(),
                    'serializationBeforeSerializationDependencies' => $r->i32(),
                    'createBeforeSerializationDependencies' => $r->i32(),
                    'serializationBeforeCreateDependencies' => $r->i32(),
                    'createBeforeCreateDependencies' => $r->i32(),
                ];
            }
            $this->exports[] = [
                'index' => $i,
                'ref' => $i + 1,
                'offset' => $start,
                'classIndex' => $classIndex,
                'className' => $this->displayNameFromRef($classIndex),
                'superIndex' => $superIndex,
                'superName' => $this->displayNameFromRef($superIndex),
                'templateIndexPresent' => $templateIndexPresent,
                'templateIndex' => $templateIndex,
                'templateName' => $this->displayNameFromRef($templateIndex),
                'outerIndex' => $outerIndex,
                'outerName' => $this->displayNameFromRef($outerIndex),
                'objectName' => $objectName,
                'ObjectName' => $objectName,
                'objectNameText' => $this->fnameText($objectName),
                'objectFlags' => $objectFlags,
                'serializedObjectFlags' => $serializedObjectFlags,
                'serialSizeWidthBits' => $serialSizeWidthBits,
                'serialSize' => $serialSize,
                'serialOffset' => $serialOffset,
                'forcedExport' => $forcedExport,
                'notForClient' => $notForClient,
                'notForServer' => $notForServer,
                'notForEditorGamePresent' => $notForEditorGamePresent,
                'notForEditorGame' => $notForEditorGame,
                'isAssetPresent' => $isAssetPresent,
                'isAsset' => $isAsset,
                'packageGuid' => $packageGuid,
                'packageFlags' => $packageFlags,
                'preloadPresent' => $preloadPresent,
                'preload' => $preload,
            ];
        }
    }

    /**
     * Epic FLinkerLoad::SerializeDependsMap: one TArray<FPackageIndex>
     * per export, serialized as count then signed package-index words.
     */
    private function readDependsMap(): void
    {
        $offset = (int)($this->header['dependsOffset'] ?? 0);
        $exportCount = (int)($this->header['exportCount'] ?? 0);
        if ($offset <= 0 || $exportCount <= 0) {
            return; // Epic skips absent DependsOffset (common cooked packages).
        }
        try {
            $r = $this->tableReader($offset);
            for ($i = 0; $i < $exportCount; $i++) {
                $rowOffset = $r->tell();
                $count = $r->i32();
                $this->assertArrayCountFits($r, $count, 4, 'UE4 DependsMap export ' . $i);
                $references = [];
                for ($j = 0; $j < $count; $j++) {
                    $references[] = $r->i32();
                }
                $this->dependsMap[] = ['exportIndex' => $i, 'offset' => $rowOffset, 'references' => $references];
            }
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse UE4 DependsMap: ' . $e->getMessage();
        }
    }

    /**
     * Epic FLinkerLoad::SerializeThumbnails reads the editor-only index as
     * Count, then ObjectClassName FString, ObjectPath FString and FileOffset.
     * This exposes the index only, without decoding thumbnail image bytes.
     */
    private function readThumbnailIndex(): void
    {
        $offset = (int)($this->header['thumbnailTableOffset'] ?? 0);
        if ($offset <= 0) return;
        try {
            $r = $this->tableReader($offset);
            $count = $r->i32();
            $this->assertArrayCountFits($r, $count, 12, 'UE4 thumbnail index');
            for ($i = 0; $i < $count; $i++) {
                $recordOffset = $r->tell();
                $className = $r->fstring();
                $objectPath = $r->fstring();
                $dataOffset = $r->i32();
                if ($dataOffset < 0 || $dataOffset >= $this->fileSize) {
                    throw new OutOfBoundsException("UE4 thumbnail payload offset invalid at entry $i");
                }
                $this->thumbnailIndex[] = [
                    'index' => $i,
                    'offset' => $recordOffset,
                    'className' => $className,
                    'objectPath' => $objectPath,
                    'fileOffset' => $dataOffset,
                ];
            }
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse UE4 thumbnail index: ' . $e->getMessage();
        }
    }

    /**
     * Source-shaped raw AssetRegistry main section, separate from the
     * package-name synthesis and dependency bit arrays in FPackageReader.
     * The cooker deliberately emits pre-dependency format for filtered
     * editor-only packages, even when the package version is recent.
     */
    private function readAssetRegistryData(): void
    {
        $offset = (int)($this->header['assetRegistryDataOffset'] ?? 0);
        if ($offset <= 0) return;
        try {
            $r = $this->tableReader($offset);
            $isPreDependencyFormat =
                (int)($this->header['version'] ?? 0) < self::VER_ASSETREGISTRY_DEPENDENCYFLAGS
                || (((int)($this->header['packageFlags'] ?? 0) & 0x80000000) !== 0);
            $dependencyOffset = $isPreDependencyFormat ? -1 : $r->i64();
            $count = $r->i32();
            $this->assertArrayCountFits($r, $count, 12, 'UE4 AssetRegistry object');
            $objects = [];
            for ($i = 0; $i < $count; $i++) {
                $recordOffset = $r->tell();
                $objectPath = $r->fstring();
                $className = $r->fstring();
                $tagCount = $r->i32();
                $this->assertArrayCountFits($r, $tagCount, 8, 'UE4 AssetRegistry object tags');
                $tags = [];
                for ($j = 0; $j < $tagCount; $j++) {
                    $tagOffset = $r->tell();
                    $tags[] = [
                        'index' => $j,
                        'offset' => $tagOffset,
                        'key' => $r->fstring(),
                        'value' => $r->fstring(),
                    ];
                }
                $objects[] = [
                    'index' => $i, 'offset' => $recordOffset,
                    'objectPath' => $objectPath, 'className' => $className,
                    'tagCount' => $tagCount, 'tags' => $tags,
                ];
            }
            $this->assetRegistryDependencyDataOffset = $dependencyOffset;
            $this->assetRegistryObjects = $objects;
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse UE4 AssetRegistry main section: ' . $e->getMessage();
        }
    }

    /**
     * UE4 TBitArray<FDefaultBitArrayAllocator>::operator<< emits a signed
     * bit count followed by ceil(count/32) raw 32-bit storage words. Epic
     * FArchive::Serialize does not integer-byte-swap the word payload.
     * Return decoded booleans in logical import/soft-package index order.
     */
    private function readAssetRegistryDependencies(): void
    {
        $offset = $this->assetRegistryDependencyDataOffset;
        if ($offset === -1) return; // Older packages: Epic defaults all bits true.
        try {
            if ($offset <= 0 || $offset > $this->fileSize - 8) {
                throw new OutOfBoundsException("UE4 AssetRegistry dependency section offset invalid: $offset");
            }
            $r = $this->tableReader($offset);
            $imports = $this->readRegistryBitArray($r, 'imports');
            $soft = $this->readRegistryBitArray($r, 'soft packages');
            // Epic FPackageDependencyData::IsValid checks these exact sizes.
            if (count($imports) !== count($this->imports)
                || count($soft) !== (int)($this->header['stringAssetReferencesCount'] ?? 0)) {
                throw new OutOfBoundsException('UE4 AssetRegistry dependency bit counts do not match source import/soft-reference maps');
            }
            $this->assetRegistryDependencyBits = [
                'offset' => $offset,
                'endOffset' => $r->tell(),
                'importUsedInGame' => $imports,
                'softPackageUsedInGame' => $soft,
            ];
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse UE4 AssetRegistry dependencies: ' . $e->getMessage();
        }
    }

    /** @return array<int,bool> */
    private function readRegistryBitArray(UE4BinaryReader $r, string $label): array
    {
        $count = $r->i32();
        if ($count < 0 || $count > (intdiv($r->remaining(), 4) * 32)) {
            throw new OutOfBoundsException("Bad UE4 AssetRegistry $label bit count $count");
        }
        $words = intdiv($count + 31, 32);
        $bytes = $r->bytes($words * 4);
        $bits = [];
        for ($i = 0; $i < $count; ++$i) {
            $bits[] = ((ord($bytes[intdiv($i, 8)]) >> ($i & 7)) & 1) !== 0;
        }
        return $bits;
    }

    /**
     * The editor-only World Composition record is stored at the summary's
     * WorldTileInfoDataOffset, independently of AssetRegistry and thumbnails.
     * Decode the source-version and Fortnite-main custom-version gates used
     * by Epic's FWorldTileInfo/FWorldTileLayer operator<<.
     */
    private function readWorldTileInfo(): void
    {
        $offset = (int)($this->header['worldTileInfoDataOffset'] ?? 0);
        if ($offset === 0) return;
        try {
            if ($offset < 0 || $offset >= $this->fileSize) {
                throw new OutOfBoundsException("UE4 WorldTileInfo offset is outside package: $offset");
            }
            $r = $this->tableReader($offset);
            $version = (int)$this->header['version'];
            $fortniteVersion = -1;
            foreach ((array)($this->header['customVersions'] ?? []) as $custom) {
                if (strtoupper((string)($custom['guid'] ?? '')) === self::FORTNITE_MAIN_BRANCH_VERSION_GUID) {
                    $fortniteVersion = (int)$custom['version'];
                    break;
                }
            }
            $x = $r->i32();
            $y = $r->i32();
            $z = $fortniteVersion >= self::FORTNITE_WORLD_TILE_3D_OFFSET_VERSION ? $r->i32() : 0;
            $boundsMin = [$r->f32(), $r->f32(), $r->f32()];
            $boundsMax = [$r->f32(), $r->f32(), $r->f32()];
            $boundsValid = $r->u8() !== 0;
            $layerName = $r->fstring();
            $reserved0 = $r->i32();
            $reservedPosition = [$r->i32(), $r->i32()];
            $distance = $version >= self::VER_WORLD_LEVEL_INFO_UPDATED ? $r->i32() : 50000;
            // FArchive serializes bool as an int32, unlike FBox::IsValid (uint8).
            $distanceEnabled = $version >= self::VER_WORLD_LAYER_ENABLE_DISTANCE_STREAMING
                ? $r->i32() !== 0 : true;
            $hidden = $version >= self::VER_WORLD_LEVEL_INFO_UPDATED ? $r->i32() !== 0 : false;
            $parent = $version >= self::VER_WORLD_LEVEL_INFO_UPDATED ? $r->fstring() : '';
            $lod = [];
            if ($version >= self::VER_WORLD_LEVEL_INFO_LOD_LIST) {
                $count = $r->i32();
                $this->assertArrayCountFits($r, $count, 20, 'UE4 world tile LOD');
                for ($i = 0; $i < $count; $i++) {
                    $lod[] = [
                        'relativeStreamingDistance' => $r->i32(),
                        'reservedFloats' => [$r->f32(), $r->f32()],
                        'reservedInts' => [$r->i32(), $r->i32()],
                    ];
                }
            }
            $zOrder = $version >= self::VER_WORLD_LEVEL_INFO_ZORDER ? $r->i32() : 0;
            $this->worldTileInfo = [
                'offset' => $offset, 'endOffset' => $r->tell(),
                'position' => [$x, $y, $z],
                'boundsMin' => $boundsMin, 'boundsMax' => $boundsMax,
                'boundsValid' => $boundsValid,
                'layerName' => $layerName, 'layerReserved0' => $reserved0,
                'layerReservedPosition' => $reservedPosition,
                'streamingDistance' => $distance,
                'distanceStreamingEnabled' => $distanceEnabled,
                'hideInTileView' => $hidden,
                'parentTilePackageName' => $parent,
                'lodList' => $lod, 'zOrder' => $zOrder,
                'customVersion3D' => $fortniteVersion >= self::FORTNITE_WORLD_TILE_3D_OFFSET_VERSION,
            ];
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse UE4 WorldTileInfo: ' . $e->getMessage();
        }
    }

    private function readStringAssetReferences(): void
    {
        $count = (int)($this->header['stringAssetReferencesCount'] ?? 0);
        $offset = (int)($this->header['stringAssetReferencesOffset'] ?? 0);
        if ($count <= 0 || $offset <= 0 || $offset >= $this->fileSize) {
            return;
        }

        try {
            $r = $this->tableReader($offset);
            // Epic UE4 <=513 corpus stores this list as length-prefixed
            // FStrings. The >=514 corpus follows UE4.27 SavePackage.cpp
            // and SavePackage2.cpp: package-local FName index/number pairs.
            // UT4 clean-master v511 is independently pinned and already uses
            // FName pairs; its source profile must not inherit the stock
            // historical UE4 wire interpretation.
            $legacyStrings = (int)$this->header['version'] < self::VER_ADDED_SOFT_OBJECT_PATH
                && (string)($this->header['parserProfileKey'] ?? '') !== 'ut4-alpha';
            for ($i = 0; $i < $count; $i++) {
                $entryOffset = $r->tell();
                $path = $legacyStrings
                    ? $r->fstring()
                    : $this->fnameText($this->readFName($r));
                if ($path !== '') {
                    $this->stringAssetReferences[] = [
                        'index' => $i, 'offset' => $entryOffset, 'path' => $path,
                        'source' => 'summary_string_asset_reference',
                        'wireFormat' => $legacyStrings ? 'fstring' : 'fname',
                    ];
                }
            }
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse string asset references map: ' . $e->getMessage();
        }
    }

    private function readPreloadDependencies(): void
    {
        $count = (int)($this->header['preloadDependencyCount'] ?? 0);
        $offset = (int)($this->header['preloadDependencyOffset'] ?? 0);
        if ($count <= 0 || $offset <= 0 || $offset >= $this->fileSize) {
            return;
        }

        try {
            $r = $this->tableReader($offset);
            for ($i = 0; $i < $count; $i++) {
                $entryOffset = $r->tell();
                $ref = $r->i32();
                $this->preloadDependencies[] = [
                    'index' => $i,
                    'offset' => $entryOffset,
                    'ref' => $ref,
                    'path' => $this->displayPathFromRef($ref),
                    'name' => $this->displayNameFromRef($ref),
                ];
            }
        } catch (Throwable $e) {
            $this->issues[] = 'Could not parse preload dependency table: ' . $e->getMessage();
        }
    }

    /**
     * Resolve Epic's logical FObjectExport SerialOffset/SerialSize into the
     * underlying legacy package file, without reading or copying payloads.
     * A split cooked package uses the byte length of its .uasset/.umap
     * header as the boundary; the remaining stream lives in its .uexp.
     *
     * This is deliberately only export payload addressing. FUntypedBulkData
     * chooses .ubulk/.uptnl/.m.ubulk according to serialized flags, linker
     * state, and package source; do not infer bulk providers from this API.
     *
     * @return array{exportIndex:int,logicalOffset:int,size:int,path:string,physicalOffset:int,provider:string}
     */
    public function resolveExportPayloadSpan(int $exportIndex): array
    {
        $row = $this->exports[$exportIndex] ?? null;
        if (!is_array($row)) {
            throw new OutOfBoundsException("UE4 export index does not exist: $exportIndex");
        }
        $logicalOffset = (int)($row['serialOffset'] ?? -1);
        $size = (int)($row['serialSize'] ?? -1);
        if ($logicalOffset < 0 || $size < 0 || $logicalOffset > PHP_INT_MAX - $size) {
            throw new OutOfBoundsException("Invalid UE4 export span index=$exportIndex offset=$logicalOffset size=$size");
        }

        $baseSize = @filesize($this->path);
        if ($baseSize === false) {
            throw new RuntimeException("Cannot stat UE4 package: {$this->path}");
        }
        $baseSize = (int)$baseSize;
        if ($logicalOffset < $baseSize || ($logicalOffset === $baseSize && $size === 0)) {
            if ($size > $baseSize - $logicalOffset) {
                throw new OutOfBoundsException("UE4 export span crosses the base/split file boundary: $exportIndex");
            }
            return [
                'exportIndex' => $exportIndex, 'logicalOffset' => $logicalOffset, 'size' => $size,
                'path' => $this->path, 'physicalOffset' => $logicalOffset, 'provider' => 'uasset',
            ];
        }

        $path = (string)($this->header['uexpPath'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new RuntimeException("UE4 export requires missing .uexp companion: index=$exportIndex");
        }
        $splitSize = @filesize($path);
        if ($splitSize === false) {
            throw new RuntimeException("Cannot stat UE4 .uexp companion: $path");
        }
        $physicalOffset = $logicalOffset - $baseSize;
        if ($size > (int)$splitSize - $physicalOffset) {
            throw new OutOfBoundsException("UE4 export span exceeds .uexp companion: index=$exportIndex");
        }
        return [
            'exportIndex' => $exportIndex, 'logicalOffset' => $logicalOffset, 'size' => $size,
            'path' => $path, 'physicalOffset' => $physicalOffset, 'provider' => 'uexp',
        ];
    }

    private function guessUexpPath(): string
    {
        $base = preg_replace('/\.(uasset|umap)$/i', '.uexp', $this->path);
        return is_string($base) && $base !== $this->path ? $base : '';
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function validatePackage(): array { return $this->issues; }
    public function getFileSize(): string { return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : ''; }
    public function getRawHeaderFields(): array { return $this->rawHeaderFields; }
    public function getStringAssetReferences(): array { return $this->stringAssetReferences; }
    public function getPreloadDependencies(): array { return $this->preloadDependencies; }
    public function getDependsMap(): array { return $this->dependsMap; }
    public function getThumbnailIndex(): array { return $this->thumbnailIndex; }
    /**
     * Lazily read the compressed bytes of one thumbnail via the FileOffset
     * stored in the index. FObjectThumbnail::Serialize writes signed width,
     * height and a signed TArray<uint8> length before the compressed payload.
     * It does not decode image pixels; no bulk read occurs at construction.
     *
     * @return array<string,int|string>
     */
    public function getThumbnailPayload(int $index): array
    {
        if (!isset($this->thumbnailIndex[$index])) {
            throw new OutOfBoundsException('UE4 thumbnail entry not found: ' . $index);
        }
        $entry = $this->thumbnailIndex[$index];
        $r = $this->tableReader((int)$entry['fileOffset']);
        $width = $r->i32();
        $height = $r->i32();
        $bytes = $r->i32();
        if ($width < 0 || $height < 0 || $bytes < 0 || $bytes > $r->remaining()) {
            throw new OutOfBoundsException('Invalid UE4 thumbnail dimensions or compressed byte count');
        }
        $data = $r->bytes($bytes);
        return [
            'fileOffset' => (int)$entry['fileOffset'],
            'endOffset' => $r->tell(),
            'width' => $width,
            'height' => $height,
            'compressedDataSize' => $bytes,
            'compressedData' => $data,
        ];
    }

    public function getAssetRegistryObjects(): array { return $this->assetRegistryObjects; }
    public function getAssetRegistryDependencyDataOffset(): int { return $this->assetRegistryDependencyDataOffset; }
    public function getAssetRegistryDependencyBits(): array { return $this->assetRegistryDependencyBits; }
    public function getWorldTileInfo(): array { return $this->worldTileInfo; }

    public function nameByIndex(int $index, int $number = 0): string
    {
        if ($index < 0 || !isset($this->names[$index])) {
            return '';
        }
        $name = (string)($this->names[$index]['name'] ?? '');
        if ($number > 0 && $name !== '') {
            return $name . '_' . ($number - 1);
        }
        return $name;
    }

    public function displayNameFromRef(int $ref): string
    {
        if ($ref === 0) {
            return '';
        }
        if ($ref > 0) {
            $ex = $this->exports[$ref - 1] ?? null;
            return is_array($ex) ? (string)($ex['objectNameText'] ?? ($ex['objectName']['text'] ?? '')) : '';
        }
        $im = $this->imports[-$ref - 1] ?? null;
        return is_array($im) ? (string)($im['objectNameText'] ?? ($im['objectName']['text'] ?? '')) : '';
    }

    private function displayPathFromRef(int $ref, array $seen = []): string
    {
        if ($ref === 0 || isset($seen[$ref])) {
            return '';
        }
        $seen[$ref] = true;
        if ($ref < 0) {
            $row = $this->imports[-$ref - 1] ?? null;
            if (!is_array($row)) {
                return '';
            }
            $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? 0);
            return $this->joinPathParts([$this->displayPathFromRef($outer, $seen), (string)($row['objectNameText'] ?? '')]);
        }

        $row = $this->exports[$ref - 1] ?? null;
        if (!is_array($row)) {
            return '';
        }
        $outer = (int)($row['outerIndex'] ?? 0);
        return $this->joinPathParts([$this->displayPathFromRef($outer, $seen), (string)($row['objectNameText'] ?? '')]);
    }

    private function joinPathParts(array $parts): string
    {
        $out = [];
        foreach ($parts as $part) {
            $part = trim(str_replace(["\0", "\\"], ['', '/'], (string)$part));
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return implode('.', $out);
    }

    private function rawHexAt(int $offset, int $size): string
    {
        if ($size <= 0) {
            return '';
        }
        if (!$this->reader instanceof UE4BinaryReader || $offset < 0 || $size > $this->reader->size() - $offset) return '';
        $saved = $this->reader->tell();
        try { $this->reader->seek($offset); $raw = $this->reader->bytes($size); }
        finally { $this->reader->seek($saved); }
        return strtoupper(trim(chunk_split(bin2hex($raw), 2, ' ')));
    }

    private function addRawHeaderField(string $name, int $offset, int $size, string $type, $value, string $note = ''): void
    {
        $this->rawHeaderFields[] = [
            'offset' => $offset,
            'size' => $size,
            'name' => $name,
            'type' => $type,
            'value' => $value,
            'rawHex' => $this->rawHexAt($offset, $size),
            'note' => $note,
        ];
    }
}
