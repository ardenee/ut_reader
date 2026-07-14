<?php
declare(strict_types=1);

final class UE4BinaryReader
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { $this->pos = max(0, min($pos, $this->len)); }
    public function remaining(): int { return $this->len - $this->pos; }
    public function size(): int { return $this->len; }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->len) {
            throw new OutOfBoundsException("read overrun need=$count pos={$this->pos} len={$this->len}");
        }
        $out = substr($this->buf, $this->pos, $count);
        $this->pos += $count;
        return $out;
    }

    public function u8(): int { return ord($this->bytes(1)); }
    public function u16(): int { return unpack('v', $this->bytes(2))[1]; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }

    public function u64(): int
    {
        $p = unpack('Vlo/Vhi', $this->bytes(8));
        return (int)($p['lo'] + ($p['hi'] * 4294967296));
    }

    public function i64(): int
    {
        $p = unpack('Vlo/Vhi', $this->bytes(8));
        $v = $p['lo'] + ($p['hi'] * 4294967296);
        return ($p['hi'] & 0x80000000) ? (int)($v - 18446744073709551616.0) : (int)$v;
    }

    public function fstring(): string
    {
        $length = $this->i32();
        if ($length === 0) {
            return '';
        }
        if ($length > 0) {
            if ($length > 1048576 || $length > $this->remaining()) {
                throw new OutOfBoundsException("bad FString length=$length pos={$this->pos}");
            }
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            return self::toUtf8($raw);
        }

        $chars = -$length;
        $bytes = $chars * 2;
        if ($chars > 524288 || $bytes > $this->remaining()) {
            throw new OutOfBoundsException("bad wide FString length=$length pos={$this->pos}");
        }
        $raw = $this->bytes($bytes);
        if (substr($raw, -2) === "\0\0") {
            $raw = substr($raw, 0, -2);
        }
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
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
    private string $bytes = '';
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

    private const PACKAGE_FILE_TAG = 0x9E2A83C1;
    private const PACKAGE_FILE_TAG_SWAPPED = 0xC1832A9E;

    // Version gates matching the UT4-era UE4 package summary layout used by this reader.
    private const VER_SERIALIZE_TEXT_IN_PACKAGES = 459;
    private const VER_ADD_STRING_ASSET_REFERENCES_MAP = 384;
    private const VER_ADDED_SEARCHABLE_NAMES = 510;
    private const VER_ENGINE_VERSION_OBJECT = 336;
    private const VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION = 444;
    private const VER_WORLD_LEVEL_INFO = 225;
    private const VER_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE = 278;
    private const VER_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS = 429;
    private const VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS = 507;
    private const VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS = 508;
    private const VER_LOAD_FOR_EDITOR_GAME = 423;
    private const VER_COOKED_ASSETS_IN_EDITOR_SUPPORT = 482;
    private const VER_64BIT_EXPORTMAP_SERIALSIZES = 511;
    private const VER_NAME_HASHES_SERIALIZED = 504;
    private const DEFAULT_ASSUMED_UNVERSIONED_UE4_VERSION = 511;

    public function __construct(string $path, array $options = [])
    {
        $this->path = $path;
        $this->readerOptions = $options ?: (function_exists('catalog_ue4_take_next_reader_options') ? catalog_ue4_take_next_reader_options() : []);
        $profile = $this->readerOptions['parser_profile'] ?? [];
        $this->parserProfile = is_array($profile) ? $profile : [];

        try {
            $data = file_get_contents($path);
            if ($data === false) {
                throw new RuntimeException("Failed to read UE4 package: $path");
            }
            $this->bytes = $data;
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
            'legacyFileVersion' => 0,
            'legacyUE3Version' => 0,
            'version' => 0,
            'licenseeVersion' => 0,
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
        $r = new UE4BinaryReader($this->bytes);
        $tag = $r->u32();
        if ($tag !== self::PACKAGE_FILE_TAG && $tag !== self::PACKAGE_FILE_TAG_SWAPPED) {
            throw new RuntimeException(sprintf('Bad UE4 package tag 0x%08X', $tag));
        }
        if ($tag === self::PACKAGE_FILE_TAG_SWAPPED) {
            throw new RuntimeException('Big-endian swapped UE4 packages are not supported yet.');
        }

        $legacy = $r->i32();
        $this->header = $this->blankHeader();
        $this->attachParserProfileToHeader();
        $this->header['signature'] = $tag;
        $this->header['legacyFileVersion'] = $legacy;

        if ($legacy >= 0) {
            throw new RuntimeException('This looks like an older UE package, not a modern UE4 package. LegacyFileVersion=' . $legacy);
        }
        if ($legacy < -7) {
            $this->issues[] = 'Package legacy version is newer than this UE4 parser profile understands: ' . $legacy;
        }
        if ($legacy !== -4) {
            $this->header['legacyUE3Version'] = $r->i32();
        }

        $ue4Version = $r->i32();
        $licensee = $r->i32();
        $this->header['version'] = $ue4Version;
        $this->header['licenseeVersion'] = $licensee;

        if ($legacy <= -2) {
            $this->header['customVersions'] = $this->readCustomVersions($r, $legacy);
        }
        if ($ue4Version === 0 && $licensee === 0) {
            $ue4Version = $this->assumedUnversionedVersion();
            $this->header['unversioned'] = true;
            $this->header['version'] = $ue4Version;
            $this->issues[] = 'Package is unversioned; using assumed UE4 parser version ' . $ue4Version . ' from parser profile ' . (string)$this->header['parserProfileKey'] . ' for table parsing.';
        }

        $this->header['totalHeaderSize'] = $r->i32();
        $this->header['folderName'] = $r->fstring();
        $this->header['packageFlags'] = $r->u32();
        $this->header['nameCount'] = $r->i32();
        $this->header['nameOffset'] = $r->i32();
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

        $genCount = $r->i32();
        if ($genCount < 0 || $genCount > 1024) {
            throw new RuntimeException('Bad UE4 generation count ' . $genCount . ' at ' . ($r->tell() - 4));
        }
        for ($i = 0; $i < $genCount; $i++) {
            $this->header['generations'][] = ['exportCount' => $r->i32(), 'nameCount' => $r->i32()];
        }

        $this->header['savedByEngineVersion'] = $ue4Version >= self::VER_ENGINE_VERSION_OBJECT ? $this->readEngineVersion($r) : ['changelist' => $r->i32()];
        $this->header['compatibleWithEngineVersion'] = $ue4Version >= self::VER_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION ? $this->readEngineVersion($r) : $this->header['savedByEngineVersion'];
        $this->header['compressionFlags'] = $r->u32();
        $this->header['compressedChunks'] = $this->readCompressedChunks($r);
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

        $this->validateTableBounds();
        $this->readNames();
        $this->readImports();
        $this->readExports();
        $this->readStringAssetReferences();
        $this->readPreloadDependencies();
    }

    private function readCustomVersions(UE4BinaryReader $r, int $legacy): array
    {
        $count = $r->i32();
        if ($count < 0 || $count > 4096) {
            throw new RuntimeException('Bad custom version count ' . $count . ' at ' . ($r->tell() - 4));
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            if ($legacy === -2) {
                $out[] = ['format' => 'enum', 'key' => $r->i32(), 'version' => $r->i32()];
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
        if ($count < 0 || $count > 65536) {
            throw new RuntimeException('Bad compressed chunk count ' . $count . ' at ' . ($r->tell() - 4));
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = ['uncompressedOffset' => $r->i32(), 'uncompressedSize' => $r->i32(), 'compressedOffset' => $r->i32(), 'compressedSize' => $r->i32()];
        }
        return $out;
    }

    private function readStringArray(UE4BinaryReader $r): array
    {
        $count = $r->i32();
        if ($count < 0 || $count > 65536) {
            throw new RuntimeException('Bad string array count ' . $count . ' at ' . ($r->tell() - 4));
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $r->fstring();
        }
        return $out;
    }

    private function readIntArray(UE4BinaryReader $r): array
    {
        $count = $r->i32();
        if ($count < 0 || $count > 1048576) {
            throw new RuntimeException('Bad int array count ' . $count . ' at ' . ($r->tell() - 4));
        }
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $r->i32();
        }
        return $out;
    }

    private function tableReader(int $offset): UE4BinaryReader
    {
        $r = new UE4BinaryReader($this->bytes);
        $r->seek($offset);
        return $r;
    }

    private function validateTableBounds(): void
    {
        foreach ([['name', 'nameCount', 'nameOffset'], ['import', 'importCount', 'importOffset'], ['export', 'exportCount', 'exportOffset']] as $table) {
            [$label, $countKey, $offsetKey] = $table;
            $count = (int)($this->header[$countKey] ?? 0);
            $offset = (int)($this->header[$offsetKey] ?? 0);
            if ($count < 0) {
                $this->issues[] = "Bad $label count: $count";
            }
            if ($count > 0 && ($offset <= 0 || $offset >= strlen($this->bytes))) {
                $this->issues[] = "Bad $label offset: $offset";
            }
        }
    }

    private function readNames(): void
    {
        $count = (int)$this->header['nameCount'];
        $offset = (int)$this->header['nameOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) {
            return;
        }
        $r = $this->tableReader($offset);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < $count; $i++) {
            $entryOffset = $r->tell();
            $name = $r->fstring();
            $nonCaseHash = null;
            $caseHash = null;
            if ($version >= self::VER_NAME_HASHES_SERIALIZED && $r->remaining() >= 4) {
                $nonCaseHash = $r->u16();
                $caseHash = $r->u16();
            }
            $this->names[] = ['index' => $i, 'name' => $name, 'offset' => $entryOffset, 'nonCaseHash' => $nonCaseHash, 'caseHash' => $caseHash];
        }
    }

    private function readFName(UE4BinaryReader $r): array
    {
        $idx = $r->i32();
        $num = $r->i32();
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
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) {
            return;
        }
        $r = $this->tableReader($offset);
        for ($i = 0; $i < $count; $i++) {
            $start = $r->tell();
            $classPackage = $this->readFName($r);
            $className = $this->readFName($r);
            $outerIndex = $r->i32();
            $objectName = $this->readFName($r);
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
            ];
        }
    }

    private function readExports(): void
    {
        $count = (int)$this->header['exportCount'];
        $offset = (int)$this->header['exportOffset'];
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) {
            return;
        }
        $r = $this->tableReader($offset);
        $version = (int)$this->header['version'];
        for ($i = 0; $i < $count; $i++) {
            $start = $r->tell();
            $classIndex = $r->i32();
            $superIndex = $r->i32();
            $templateIndex = 0;
            if ($version >= self::VER_TEMPLATE_INDEX_IN_COOKED_EXPORTS) {
                $templateIndex = $r->i32();
            }
            $outerIndex = $r->i32();
            $objectName = $this->readFName($r);
            $objectFlags = $r->u32();
            if ($version >= self::VER_64BIT_EXPORTMAP_SERIALSIZES) {
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
            $notForEditorGame = null;
            $isAsset = null;
            if ($version >= self::VER_LOAD_FOR_EDITOR_GAME) {
                $notForEditorGame = $r->i32() !== 0;
            }
            if ($version >= self::VER_COOKED_ASSETS_IN_EDITOR_SUPPORT) {
                $isAsset = $r->i32() !== 0;
            }
            $preload = [];
            if ($version >= self::VER_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS) {
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
                'templateIndex' => $templateIndex,
                'templateName' => $this->displayNameFromRef($templateIndex),
                'outerIndex' => $outerIndex,
                'outerName' => $this->displayNameFromRef($outerIndex),
                'objectName' => $objectName,
                'ObjectName' => $objectName,
                'objectNameText' => $this->fnameText($objectName),
                'objectFlags' => $objectFlags,
                'serialSize' => $serialSize,
                'serialOffset' => $serialOffset,
                'forcedExport' => $forcedExport,
                'notForClient' => $notForClient,
                'notForServer' => $notForServer,
                'notForEditorGame' => $notForEditorGame,
                'isAsset' => $isAsset,
                'packageGuid' => $packageGuid,
                'packageFlags' => $packageFlags,
                'preload' => $preload,
            ];
        }
    }

    private function readStringAssetReferences(): void
    {
        $count = (int)($this->header['stringAssetReferencesCount'] ?? 0);
        $offset = (int)($this->header['stringAssetReferencesOffset'] ?? 0);
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) {
            return;
        }

        try {
            $r = $this->tableReader($offset);
            for ($i = 0; $i < $count; $i++) {
                $entryOffset = $r->tell();
                $path = trim($r->fstring());
                if ($path !== '' && strlen($path) <= 1000) {
                    $this->stringAssetReferences[] = ['index' => $i, 'offset' => $entryOffset, 'path' => $path, 'source' => 'summary_string_asset_reference'];
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
        if ($count <= 0 || $offset <= 0 || $offset >= strlen($this->bytes)) {
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
        return strtoupper(trim(chunk_split(bin2hex(substr($this->bytes, $offset, $size)), 2, ' ')));
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
