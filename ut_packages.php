<?php
declare(strict_types=1);

/**
 * PHP port of the main public model from ut_packages.pas.
 *
 * This file mirrors the Delphi unit's package/object/property concepts in PHP.
 * It uses TUnrealPackage.php as the binary package reader, then exposes a
 * TUTPackage/TUTObject/TUTProperty style API comparable to the Pascal unit.
 */

require_once __DIR__ . '/TUnrealPackage.php';

// Package flags
const PKG_AllowDownload = 0x0001;
const PKG_ClientOptional = 0x0002;
const PKG_ServerSideOnly = 0x0004;
const PKG_BrokenLinks = 0x0008;
const PKG_Unsecure = 0x0010;
const PKG_Encrypted = 0x0020;
const PKG_Need = 0x8000;

// Extra Package Flags
const PKG_OTHER_FLAGS_AAOEncrypted = 0x00000001;
const PKG_OTHER_FLAGS_Lineage2Encrypted = 0x00000002;

// Object flags
const RF_Transactional = 0x00000001;
const RF_Unreachable = 0x00000002;
const RF_Public = 0x00000004;
const RF_TagImp = 0x00000008;
const RF_TagExp = 0x00000010;
const RF_SourceModified = 0x00000020;
const RF_TagGarbage = 0x00000040;
const RF_Private = 0x00000080;
const RF_Unk_00000100 = 0x00000100;
const RF_NeedLoad = 0x00000200;
const RF_HighlightedName = 0x00000400;
const RF_InSingularFunc = 0x00000800;
const RF_Suppress = 0x00001000;
const RF_InEndState = 0x00002000;
const RF_Transient = 0x00004000;
const RF_PreLoading = 0x00008000;
const RF_LoadForClient = 0x00010000;
const RF_LoadForServer = 0x00020000;
const RF_LoadForEdit = 0x00040000;
const RF_Standalone = 0x00080000;
const RF_NotForClient = 0x00100000;
const RF_NotForServer = 0x00200000;
const RF_NotForEdit = 0x00400000;
const RF_Destroyed = 0x00800000;
const RF_NeedPostLoad = 0x01000000;
const RF_HasStack = 0x02000000;
const RF_Native = 0x04000000;
const RF_Marked = 0x08000000;
const RF_ErrorShutdown = 0x10000000;
const RF_DebugPostLoad = 0x20000000;
const RF_DebugSerialize = 0x40000000;
const RF_DebugDestroy = 0x80000000;

// Property types
const otNone = 0;
const otByte = 1;
const otInt = 2;
const otBool = 3;
const otFloat = 4;
const otObject = 5;
const otName = 6;
const otString = 7;
const otClass = 8;
const otArray = 9;
const otStruct = 10;
const otVector = 11;
const otRotator = 12;
const otStr = 13;
const otMap = 14;
const otFixedArray = 15;
const otExtendedValue = 0x00000100;
const otBuffer = otExtendedValue | 0;
const otWord = otExtendedValue | 1;

// Native function formats
const nffFunction = 0;
const nffPreOperator = 1;
const nffPostOperator = 2;
const nffOperator = 3;

// Game hints
const UTPGH_NotSpecified = 0;
const UTPGH_Unreal = 1;
const UTPGH_UnrealTournament = 2;
const UTPGH_TheWheelOfTime = 3;
const UTPGH_KlingonHonorGuard = 4;
const UTPGH_Rune = 5;
const UTPGH_Undying = 6;
const UTPGH_DeusEx = 7;
const UTPGH_XComEnforcer = 8;
const UTPGH_DeepSpaceNine = 9;
const UTPGH_NerfArenaBlast = 10;
const UTPGH_UnrealTournament2003 = 11;
const UTPGH_ArmyOperations = 12;
const UTPGH_HarryPotterSorcerersStone = 13;
const UTPGH_HarryPotterChamberSecrets = 14;
const UTPGH_Unreal2 = 15;
const UTPGH_SplinterCell = 16;
const UTPGH_Devastation = 17;
const UTPGH_Rainbow6RavenShield = 18;
const UTPGH_UnrealChampionship = 19;
const UTPGH_Lineage2 = 20;
const UTPGH_Postal2 = 21;
const UTPGH_UnrealEngine2Runtime = 22;
const UTPGH_DeusExInvisibleWar = 23;
const UTPGH_DesertThunder = 24;
const UTPGH_UnrealTournament2004 = 25;
const UTPGH_XIII = 26;
const UTPGH_TribesVengeance = 27;
const UTPGH_MenOfValor = 28;

