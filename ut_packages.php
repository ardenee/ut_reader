<?php
/**
 * PHP companion for ut_packages.pas.
 *
 * This is not a full Delphi class-port of the original unit. The original
 * ut_packages.pas contains a large object model and parser implementation.
 * This PHP file provides the portable constants, enum values, flag helpers,
 * and lightweight data containers needed by the PHP native-function table
 * companions and browser tooling.
 *
 * Original Pascal source: ut_packages.pas
 */

// Package flags
const PKG_AllowDownload = 0x0001;
const PKG_ClientOptional = 0x0002;
const PKG_ServerSideOnly = 0x0004;
const PKG_BrokenLinks = 0x0008;
const PKG_Unsecure = 0x0010;
const PKG_Encrypted = 0x0020;
const PKG_Need = 0x8000;

// Extra package flags
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

// Game hints. Numeric values follow Pascal enum order.
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

function ut_packages_game_hint_strings(): array
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
        UTPGH_HarryPotterSorcerersStone => 'Harry Potter and the Sorcerer\'s Stone',
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

function ut_packages_game_hint_name(int $hint): string
{
    $names = ut_packages_game_hint_strings();
    return $names[$hint] ?? 'Unknown';
}

function ut_packages_native_format_names(): array
{
    return [
        nffFunction => 'nffFunction',
        nffPreOperator => 'nffPreOperator',
        nffPostOperator => 'nffPostOperator',
        nffOperator => 'nffOperator',
    ];
}

function ut_packages_native_format_name(int|string $format): string
{
    if (is_string($format)) {
        return $format;
    }
    $names = ut_packages_native_format_names();
    return $names[$format] ?? 'nffUnknown';
}

function ut_packages_property_type_names(): array
{
    return [
        otNone => 'None',
        otByte => 'Byte',
        otInt => 'Int',
        otBool => 'Bool',
        otFloat => 'Float',
        otObject => 'Object',
        otName => 'Name',
        otString => 'String',
        otClass => 'Class',
        otArray => 'Array',
        otStruct => 'Struct',
        otVector => 'Vector',
        otRotator => 'Rotator',
        otStr => 'Str',
        otMap => 'Map',
        otFixedArray => 'FixedArray',
        otBuffer => 'Buffer',
        otWord => 'Word',
    ];
}

function ut_packages_property_type_name(int $type): string
{
    $names = ut_packages_property_type_names();
    return $names[$type] ?? ('Unknown(' . $type . ')');
}

function ut_packages_decode_package_flags(int $flags): array
{
    $map = [
        PKG_AllowDownload => 'PKG_AllowDownload',
        PKG_ClientOptional => 'PKG_ClientOptional',
        PKG_ServerSideOnly => 'PKG_ServerSideOnly',
        PKG_BrokenLinks => 'PKG_BrokenLinks',
        PKG_Unsecure => 'PKG_Unsecure',
        PKG_Encrypted => 'PKG_Encrypted',
        PKG_Need => 'PKG_Need',
    ];
    return ut_packages_decode_bitmask($flags, $map);
}

function ut_packages_decode_extra_package_flags(int $flags): array
{
    $map = [
        PKG_OTHER_FLAGS_AAOEncrypted => 'PKG_OTHER_FLAGS_AAOEncrypted',
        PKG_OTHER_FLAGS_Lineage2Encrypted => 'PKG_OTHER_FLAGS_Lineage2Encrypted',
    ];
    return ut_packages_decode_bitmask($flags, $map);
}

