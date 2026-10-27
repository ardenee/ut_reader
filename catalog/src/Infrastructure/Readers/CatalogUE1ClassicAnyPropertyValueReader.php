<?php
/**
 * Explicit Unreal 227k FAnyProperty INSTANCE-value archive subset.
 *
 * Not an ordinary FVector or int alias: UAnyProperty::GetID() exposes
 * NAME_VectorProperty, but the real compiled 227k UAnyProperty::SerializeItem
 * archives one runtime type byte followed by type-specific bytes.
 *
 * Original 227k Core.dll has been INVOKED through actual original native
 * exports on LOAD and SAVE for NULL, INT, FLOAT, BOOL (0..3) with a test
 * FArchive-ABI shim. STRING, NAME, STRUCT and OBJECT (4..7) are not
 * emulated or read as guessed tagged FVectors.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicAnyPropertyValueReader
{
    public const TYPES=[
        0=>'ANYTYPE_NULL',1=>'ANYTYPE_INT',2=>'ANYTYPE_FLOAT',
        3=>'ANYTYPE_BOOL',4=>'ANYTYPE_STRING',5=>'ANYTYPE_NAME',
        6=>'ANYTYPE_STRUCT',7=>'ANYTYPE_OBJECT',
    ];

    /**
     * Read only when the actual UProperty declaration is proven ANY,
     * never merely because FPropertyTag.Type is NAME_VectorProperty=11.
     *
     * The $read callback must advance $position and apply the existing
     * enclosing archive's physical-bounds rules.
     *
     * @return array<string,mixed>
     */
    public static function readFromArchive(
        callable $read,int &$position,string $sourceFamily
    ):array{
        if($sourceFamily!==CatalogUE1ClassicSourceFamily::UNREAL_V227){
            throw new RuntimeException(
                'Original FAnyProperty runtime value only certified for Unreal v227');
        }
        $start=$position;
        $first=$read(1);
        if(strlen($first)!==1){
            throw new RuntimeException('Unreal v227 Any type discriminator truncated');
        }
        $type=ord($first);
        if($type>3){
            $typeName=self::TYPES[$type]??'ANYTYPE_INVALID';
            // This is NOT an Epic native archive error: it is a precise
            // Unsupported decoder status. Do not guess a 12-byte vector
            // or skip Tag.Size and claim an original native loaded value.
            throw new RuntimeException(
                'Unreal v227 dynamic Any subtype not yet implemented: '
                .$typeName.' ('.$type.')');
        }
        $size=match($type){0=>0,1=>4,2=>4,3=>1};
        $payload=$size>0?$read($size):'';
        if(strlen($payload)!==$size){
            throw new RuntimeException('Unreal v227 dynamic Any payload truncated');
        }
        $raw=$first.$payload;
        $value=match($type){
            0=>null,
            1=>self::signed32($payload),
            2=>(float)unpack('g',$payload)[1],
            3=>ord($payload)!==0,
        };
        return [
            'status'=>'native_227k_any_value_original_compiled_subtype_verified',
            'source_profile'=>CatalogUE1ClassicSourceFamily::UNREAL_V227,
            'original_any_type_id'=>$type,
            'original_any_type_name'=>self::TYPES[$type],
            'value'=>$value,
            'value_kind'=>match($type){
                0=>'native_any_null',1=>'native_any_int32',
                2=>'native_any_float32',3=>'native_any_bool',
            },
            'serialized_byte_count'=>$position-$start,
            'raw_archive_bytes_hex'=>bin2hex($raw),
            'raw_value_bytes_hex'=>bin2hex($payload),
            'actual_compiled_native_load_save_oracle'=>'original-227k-Core-UAnyProperty-SerializeItem',
        ];
    }

    /**
     * Save only the exact wire forms previously executed with original
     * 227k compiled UAnyProperty::SerializeItem. Floats are passed as
     * exact original IEEE-754 little-endian bytes to avoid NaN rewrites.
     */
    public static function saveVerified(
        int $type,string $rawValue,string $sourceFamily
    ):string{
        if($sourceFamily!==CatalogUE1ClassicSourceFamily::UNREAL_V227){
            throw new RuntimeException('UAny source provenance is not Unreal v227');
        }
        $expected=match($type){0=>0,1=>4,2=>4,3=>1,default=>null};
        if($expected===null||strlen($rawValue)!==$expected){
            throw new RuntimeException('Unreal v227 Any SAVE subtype or wire length unsupported');
        }
        if($type===3 && $rawValue!=="\0" && $rawValue!=="\x01"){
            // Original noncanonical BOOL-byte handling is not native proven.
            throw new RuntimeException('Unreal v227 Any noncanonical BOOL byte not verified');
        }
        return chr($type).$rawValue;
    }

    private static function signed32(string $raw):int
    {
        $v=(int)unpack('V',$raw)[1];
        return $v>=0x80000000?$v-0x100000000:$v;
    }
}