class EInvalidUTPackage extends RuntimeException {}
class EProcessingUTPackage extends RuntimeException {}
class EReadingUTProperty extends RuntimeException {}
class EUnknownUTOpcode extends RuntimeException {}
class EInvalidUTNativeIndex extends RuntimeException {}
class EUnknownObjectFormat extends RuntimeException {}

function UTPackage_GameHintStrings(): array
{
    return [
        UTPGH_NotSpecified => 'Unknown',
        UTPGH_Unreal => 'Unreal',
        UTPGH_UnrealTournament => 'Unreal Tournament',
        UTPGH_TheWheelOfTime => 'The Wheel of Time',
        UTPGH_KlingonHonorGuard => 'Klingon Honor Guard',
        UTPGH_Rune => 'Rune',
        UTPGH_Undying => "Clive Barker's Undying",
        UTPGH_DeusEx => 'Deus Ex',
        UTPGH_XComEnforcer => 'XCom Enforcer',
        UTPGH_DeepSpaceNine => 'Star Trek: Deep Space Nine',
        UTPGH_NerfArenaBlast => 'Nerf Arena Blast',
        UTPGH_UnrealTournament2003 => 'Unreal Tournament 2003',
        UTPGH_ArmyOperations => 'Army Operations',
        UTPGH_HarryPotterSorcerersStone => "Harry Potter and the Sorcerer's Stone",
        UTPGH_HarryPotterChamberSecrets => 'Harry Potter and the Chamber of Secrets',
        UTPGH_Unreal2 => 'Unreal 2',
        UTPGH_SplinterCell => 'Splinter Cell',
        UTPGH_Devastation => 'Devastation',
        UTPGH_Rainbow6RavenShield => 'Rainbow Six RavenShield',
        UTPGH_UnrealChampionship => 'Unreal Championship',
        UTPGH_Lineage2 => 'Lineage 2',
        UTPGH_Postal2 => 'Postal 2',
        UTPGH_UnrealEngine2Runtime => 'Unreal Engine 2 Runtime',
        UTPGH_DeusExInvisibleWar => 'Deus Ex: Invisible War',
        UTPGH_DesertThunder => 'Desert Thunder',
        UTPGH_UnrealTournament2004 => 'Unreal Tournament 2004',
        UTPGH_XIII => 'XIII',
        UTPGH_TribesVengeance => 'Tribes Vengeance',
        UTPGH_MenOfValor => 'Men Of Valor',
    ];
}

function UTDecodeBitmask(int $flags, array $map): array
{
    $out = [];
    foreach ($map as $bit => $name) {
        if (($flags & (int)$bit) !== 0) {
            $out[] = $name;
        }
    }
    return $out;
}

function UTPackageFlagNames(int $flags): array
{
    return UTDecodeBitmask($flags, [
        PKG_AllowDownload => 'PKG_AllowDownload',
        PKG_ClientOptional => 'PKG_ClientOptional',
        PKG_ServerSideOnly => 'PKG_ServerSideOnly',
        PKG_BrokenLinks => 'PKG_BrokenLinks',
        PKG_Unsecure => 'PKG_Unsecure',
        PKG_Encrypted => 'PKG_Encrypted',
        PKG_Need => 'PKG_Need',
    ]);
}

