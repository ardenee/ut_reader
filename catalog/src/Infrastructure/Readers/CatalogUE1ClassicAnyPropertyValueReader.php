<?php
/**
 * Explicit Unreal 227k FAnyProperty INSTANCE-value archive subset.
 *
 * Not an ordinary FVector or int alias: UAnyProperty::GetID() exposes
 * NAME_VectorProperty, but the real compiled 227k UAnyProperty::SerializeItem
 * archives one runtime type byte followed by type-specific bytes.
 *
 * Original 227k Core.dll has been INVOKED through actual original native
 * exports on LOAD and SAVE for NULL, INT, FLOAT, BOOL, STRING (0..4)
 * with a test FArchive-ABI shim. STRING was additionally proved via
 * genuine original native FString->FArchive compact-index SAVE/LOAD
 * round-trip under correct source ArIsSaving=2 and CountBytes vtable.
 * Only original native-proven small ASCII FString wire records are
 * decoded; extended Unicode and NAME/STRUCT/OBJECT (5..7) fail closed.
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
        if($type>5){
            $typeName=self::TYPES[$type]??'ANYTYPE_INVALID';
            // Unsupported capability; not an Epic runtime archive error.
            throw new RuntimeException(
                'Unreal v227 dynamic Any subtype not yet implemented: '
                .$typeName.' ('.$type.')');
        }
        if($type===4){
            // Original compiled 227k FAnyProperty::Serialize delegates
            // FString <<, which archives signed compact character count
            // followed by ANSI/Unicode string bytes. The actual original
            // 227k native STRING test proved canonical small ANSI form
            // 04 05 54 65 73 74 00 on LOAD, SAVE, and SAVE->LOAD.
            // Large/multibyte/Unicode/corrupt counts are NOT yet proved.
            $countBytes=$read(1);
            if(strlen($countBytes)!==1){
                throw new RuntimeException('Unreal v227 Any STRING compact count truncated');
            }
            $count=ord($countBytes);
            if($count<1||$count>63){
                throw new RuntimeException(
                    'Unreal v227 Any STRING compact length or Unicode form not yet verified');
            }
            $stringBytes=$read($count);
            if(strlen($stringBytes)!==$count
                ||substr($stringBytes,-1)!=="\0"
                ||str_contains(substr($stringBytes,0,-1),"\0")
                ||preg_match('/[\x80-\xFF]/',$stringBytes)===1){
                throw new RuntimeException(
                    'Unreal v227 Any STRING payload invalid or Unicode form not yet verified');
            }
            $payload=$countBytes.$stringBytes;
        }elseif($type===5){
            // Original shipped 227k UAnyProperty::SerializeItem calls
            // FArchive's virtual FName operator (slot +0x1c). Executed
            // through original x86 Core.dll for the ZERO-valued name only:
            // native LOAD/SAVE wire hex 05 00 00 00 00.
            // Arbitrary names require real name-table/archive remapping,
            // which has not been native-proven in this mock archive.
            $payload=$read(4);
            if(strlen($payload)!==4||$payload!=="\0\0\0\0"){
                throw new RuntimeException(
                    'Unreal v227 Any nonzero NAME value requires native name-table mapping');
            }
        }else{
            $size=match($type){0=>0,1=>4,2=>4,3=>1};
            $payload=$size>0?$read($size):'';
            if(strlen($payload)!==$size){
                throw new RuntimeException('Unreal v227 dynamic Any payload truncated');
            }
        }
        $raw=$first.$payload;
        $value=match($type){
            0=>null,
            1=>self::signed32($payload),
            2=>(float)unpack('g',$payload)[1],
            3=>ord($payload)!==0,
            4=>substr($stringBytes,0,-1),
            5=>['name_index'=>0,'resolved_text'=>null],
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
                4=>'native_any_ansi_string',
                5=>'native_any_zero_fname',
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
     * exact IEEE-754 bytes to avoid NaN rewrites. Type4 rawValue is the
     * original FString compact count and its null-terminated ANSI bytes.
     */
    public static function saveVerified(
        int $type,string $rawValue,string $sourceFamily
    ):string{
        if($sourceFamily!==CatalogUE1ClassicSourceFamily::UNREAL_V227){
            throw new RuntimeException('UAny source provenance is not Unreal v227');
        }
        if($type===4){
            if(strlen($rawValue)<2||strlen($rawValue)>64){
                throw new RuntimeException('Unreal v227 Any STRING SAVE length not verified');
            }
            $count=ord($rawValue[0]);
            if($count<1||$count>63
                ||strlen($rawValue)!==$count+1
                ||substr($rawValue,-1)!=="\0"
                ||str_contains(substr($rawValue,1,-1),"\0")
                ||preg_match('/[\x80-\xFF]/',$rawValue)===1){
                throw new RuntimeException(
                    'Unreal v227 Any STRING SAVE unsupported compact, Unicode or malformed payload');
            }
            return chr($type).$rawValue;
        }
        if($type===5){
            if($rawValue!=="\0\0\0\0"){
                throw new RuntimeException(
                    'Unreal v227 Any NAME SAVE not verified for nonzero name-table reference');
            }
            return "\x05".$rawValue;
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
