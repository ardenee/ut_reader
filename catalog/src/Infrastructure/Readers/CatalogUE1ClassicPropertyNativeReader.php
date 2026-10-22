<?php
/**
 * Persistent classic UE1 UProperty family native serializer.
 *
 * Epic Unreal v1.200 Core/Src/UnProp.cpp UProperty::Serialize:101-114
 * and individual subclass Serialize; UT99 v400 UnProp.cpp:117-134.
 * This decodes the on-disk LOAD/SAVE archive only. Runtime-linked property
 * offsets, Boolean BitMask and constructor links are deliberately not invented.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicPropertyNativeReader
{
    public const KINDS = [
        'byteproperty', 'intproperty', 'boolproperty', 'floatproperty',
        'objectproperty', 'classproperty', 'nameproperty', 'stringproperty',
        'strproperty', 'structproperty', 'arrayproperty', 'fixedarrayproperty',
        'mapproperty',
    ];

    /**
     * Uses the enclosing file-bounded native archive's callbacks. The
     * parent maintains $position and supplies a safe [start,limit) read.
     * @return array<string,mixed>
     */
    public static function inspect(
        string $kind, CatalogUE1PackageReader $pkg, int $version,
        callable $read, callable $compact, callable $u32,
        int &$position, int $limit
    ): array {
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unsupported classic UE1 property serializer: '.$kind);
        }
        // The v1.200 branch uses fixed UStringProperty. Dynamic FString,
        // array, fixed-array and map property classes belong to the later
        // UT99 source, not the v1.200 class registration table.
        if ($version <= 61 && in_array($kind, [
            'strproperty', 'arrayproperty', 'fixedarrayproperty', 'mapproperty'
        ], true)) {
            throw new RuntimeException('Property class not registered by v1.200 source');
        }
        if ($version >= 68 && $kind === 'stringproperty') {
            throw new RuntimeException('Legacy fixed UStringProperty absent from UT99 source');
        }
        $reference = static function(string $field) use ($compact, $pkg): int {
            $value = $compact();
            if (($value < 0 && -$value > count($pkg->getImports()))
                || ($value > 0 && $value > count($pkg->getExports()))) {
                throw new RuntimeException('Invalid '.$field.' package reference');
            }
            return $value;
        };
        // UField::Serialize is the mandatory inherited LOAD/SAVE prefix.
        $superField = $reference('SuperField');
        $nextField = $reference('Next');
        // UProperty::Serialize persists INT ArrayDim, DWORD PropertyFlags,
        // FName Category, then WORD RepOffset only for CPF_Net (0x20).
        $arrayDimRaw = (int)$u32();
        $arrayDim = $arrayDimRaw >= 0x80000000
            ? $arrayDimRaw - 0x100000000 : $arrayDimRaw;
        if ($arrayDim <= 0) {
            throw new RuntimeException('Invalid UProperty ArrayDim');
        }
        $flags = (int)$u32();
        $categoryIndex = (int)$compact();
        $nameMap = $pkg->getNames();
        if ($categoryIndex < 0 || !isset($nameMap[$categoryIndex])) {
            throw new RuntimeException('Invalid UProperty Category FName');
        }
        $repOffset = ($flags & 0x20) !== 0
            ? (int)unpack('v', $read(2))[1] : null;
        $extra = [];
        switch ($kind) {
            case 'byteproperty':
                $extra['enum_reference'] = $reference('UByteProperty.Enum');
                break;
            case 'objectproperty':
            case 'classproperty':
                $extra['property_class_reference'] = $reference('UObjectProperty.PropertyClass');
                if ($kind === 'classproperty') {
                    $extra['meta_class_reference'] = $reference('UClassProperty.MetaClass');
                    if ($extra['meta_class_reference'] === 0) {
                        throw new RuntimeException('UClassProperty MetaClass must be non-null');
                    }
                }
                break;
            case 'stringproperty':
                // Unreal v1.200 UStringProperty is a fixed-size CHAR array.
                $raw = (int)$u32();
                $extra['string_size'] = $raw >= 0x80000000 ? $raw - 0x100000000 : $raw;
                if ($extra['string_size'] <= 0) {
                    throw new RuntimeException('Invalid UStringProperty StringSize');
                }
                break;
            case 'structproperty':
                $extra['struct_reference'] = $reference('UStructProperty.Struct');
                break;
            case 'arrayproperty':
                $extra['inner_reference'] = $reference('UArrayProperty.Inner');
                break;
            case 'fixedarrayproperty':
                $extra['inner_reference'] = $reference('UFixedArrayProperty.Inner');
                $raw = (int)$u32();
                $extra['fixed_count'] = $raw >= 0x80000000 ? $raw - 0x100000000 : $raw;
                if ($extra['fixed_count'] <= 0) {
                    throw new RuntimeException('Invalid UFixedArrayProperty.Count');
                }
                break;
            case 'mapproperty':
                $extra['key_reference'] = $reference('UMapProperty.Key');
                $extra['value_reference'] = $reference('UMapProperty.Value');
                break;
            // UInt/Float/Bool/Name/StrProperty add NO LOAD/SAVE native bytes.
            // UBoolProperty BitMask is serialized only in non-load/save archives.
        }
        return [
            'status' => $position === $limit
                ? 'native_property_record_complete' : 'native_property_has_extension',
            'property_kind' => $kind,
            'super_field_reference' => $superField,
            'next_field_reference' => $nextField,
            'array_dim' => $arrayDim,
            'property_flags' => $flags,
            'effective_property_flags' => $version <= 61
                ? ($flags & ~0x00080040) : $flags,
            'category_name_index' => $categoryIndex,
            'category_name' => (string)$nameMap[$categoryIndex]['name'],
            'rep_offset' => $repOffset,
            'subclass_fields' => $extra,
            'extension_bytes' => $limit - $position,
            'runtime_property_offsets_linked' => false,
            'runtime_bool_bitmask_linked' => false,
        ];
    }
}
