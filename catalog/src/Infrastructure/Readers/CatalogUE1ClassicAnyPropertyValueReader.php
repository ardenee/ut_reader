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
 * Only native-proven small ASCII FString wire records are decoded.
 * Genuine 2026 original compiled C227k engine SavePackage and independent
 * original native LOAD additionally prove source-linker FName (5),
 * FVector Struct Core.Object.Vector (6) and UObject (7).
 * Unproven UStructs, linker remappings and Unicode fail closed.
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
        callable $read,int &$position,string $sourceFamily,
        ?CatalogUE1PackageReader $linkerPkg=null
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
        if($type>7){
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
            if($linkerPkg!==null){
                // Genuine 2026 compiled C227k original-UCC class default:
                // dynamic Any subtype 5 followed by ULinkerLoad FName's
                // signed AR_INDEX. Genuine native loaded name G3NameAlpha
                // from exactly 05 08, NOT the mock ABI's 4-byte zero.
                [$index,$payload]=self::readLinkerCompact($read);
                $names=$linkerPkg->getNames();
                if($index<0||!isset($names[$index])){
                    throw new RuntimeException(
                        'UE1 v227 ULinkerLoad<<FName Any invalid name index '.$index
                        .' at archive offset '.$position);
                }
                $value=['name_index'=>$index,
                    'resolved_text'=>(string)$names[$index]['name']];
                $valueKind='native_any_linker_fname';
            }else{
                // An older original Core.dll ABI shim proved only
                // NAME_None using four raw bytes; do not pretend its
                // FArchive implementation was genuine ULinkerLoad.
                $payload=$read(4);
                if(strlen($payload)!==4||$payload!=="\0\0\0\0"){
                    throw new RuntimeException(
                        'Unreal v227 Any nonzero NAME requires real linker');
                }
                $value=['name_index'=>0,'resolved_text'=>null];
                $valueKind='native_any_zero_fname';
            }
        }elseif($type===7){
            if($linkerPkg!==null){
                // Genuine 2026 original native CDO loaded
                // 07 82 => index -2 => import Class Engine.Actor.
                // Resolve the ORIGINAL ImportMap outer chain; do
                // not turn bare indices into fake package names.
                [$index,$payload]=self::readLinkerCompact($read);
                $full=self::resolveLinkerObject($linkerPkg,$index);
                $value=['object_reference'=>$index,
                    'resolved_object'=>$full];
                $valueKind='native_any_linker_object';
            }else{
                $payload=$read(4);
                if(strlen($payload)!==4||$payload!=="\0\0\0\0"){
                    throw new RuntimeException(
                        'Unreal v227 Any nonnull OBJECT requires real linker');
                }
                $value=['object_reference'=>0,'resolved_object'=>null];
                $valueKind='native_any_null_object';
            }
        }elseif($type===6){
            if($linkerPkg===null){
                throw new RuntimeException(
                    'Unreal v227 Any STRUCT needs a genuine source linker UStruct reference');
            }
            // Real independently compiled original 2026 C227k Core.dll:
            // UAnyProperty SetStruct(FVector, Core.Object.Vector),
            // SavePackage => 06 83 0c000000 00005040 000090c0
            //                0000b840; original fresh Core.dll reload
            // recovers exact Struct Core.Object.Vector and XYZ.
            // The original 2012 compiler cannot assign this Any struct;
            // no simulated object is accepted as the oracle.
            [$structRef,$refBytes]=self::readLinkerCompact($read);
            $full=self::resolveLinkerObject($linkerPkg,$structRef);
            if($structRef>=0||$full!=='Struct Core.Object.Vector'){
                // Other UStructs require their own native SerializeBin
                // source field-chain and compiled original oracle.
                throw new RuntimeException(
                    'Unreal v227 Any STRUCT UStruct source layout not verified: '
                    .($full??'NULL').' ref '.$structRef);
            }
            $sizeBytes=$read(4);
            if(strlen($sizeBytes)!==4){
                throw new RuntimeException('Unreal v227 Any STRUCT size truncated');
            }
            $size=(int)unpack('V',$sizeBytes)[1];
            if($size!==12){
                throw new RuntimeException(
                    'Unreal v227 Any STRUCT Vector serialized size not original-verified: '.$size);
            }
            $valueBytes=$read($size);
            if(strlen($valueBytes)!==$size){
                throw new RuntimeException('Unreal v227 Any STRUCT FVector truncated');
            }
            $xyz=array_values(unpack('g3',$valueBytes));
            $value=[
                'struct_reference'=>$structRef,
                'resolved_struct'=>$full,
                'struct_property_bytes'=>12,
                'x'=>$xyz[0],'y'=>$xyz[1],'z'=>$xyz[2],
                'serialized_float32_xyz_hex'=>bin2hex($valueBytes)
            ];
            $valueKind='native_any_linker_fvector_xyz';
            $payload=$refBytes.$sizeBytes.$valueBytes;
        }else{
            $size=match($type){0=>0,1=>4,2=>4,3=>1};
            $payload=$size>0?$read($size):'';
            if(strlen($payload)!==$size){
                throw new RuntimeException('Unreal v227 dynamic Any payload truncated');
            }
        }
        $raw=$first.$payload;
        $value=$value??match($type){
            0=>null,
            1=>self::signed32($payload),
            2=>(float)unpack('g',$payload)[1],
            3=>ord($payload)!==0,
            4=>substr($stringBytes,0,-1),
            default=>null,
        };
        return [
            'status'=>'native_227k_any_value_original_compiled_subtype_verified',
            'source_profile'=>CatalogUE1ClassicSourceFamily::UNREAL_V227,
            'original_any_type_id'=>$type,
            'original_any_type_name'=>self::TYPES[$type],
            'value'=>$value,
            'value_kind'=>$valueKind??match($type){
                0=>'native_any_null',1=>'native_any_int32',
                2=>'native_any_float32',3=>'native_any_bool',
                4=>'native_any_ansi_string',
                default=>'native_any_unverified'
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
        int $type,string $rawValue,string $sourceFamily,
        ?CatalogUE1PackageReader $linkerPkg=null
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
        if($type===6&&$linkerPkg!==null){
            // This is a source-index-stable reserialization of the exact
            // genuine ULinker archive wire into the SAME package map.
            // Do not attempt a fresh package's new import-index mapping.
            $input=chr(6).$rawValue;
            $position=0;
            $read=static function(int $n)use($input,&$position):string{
                $bytes=substr($input,$position,$n);
                $position+=strlen($bytes);
                return $bytes;
            };
            $decoded=self::readFromArchive(
                $read,$position,$sourceFamily,$linkerPkg);
            if($position!==strlen($input)
                ||($decoded['original_any_type_id']??null)!==6
                ||($decoded['value_kind']??null)!=='native_any_linker_fvector_xyz'){
                throw new RuntimeException(
                    'Unreal v227 Any STRUCT SAVE source-linked wire mismatch');
            }
            return $input;
        }
        if($type===5&&$linkerPkg!==null){
            $offset=0;
            $read=static function(int $n)use($rawValue,&$offset):string{
                $out=substr($rawValue,$offset,$n);$offset+=strlen($out);
                return $out;
            };
            [$index,$bytes]=self::readLinkerCompact($read);
            if($offset!==strlen($rawValue)
                ||$index<0||!isset($linkerPkg->getNames()[$index])){
                throw new RuntimeException('Unreal v227 Any NAME save invalid linker FName');
            }
            return "\x05".$bytes;
        }
        if($type===7&&$linkerPkg!==null){
            $offset=0;
            $read=static function(int $n)use($rawValue,&$offset):string{
                $out=substr($rawValue,$offset,$n);$offset+=strlen($out);
                return $out;
            };
            [$index,$bytes]=self::readLinkerCompact($read);
            if($offset!==strlen($rawValue)){
                throw new RuntimeException('Unreal v227 Any OBJECT save malformed compact');
            }
            self::resolveLinkerObject($linkerPkg,$index);
            return "\x07".$bytes;
        }
        if($type===5){
            if($rawValue!=="\0\0\0\0"){
                throw new RuntimeException(
                    'Unreal v227 Any NAME SAVE not verified for nonzero name-table reference');
            }
            return "\x05".$rawValue;
        }
        if($type===7){
            if($rawValue!=="\0\0\0\0"){
                throw new RuntimeException(
                    'Unreal v227 Any OBJECT SAVE not verified for nonnull UObject reference');
            }
            return "\x07".$rawValue;
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

    /** @return array{0:int,1:string} */
    private static function readLinkerCompact(callable $read):array{
        $raw=$read(1);
        if(strlen($raw)!==1){
            throw new RuntimeException('Unreal v227 Any compact reference truncated');
        }
        $first=ord($raw);$number=$first&0x3f;
        $shift=6;$more=($first&0x40)!==0;
        while($more){
            if(strlen($raw)>=5){
                throw new RuntimeException('Unreal v227 Any compact reference overflow');
            }
            $byte=$read(1);
            if(strlen($byte)!==1){
                throw new RuntimeException('Unreal v227 Any compact reference truncated');
            }
            $raw.=$byte;$b=ord($byte);
            $number|=($b&0x7f)<<$shift;
            $more=($b&0x80)!==0;$shift+=7;
        }
        return [($first&0x80)?-$number:$number,$raw];
    }

    private static function resolveLinkerObject(
        CatalogUE1PackageReader $pkg,int $index
    ):?string{
        if($index===0)return null;
        $imports=$pkg->getImports();$exports=$pkg->getExports();
        $full=[];$leafClass=null;$visited=[];
        for($depth=0;$index!==0&&$depth<64;$depth++){
            if(isset($visited[$index])){
                throw new RuntimeException('UE1 v227 Any UObject outer cycle');
            }
            $visited[$index]=true;
            $obj=$index<0?($imports[-$index-1]??null)
                :($exports[$index-1]??null);
            if(!is_array($obj)){
                throw new RuntimeException(
                    'UE1 v227 Any invalid linker object reference '.$index);
            }
            if($leafClass===null){
                if($index>0){
                    // The export's classIndex requires a second
                    // class-path resolution. Fail closed rather than
                    // guessing a UClass for unsupported export targets.
                    throw new RuntimeException(
                        'UE1 v227 Any positive UObject class identity not verified');
                }
                $leafClass=(string)($obj['classNameText']??'');
                if($leafClass===''){
                    throw new RuntimeException('UE1 v227 Any import class unresolved');
                }
            }
            $full[]=(string)($obj['objectNameText']??'');
            if(end($full)===''){
                throw new RuntimeException('UE1 v227 Any import object name unresolved');
            }
            $index=(int)($obj['outerIndex']??0);
        }
        if($index!==0)throw new RuntimeException('UE1 v227 Any UObject outer depth exceeded');
        return $leafClass.' '.implode('.',array_reverse($full));
    }

    private static function signed32(string $raw):int
    {
        $v=(int)unpack('V',$raw)[1];
        return $v>=0x80000000?$v-0x100000000:$v;
    }
}
