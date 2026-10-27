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
        // Original Unreal v1.227 Core/Inc/UnType.h adds these two class
        // declarations with inherited UProperty::Serialize. They do not
        // declare a new Serialize override; only their tag GetID differs.
        'pointerproperty', 'anyproperty',
    ];

    /**
     * Uses the enclosing file-bounded native archive's callbacks. The
     * parent maintains $position and supplies a safe [start,limit) read.
     * @return array<string,mixed>
     */
    public static function inspect(
        string $kind, CatalogUE1PackageReader $pkg, int $version,
        callable $read, callable $compact, callable $u32,
        int &$position, int $limit, ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Unsupported classic UE1 property serializer: '.$kind);
        }
        // Later Unreal source introduces both classes, but neither
        // overrides Serialize. Their saved declaration is exactly the
        // inherited UField -> UProperty archive, source-gated to v227;
        // their SerializeItem/runtime semantics are a separate contract.
        if (in_array($kind,['pointerproperty','anyproperty'],true)
            &&$family!==CatalogUE1ClassicSourceFamily::UNREAL_V227) {
            throw new RuntimeException('New v227 property class absent from selected UE1 game source');
        }
        // The v1.200 branch uses fixed UStringProperty. Dynamic FString,
        // array, fixed-array and map property classes belong to the later
        // UT99 source, not the v1.200 class registration table.
        if ($family===CatalogUE1ClassicSourceFamily::EPIC_UE1 && in_array($kind, [
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
        // Epic C98 v1.200 and inherited UT99 v400 UProperty::Serialize
        // archives ArrayDim verbatim (Ar << ArrayDim) and does not perform
        // a positivity check here. The later UProperty::Link/instance
        // construction outcome is independent and NOT verified by an
        // archive-only reader. Rejecting 0 or negative here invents an
        // earlier native FArchive error that is absent from these methods.
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
                // Epic v1.200 UnProp.cpp:519-525 archives signed StringSize
                // verbatim. No positivity check occurs in Serialize; memory
                // allocation/link semantics remain separate and unverified.
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
                // Epic UT99 v400 UnProp.cpp:954-961 archives signed Count
                // verbatim and checkSlow checks Inner, NOT Count. Do not
                // invent an archive error for zero or negative Count.
                break;
            case 'mapproperty':
                $extra['key_reference'] = $reference('UMapProperty.Key');
                $extra['value_reference'] = $reference('UMapProperty.Value');
                break;
            // UPointerProperty and UAnyProperty have no Serialize override
            // in v227 UnType.h: UProperty inherited native archive only.
            // Their GetID aliases affect tagged instance values, not this
            // declaration's serialized native byte count.
            // UInt/Float/Bool/Name/StrProperty add NO LOAD/SAVE native bytes.
            // UBoolProperty BitMask is serialized only in non-load/save archives.
        }
        // These two pinned Epic serializers genuinely disagree for
        // overlapping old package versions: Unreal v1.200 UnProp.cpp
        // preserves PropertyFlags verbatim, whereas UT99 v400 UnProp.cpp
        // clears obsolete net flags 0x00080040 after reading version<=61.
        // Use the original source result for <=61 and UT99 for >=62;
        // expose the separate UT99 legacy-loader result instead of
        // silently pretending both engines produce identical flags.
        $ut99LegacyEffectiveFlags = $version<=61
            ?($flags & ~0x00080040):$flags;
        return [
            'status' => $position === $limit
                ? 'native_property_record_complete' : 'native_property_has_extension',
            'property_kind' => $kind,
            'declared_4bit_tag_alias' => $kind==='pointerproperty'
                ?'NAME_IntProperty':($kind==='anyproperty'?'NAME_VectorProperty':null),
            'new_v227_property_runtime_serializeitem_verified' =>
                !in_array($kind,['pointerproperty','anyproperty'],true),
            'super_field_reference' => $superField,
            'next_field_reference' => $nextField,
            'array_dim' => $arrayDim,
            'archive_array_dim_preserved_without_linkage_validation' => true,
            'property_flags' => $flags,
            'effective_property_flags' => $family===CatalogUE1ClassicSourceFamily::UT99_V432
                ?$ut99LegacyEffectiveFlags:$flags,
            'selected_source_family'=>$family,
            'effective_property_flags_if_ut99_v400_load' =>
                $ut99LegacyEffectiveFlags,
            'property_flag_load_semantics' => $family===CatalogUE1ClassicSourceFamily::UT99_V432&&$version<=61
                ? 'UT99-v400-old-loader-clears-obsolete-net-flags'
                : ($family===CatalogUE1ClassicSourceFamily::UT99_V432
                    ?'UT99-v400-preserve-flags':'Epic-UE1-preserve-flags'),
            'source_dependent_legacy_net_flags' =>
                $version<=61&&$flags!==$ut99LegacyEffectiveFlags,
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