function ut_packages_decode_object_flags(int $flags): array
{
    $map = [
        RF_Transactional => 'RF_Transactional',
        RF_Unreachable => 'RF_Unreachable',
        RF_Public => 'RF_Public',
        RF_TagImp => 'RF_TagImp',
        RF_TagExp => 'RF_TagExp',
        RF_SourceModified => 'RF_SourceModified',
        RF_TagGarbage => 'RF_TagGarbage',
        RF_Private => 'RF_Private',
        RF_Unk_00000100 => 'RF_Unk_00000100',
        RF_NeedLoad => 'RF_NeedLoad',
        RF_HighlightedName => 'RF_HighlightedName',
        RF_InSingularFunc => 'RF_InSingularFunc',
        RF_Suppress => 'RF_Suppress',
        RF_InEndState => 'RF_InEndState',
        RF_Transient => 'RF_Transient',
        RF_PreLoading => 'RF_PreLoading',
        RF_LoadForClient => 'RF_LoadForClient',
        RF_LoadForServer => 'RF_LoadForServer',
        RF_LoadForEdit => 'RF_LoadForEdit',
        RF_Standalone => 'RF_Standalone',
        RF_NotForClient => 'RF_NotForClient',
        RF_NotForServer => 'RF_NotForServer',
        RF_NotForEdit => 'RF_NotForEdit',
        RF_Destroyed => 'RF_Destroyed',
        RF_NeedPostLoad => 'RF_NeedPostLoad',
        RF_HasStack => 'RF_HasStack',
        RF_Native => 'RF_Native',
        RF_Marked => 'RF_Marked',
        RF_ErrorShutdown => 'RF_ErrorShutdown',
        RF_DebugPostLoad => 'RF_DebugPostLoad',
        RF_DebugSerialize => 'RF_DebugSerialize',
        RF_DebugDestroy => 'RF_DebugDestroy',
    ];
    return ut_packages_decode_bitmask($flags, $map);
}

function ut_packages_decode_bitmask(int $flags, array $map): array
{
    $out = [];
    foreach ($map as $bit => $name) {
        if (($flags & (int)$bit) !== 0) {
            $out[] = $name;
        }
    }
    return $out;
}

final class UTNativeFunction
{
    public int $index;
    public int|string $format;
    public int $operatorPrecedence;
    public string $name;
    public string $source;

    public function __construct(int $index, int|string $format, int $operatorPrecedence, string $name, string $source = '')
    {
        $this->index = $index;
        $this->format = $format;
        $this->operatorPrecedence = $operatorPrecedence;
        $this->name = $name;
        $this->source = $source;
    }

    public static function fromArray(array $row): self
    {
        return new self(
            (int)($row['index'] ?? 0),
            $row['format'] ?? nffFunction,
            (int)($row['operatorPrecedence'] ?? 0),
            (string)($row['name'] ?? ''),
            (string)($row['source'] ?? '')
        );
    }

    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'format' => $this->format,
            'formatName' => ut_packages_native_format_name($this->format),
            'operatorPrecedence' => $this->operatorPrecedence,
            'name' => $this->name,
            'source' => $this->source,
        ];
    }
}

final class UTPropertyValue
{
    public string $name;
    public int $arrayIndex;
    public int $propertyType;
    public mixed $value;
    public string $typeName;

    public function __construct(string $name, int $arrayIndex, int $propertyType, mixed $value, string $typeName = '')
    {
        $this->name = $name;
        $this->arrayIndex = $arrayIndex;
        $this->propertyType = $propertyType;
        $this->value = $value;
        $this->typeName = $typeName !== '' ? $typeName : ut_packages_property_type_name($propertyType);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'arrayIndex' => $this->arrayIndex,
            'propertyType' => $this->propertyType,
            'propertyTypeName' => ut_packages_property_type_name($this->propertyType),
            'value' => $this->value,
            'typeName' => $this->typeName,
        ];
    }
}

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'packageFlags' => ut_packages_decode_package_flags(PKG_AllowDownload | PKG_ClientOptional | PKG_Need),
        'objectFlags' => ut_packages_decode_object_flags(RF_Public | RF_Native | RF_Standalone),
        'nativeFormats' => ut_packages_native_format_names(),
        'propertyTypes' => ut_packages_property_type_names(),
        'gameHints' => ut_packages_game_hint_strings(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