function UTObjectFlagNames(int $flags): array
{
    return UTDecodeBitmask($flags, [
        RF_Transactional => 'RF_Transactional', RF_Unreachable => 'RF_Unreachable', RF_Public => 'RF_Public', RF_TagImp => 'RF_TagImp',
        RF_TagExp => 'RF_TagExp', RF_SourceModified => 'RF_SourceModified', RF_TagGarbage => 'RF_TagGarbage', RF_Private => 'RF_Private',
        RF_Unk_00000100 => 'RF_Unk_00000100', RF_NeedLoad => 'RF_NeedLoad', RF_HighlightedName => 'RF_HighlightedName',
        RF_InSingularFunc => 'RF_InSingularFunc', RF_Suppress => 'RF_Suppress', RF_InEndState => 'RF_InEndState', RF_Transient => 'RF_Transient',
        RF_PreLoading => 'RF_PreLoading', RF_LoadForClient => 'RF_LoadForClient', RF_LoadForServer => 'RF_LoadForServer', RF_LoadForEdit => 'RF_LoadForEdit',
        RF_Standalone => 'RF_Standalone', RF_NotForClient => 'RF_NotForClient', RF_NotForServer => 'RF_NotForServer', RF_NotForEdit => 'RF_NotForEdit',
        RF_Destroyed => 'RF_Destroyed', RF_NeedPostLoad => 'RF_NeedPostLoad', RF_HasStack => 'RF_HasStack', RF_Native => 'RF_Native',
        RF_Marked => 'RF_Marked', RF_ErrorShutdown => 'RF_ErrorShutdown', RF_DebugPostLoad => 'RF_DebugPostLoad', RF_DebugSerialize => 'RF_DebugSerialize',
        RF_DebugDestroy => 'RF_DebugDestroy',
    ]);
}

function UTPropertyTypeName(int $type): string
{
    $names = [
        otNone => 'None', otByte => 'Byte', otInt => 'Int', otBool => 'Bool', otFloat => 'Float', otObject => 'Object',
        otName => 'Name', otString => 'String', otClass => 'Class', otArray => 'Array', otStruct => 'Struct', otVector => 'Vector',
        otRotator => 'Rotator', otStr => 'Str', otMap => 'Map', otFixedArray => 'FixedArray', otBuffer => 'Buffer', otWord => 'Word',
    ];
    return $names[$type] ?? 'Unknown(' . $type . ')';
}

final class TNativeFunction
{
    public int $Index;
    public int|string $Format;
    public int $OperatorPrecedence;
    public string $Name;

    public function __construct(int $index, int|string $format, int $operatorPrecedence, string $name)
    {
        $this->Index = $index;
        $this->Format = $format;
        $this->OperatorPrecedence = $operatorPrecedence;
        $this->Name = $name;
    }

    public static function fromArray(array $row): self
    {
        return new self((int)($row['index'] ?? $row['Index'] ?? 0), $row['format'] ?? $row['Format'] ?? nffFunction, (int)($row['operatorPrecedence'] ?? $row['OperatorPrecedence'] ?? 0), (string)($row['name'] ?? $row['Name'] ?? ''));
    }

    public function toArray(): array
    {
        return ['Index' => $this->Index, 'Format' => $this->Format, 'OperatorPrecedence' => $this->OperatorPrecedence, 'Name' => $this->Name];
    }
}

class TUTProperty
{
    protected bool $FIsInitialized = false;
    protected ?TUTPackage $FOwner = null;
    protected ?TUTObject $FOwnerObject = null;
    protected string $FName = '';
    protected int $FArrayIndex = 0;
    protected int $FPropertyType = otNone;
    protected mixed $FValue = null;
    protected string $FTypeName = '';

    public function SetOwnerObject(TUTObject $ownerobject): void { $this->FOwnerObject = $ownerobject; }

    public function SetProperty(TUTPackage $Owner, string $n, int $i, int $t, mixed $value, int $valuesize = 0, string $_typename = ''): void
    {
        $this->FOwner = $Owner;
        $this->FName = $n;
        $this->FArrayIndex = $i;
        $this->FPropertyType = $t;
        $this->FValue = $value;
        $this->FTypeName = $_typename;
        $this->FIsInitialized = true;
    }

    public function Name(): string { return $this->FName; }
    public function ArrayIndex(): int { return $this->FArrayIndex; }
    public function PropertyType(): int { return $this->FPropertyType; }
    public function Value(): mixed { return $this->FValue; }
    public function GenericTypeName(): string { return $this->FTypeName; }
    public function SpecificTypeName(): string { return $this->GetTypeName(); }
    public function Description(): string { return $this->GetDescription(); }
    public function DescriptiveValue(): string { return $this->GetDescriptiveValue(); }
    public function ArrayTypeLength(): int { return is_array($this->FValue) ? count($this->FValue) : 1; }
    public function ValueCount(): int { return is_array($this->FValue) ? count($this->FValue) : 1; }

