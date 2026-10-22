<?php
/**
 * Classic UE1 UField/UStruct/UState/UFunction persisted native tails.
 * Epic v1.200 Core/Src/UnClass.cpp:236,476,1358,1440
 * and UT99 v400 Core/Src/UnClass.cpp:239,593,712,1342.
 *
 * ScriptSize counts decoded bytecode, not its serialized archive footprint;
 * nonzero script branches remain explicitly unparsed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicStructNativeReader
{
    public const KINDS=['field','struct','state','function'];

    /** @return array<string,mixed> */
    public static function inspect(
        string $kind,CatalogUE1PackageReader $pkg,int $version,
        callable $read,callable $compact,callable $u32,int &$position,int $limit
    ):array{
        if(!in_array($kind,self::KINDS,true)){
            throw new RuntimeException('Unsupported classic field/struct native kind');
        }
        $reference=static function(string $label)use($compact,$pkg):int{
            $n=$compact();
            if(($n<0 && -$n>count($pkg->getImports()))
                ||($n>0 && $n>count($pkg->getExports()))){
                throw new RuntimeException('Invalid UE1 '.$label.' object reference');
            }
            return $n;
        };
        $names=$pkg->getNames();
        $name=static function(string $label)use($compact,$names):array{
            $id=$compact();
            if($id<0||!isset($names[$id])){
                throw new RuntimeException('Invalid UE1 '.$label.' FName index');
            }
            return ['index'=>$id,'text'=>(string)$names[$id]['name']];
        };
        $u16=static fn():int=>(int)unpack('v',$read(2))[1];
        $u8=static fn():int=>ord($read(1));
        $i32=static function()use($u32):int{
            $v=$u32();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $record=[
            'property_kind'=>$kind,
            'super_field_reference'=>$reference('SuperField'),
            'next_field_reference'=>$reference('Next'),
            'runtime_references_linked'=>false,
        ];
        if($kind==='field'){
            return array_replace($record,[
                'status'=>$position===$limit?'native_field_record_complete':'native_field_has_extension',
                'extension_bytes'=>$limit-$position]);
        }
        // UStruct inherits UField; this exact prefix is shared by
        // UState, UFunction and UClass in both classic source branches.
        $friendly=null;
        $record['script_text_reference']=$reference('ScriptText');
        $record['children_reference']=$reference('Children');
        $friendly=$name('FriendlyName');
        if(strcasecmp($friendly['text'],'None')===0){
            throw new RuntimeException('UStruct FriendlyName cannot be NAME_None');
        }
        $record['friendly_name']=$friendly;
        $record['source_line']=$i32();
        $record['source_text_pos']=$i32();
        $scriptSize=$i32();
        if($scriptSize<0){throw new RuntimeException('Negative UStruct ScriptSize');}
        $record['script_size']=$scriptSize;
        if($scriptSize!==0){
            return array_replace($record,[
                'status'=>'native_struct_script_expr_unimplemented',
                'unparsed_bytes'=>$limit-$position,
            ]);
        }
        if($kind==='struct'){
            return array_replace($record,[
                'status'=>$position===$limit?'native_struct_record_complete':'native_struct_has_extension',
                'extension_bytes'=>$limit-$position,
            ]);
        }
        if($kind==='state'){
            $record['probe_mask_hex']=bin2hex($read(8));
            $record['ignore_mask_hex']=bin2hex($read(8));
            $record['label_table_offset']=$u16();
            $record['state_flags']=$u32();
            return array_replace($record,[
                'status'=>$position===$limit?'native_state_record_complete':'native_state_has_extension',
                'extension_bytes'=>$limit-$position,
            ]);
        }
        // Function directly inherits UStruct rather than UState.
        // Epic v1.200 writes both old function ABI fields, while
        // UT99 v400 retains the old fields only in package versions <=63.
        $legacy=$version<=63;
        $record['legacy_parms_size']=$legacy?$u16():null;
        $record['native_index']=$u16();
        $record['legacy_num_parms']=$legacy?$u8():null;
        $record['operator_precedence']=$u8();
        $record['legacy_return_value_offset']=$legacy?$u16():null;
        $record['function_flags']=$u32();
        $record['rep_offset']=($record['function_flags']&0x40)!==0?$u16():null;
        return array_replace($record,[
            'status'=>$position===$limit?'native_function_record_complete':'native_function_has_extension',
            'extension_bytes'=>$limit-$position,
        ]);
    }
}