    protected function GetTypeName(): string { return $this->FTypeName !== '' ? $this->FTypeName : $this->GetValueTypeName($this->FPropertyType); }
    protected function GetDescription(): string { return $this->FName . '=' . $this->GetDescriptiveValue(); }
    protected function GetDescriptiveValue(): string { return is_scalar($this->FValue) || $this->FValue === null ? (string)$this->FValue : json_encode($this->FValue, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
    public function GetValueTypeName(int $t): string { return UTPropertyTypeName($t); }

    public function GetValue(int $ai, int $i, string &$valuename, mixed &$value, string &$descriptivevalue, int &$valuetype, string &$valuetypename): void
    {
        $valuename = $this->FName;
        $value = is_array($this->FValue) ? ($this->FValue[$i] ?? null) : $this->FValue;
        $descriptivevalue = is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $valuetype = $this->FPropertyType;
        $valuetypename = $this->GetValueTypeName($valuetype);
    }

    public function toArray(): array
    {
        return ['Name' => $this->FName, 'ArrayIndex' => $this->FArrayIndex, 'PropertyType' => $this->FPropertyType, 'TypeName' => $this->GetTypeName(), 'Value' => $this->FValue, 'Description' => $this->GetDescription()];
    }
}

class TUTPropertyList implements IteratorAggregate, Countable
{
    /** @var TUTProperty[] */
    private array $FProperties = [];

    public function New(): TUTProperty
    {
        $p = new TUTProperty();
        $this->FProperties[] = $p;
        return $p;
    }

    public function Add(TUTProperty $p): void { $this->FProperties[] = $p; }
    public function Clear(): void { $this->FProperties = []; }
    public function Count(): int { return count($this->FProperties); }
    public function count(): int { return $this->Count(); }
    public function PropertyByPosition(int $i): ?TUTProperty { return $this->FProperties[$i] ?? null; }

    public function PropertyByName(string $name): ?TUTProperty
    {
        foreach ($this->FProperties as $p) {
            if (strcasecmp($p->Name(), $name) === 0) return $p;
        }
        return null;
    }

    public function PropertyByNameValue(string $name): mixed { return $this->PropertyByName($name)?->Value(); }
    public function PropertyByNameValueDefault(string $name, mixed $adefault): mixed { return $this->PropertyByName($name)?->Value() ?? $adefault; }
    public function PropertyByPositionValue(int $i): mixed { return $this->PropertyByPosition($i)?->Value(); }
    public function PropertyByPositionValueDefault(int $i, mixed $adefault): mixed { return $this->PropertyByPosition($i)?->Value() ?? $adefault; }

    public function Descriptions(): string
    {
        return implode("\n", array_map(fn(TUTProperty $p) => $p->Description(), $this->FProperties));
    }

    public function FixArrayIndices(): void
    {
        // Delphi version repairs array property index display. PHP stores index directly when read.
    }

    public function getIterator(): Traversable { return new ArrayIterator($this->FProperties); }
    public function toArray(): array { return array_map(fn(TUTProperty $p) => $p->toArray(), $this->FProperties); }
}

class TUTPackage
{
    private string $filename;
    private object $reader;
    private array $header = [];
    private array $names = [];
    private array $imports = [];
    private array $exports = [];
    /** @var array<int,TUTObject> */
    private array $objects = [];
    private int $gameHint = UTPGH_NotSpecified;

    public function __construct(string $filename, int $gameHint = UTPGH_NotSpecified)
    {
        if (!is_file($filename)) throw new EInvalidUTPackage('File not found: ' . $filename);
        if (!is_readable($filename)) throw new EInvalidUTPackage('File is not readable: ' . $filename);
        $this->filename = $filename;
        $this->gameHint = $gameHint;
        $this->reader = TPackageReader::open($filename);
        if (method_exists($this->reader, 'annotateTablesWithText')) $this->reader->annotateTablesWithText();
        $this->header = $this->reader->getHeader();
        $this->names = $this->reader->getNames();
        $this->imports = $this->reader->getImports();
        $this->exports = $this->reader->getExports();
    }

    public function Filename(): string { return $this->filename; }
    public function Header(): array { return $this->header; }
    public function Names(): array { return $this->names; }
    public function Imports(): array { return $this->imports; }
    public function Exports(): array { return $this->exports; }
    public function Reader(): object { return $this->reader; }
    public function GameHint(): int { return $this->gameHint; }
    public function GameHintName(): string { $s = UTPackage_GameHintStrings(); return $s[$this->gameHint] ?? 'Unknown'; }

    public function NameCount(): int { return count($this->names); }
    public function ImportCount(): int { return count($this->imports); }
    public function ExportCount(): int { return count($this->exports); }
    public function PackageFlags(): int { return (int)($this->header['packageFlags'] ?? $this->header['pkgFlags'] ?? 0); }
    public function PackageFlagNames(): array { return UTPackageFlagNames($this->PackageFlags()); }

    public function Name(int $index): string
    {
        if ($index < 0 || !isset($this->names[$index])) return '';
        $row = (array)$this->names[$index];
        $raw = isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row;
        return (string)($row['name'] ?? $raw['name'] ?? '');
    }

    public function Object(int $exportedIndex): TUTObject
    {
        if (!isset($this->exports[$exportedIndex])) throw new EProcessingUTPackage('Invalid exported object index: ' . $exportedIndex);
        return $this->objects[$exportedIndex] ??= new TUTObject($this, $exportedIndex);
    }

    public function Objects(): array
    {
        $out = [];
        for ($i = 0; $i < count($this->exports); $i++) $out[$i] = $this->Object($i);
        return $out;
    }

    public function ExportRow(int $i): array { return isset($this->exports[$i]) ? (array)$this->exports[$i] : []; }
    public function ImportRow(int $i): array { return isset($this->imports[$i]) ? (array)$this->imports[$i] : []; }

    public function RefName(int $ref): string
    {
        if ($ref === 0) return '';
        if ($ref > 0) {
            $i = $ref - 1;
            $row = $this->ExportRow($i);
            $idx = $this->rowInt($row, ['objectName', 'ObjectName', 'nameIndex', 'NameIndex'], -1);
            $text = $this->rowText($row, ['objectNameText', 'ObjectNameText', 'name', 'Name']);
            return $text !== '' && !is_numeric($text) ? $text : $this->Name($idx);
        }
        $i = -$ref - 1;
        $row = $this->ImportRow($i);
        $idx = $this->rowInt($row, ['objectName', 'ObjectName', 'nameIndex', 'NameIndex'], -1);
        $text = $this->rowText($row, ['objectNameText', 'ObjectNameText', 'name', 'Name']);
        return $text !== '' && !is_numeric($text) ? $text : $this->Name($idx);
    }

    public function RefPath(int $ref): string
    {
        $parts = [];
        $seen = [];
        for ($depth = 0; $ref !== 0 && $depth < 64; $depth++) {
            if (isset($seen[$ref])) { $parts[] = '__CYCLE__'; break; }
            $seen[$ref] = true;
            $name = $this->RefName($ref);
            if ($name !== '') $parts[] = $name;
            if ($ref > 0) $ref = $this->rowInt($this->ExportRow($ref - 1), ['outerIndex', 'OuterIndex', 'packageIndex', 'PackageIndex', 'outer'], 0);
            else $ref = $this->rowInt($this->ImportRow(-$ref - 1), ['outerIndex', 'OuterIndex', 'packageIndex', 'PackageIndex', 'outer'], 0);
        }
        return implode('.', array_reverse(array_filter($parts)));
    }

    public function rowInt(array $row, array $keys, int $default = 0): int
    {
        $raw = isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row;
        foreach ($keys as $k) {
            if (isset($row[$k]) && is_numeric($row[$k])) return (int)$row[$k];
            if (isset($raw[$k]) && is_numeric($raw[$k])) return (int)$raw[$k];
        }
        return $default;
    }

    public function rowText(array $row, array $keys, string $default = ''): string
    {
        $view = isset($row['view']) && is_array($row['view']) ? $row['view'] : [];
        $raw = isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row;
        foreach ($keys as $k) {
            foreach ([$view, $row, $raw] as $src) {
                if (!array_key_exists($k, $src)) continue;
                $v = $src[$k];
                if (is_string($v) || is_numeric($v)) return (string)$v;
                if (is_array($v)) foreach (['text', 'base', 'name'] as $tk) if (!empty($v[$tk])) return (string)$v[$tk];
            }
        }
        return $default;
    }

    public function toArray(): array
    {
        return ['filename' => $this->filename, 'header' => $this->header, 'nameCount' => $this->NameCount(), 'importCount' => $this->ImportCount(), 'exportCount' => $this->ExportCount(), 'packageFlags' => $this->PackageFlagNames()];
    }
}

class TUTObject
{
    protected TUTPackage $FOwner;
    protected int $FExportedIndex;
    protected bool $FHasBeenRead = false;
    protected bool $FHasBeenInterpreted = false;
    protected int $FReadCount = 0;
    protected TUTPropertyList $FProperties;
    protected string $Buffer = '';
    protected int $FExtraDataCount = 0;

    public function __construct(TUTPackage $owner, int $exportedindex)
    {
        $this->FOwner = $owner;
        $this->FExportedIndex = $exportedindex;
        $this->FProperties = new TUTPropertyList();
    }

    public function Owner(): TUTPackage { return $this->FOwner; }
    public function ExportedIndex(): int { return $this->FExportedIndex; }
    public function Row(): array { return $this->FOwner->ExportRow($this->FExportedIndex); }
    public function HasBeenRead(): bool { return $this->FHasBeenRead; }
    public function HasBeenInterpreted(): bool { return $this->FHasBeenInterpreted; }
    public function Properties(): TUTPropertyList { if (!$this->FHasBeenRead) $this->ReadObject(true); return $this->FProperties; }
    public function ExtraDataCount(): int { return $this->FExtraDataCount; }
    public function Buffer(): string { if (!$this->FHasBeenRead) $this->ReadObject(false); return $this->Buffer; }

    public function UTClassIndex(): int { return $this->FOwner->rowInt($this->Row(), ['classIndex', 'ClassIndex', 'class'], 0); }
    public function UTSuperIndex(): int { return $this->FOwner->rowInt($this->Row(), ['superIndex', 'SuperIndex', 'super'], 0); }
    public function UTGroupIndex(): int { return $this->FOwner->rowInt($this->Row(), ['outerIndex', 'OuterIndex', 'packageIndex', 'PackageIndex', 'outer'], 0); }
    public function UTObjectIndex(): int { return $this->FOwner->rowInt($this->Row(), ['objectName', 'ObjectName', 'nameIndex', 'NameIndex'], -1); }
    public function UTSerialOffset(): int { return $this->FOwner->rowInt($this->Row(), ['serialOffset', 'SerialOffset', 'offset'], 0); }
    public function UTSerialSize(): int { return $this->FOwner->rowInt($this->Row(), ['serialSize', 'SerialSize', 'size'], 0); }
    public function UTFlags(): int { return $this->FOwner->rowInt($this->Row(), ['objectFlags', 'ObjectFlags', 'flags'], 0); }
    public function UTObjectName(): string { $n = $this->FOwner->rowText($this->Row(), ['objectNameText', 'ObjectNameText', 'name', 'Name']); return $n !== '' && !is_numeric($n) ? $n : $this->FOwner->Name($this->UTObjectIndex()); }
    public function UTClassName(): string { return $this->FOwner->RefName($this->UTClassIndex()); }
    public function UTGroupName(): string { return $this->FOwner->RefPath($this->UTGroupIndex()); }
    public function UTSuperName(): string { return $this->FOwner->RefName($this->UTSuperIndex()); }
    public function UTFullName(): string { $g = $this->UTGroupName(); return ($g !== '' ? $g . '.' : '') . $this->UTObjectName(); }

    public function ReadObject(bool $interpret = true): void
    {
        $this->FReadCount++;
        $this->InitializeObject();
        $this->FHasBeenRead = true;
        if ($interpret) {
            $this->InterpretObject();
            $this->FHasBeenInterpreted = true;
        }
    }

    protected function InitializeObject(): void
    {
        $file = $this->FOwner->Filename();
        $offset = $this->UTSerialOffset();
        $size = $this->UTSerialSize();
        if ($size <= 0) { $this->Buffer = ''; return; }
        $fh = fopen($file, 'rb');
        if (!$fh) throw new EProcessingUTPackage('Unable to open package file: ' . $file);
        try {
            if (fseek($fh, $offset, SEEK_SET) !== 0) throw new EProcessingUTPackage('Unable to seek to object offset ' . $offset);
            $data = fread($fh, $size);
            if ($data === false) throw new EProcessingUTPackage('Unable to read object data');
            $this->Buffer = $data;
        } finally {
            fclose($fh);
        }
    }

    protected function InterpretObject(): void
    {
        $this->ReadProperties();
    }

    protected function ReadProperties(): void
    {
        if (method_exists($this->FOwner->Reader(), 'getExportProperties')) {
            try {
                $props = (array)($this->FOwner->Reader()->getExportProperties($this->FExportedIndex) ?? []);
                foreach ($props as $name => $value) {
                    $p = new TUTProperty();
                    $p->SetProperty($this->FOwner, (string)$name, 0, otNone, $value, is_string($value) ? strlen($value) : 0, '');
                    $p->SetOwnerObject($this);
                    $this->FProperties->Add($p);
                }
            } catch (Throwable $t) {
                throw new EReadingUTProperty($t->getMessage(), 0, $t);
            }
        }
    }

    public function ReleaseObject(): void
    {
        $this->Buffer = '';
        $this->FProperties->Clear();
        $this->FHasBeenRead = false;
        $this->FHasBeenInterpreted = false;
    }

    public function RawSaveToFile(string $filename): void { file_put_contents($filename, $this->Buffer()); }
    public function RawSaveToStream($stream): void { fwrite($stream, $this->Buffer()); }
    public function CheckArrayLength(int $size): int { return max(0, $size); }

    public function toArray(): array
    {
        return ['ExportedIndex' => $this->FExportedIndex, 'Name' => $this->UTObjectName(), 'Class' => $this->UTClassName(), 'Group' => $this->UTGroupName(), 'Super' => $this->UTSuperName(), 'Size' => $this->UTSerialSize(), 'Offset' => $this->UTSerialOffset(), 'Flags' => UTObjectFlagNames($this->UTFlags())];
    }
}

class TUTObjectClassField extends TUTObject { public function SuperField(): int { return $this->UTSuperIndex(); } public function Next(): int { return 0; } }
class TUTObjectClassEnum extends TUTObjectClassField { public function Count(): int { return 0; } public function EnumValue(int $i): int { return 0; } public function EnumName(int $i): string { return ''; } public function GetDeclaration(): string { return 'enum ' . $this->UTObjectName(); } }
class TUTObjectClassConst extends TUTObjectClassField { public function Value(): string { return (string)$this->Properties()->PropertyByNameValueDefault('Value', ''); } public function GetDeclaration(): string { return 'const ' . $this->UTObjectName() . '=' . $this->Value(); } }
class TUTObjectClassProperty extends TUTObjectClassField { public function GenericTypeName(): string { return 'property'; } public function GetFlags(string $cn): string { return implode(', ', UTObjectFlagNames($this->UTFlags())); } public function GetDeclaration(string $context = '', string $cn = ''): string { return $this->GenericTypeName() . ' ' . $this->UTObjectName(); } }
class TUTObjectClassByteProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'byte'; } }
class TUTObjectClassIntProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'int'; } }
class TUTObjectClassBoolProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'bool'; } }
class TUTObjectClassFloatProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'float'; } }
class TUTObjectClassNameProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'name'; } }
class TUTObjectClassStrProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'str'; } }
class TUTObjectClassStringProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'string'; } }
class TUTObjectClassObjectProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'object'; } }
class TUTObjectClassClassProperty extends TUTObjectClassObjectProperty { public function GenericTypeName(): string { return 'class'; } }
class TUTObjectClassFixedArrayProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'fixedarray'; } }
class TUTObjectClassArrayProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'array'; } }
class TUTObjectClassMapProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'map'; } }
class TUTObjectClassStructProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'struct'; } }
class TUTObjectClassDelegateProperty extends TUTObjectClassProperty { public function GenericTypeName(): string { return 'delegate'; } }

function Register2DClasses(): void {}
function Register3DClasses(): void {}
function RegisterSoundClasses(): void {}
function RegisterCodeClasses(): void {}
function RegisterOtherClasses(): void {}
function RegisterAllClasses(): void { Register2DClasses(); Register3DClasses(); RegisterSoundClasses(); RegisterCodeClasses(); RegisterOtherClasses(); }

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');
    $file = trim((string)($_GET['file'] ?? ''));
    if ($file === '') {
        echo json_encode(['usage' => 'Package summary: ut_packages.php?file=/full/path/to/package.utx', 'classes' => ['TUTPackage', 'TUTObject', 'TUTProperty', 'TUTPropertyList']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
    try {
        $pkg = new TUTPackage($file);
        echo json_encode($pkg->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $t) {
        http_response_code(500);
        echo json_encode(['error' => $t->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
