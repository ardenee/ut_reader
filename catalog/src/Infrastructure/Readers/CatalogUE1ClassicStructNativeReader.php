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
        callable $read,callable $compact,callable $u32,int &$position,int $limit,
        ?string $sourceFamily=null
    ):array{
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        if(!in_array($kind,self::KINDS,true)){
            throw new RuntimeException('Unsupported classic field/struct native kind');
        }
        $reference=static function(string $label)use($compact,$pkg,$family,&$position):int{
            $n=$compact();
            $importCount=count($pkg->getImports());
            if(($n<0 && -$n>$importCount)
                ||($n>0 && $n>count($pkg->getExports()))){
                // Genuine original May17 1998 Core.dll raises Bad import index
                // with a zero-based import slot, after the compact index read.
                // The observed Children=-63 against 16 imports has Tell=24224.
                // Do not infer that other UE1 lineages use this diagnostic.
                if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1
                    &&$n<0){
                    throw new RuntimeException(sprintf(
                        'UE1 Bad import index %d/%d at archive cursor %d',
                        -$n-1,$importCount,$position));
                }
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
            try{
                $script=CatalogUE1ClassicScriptArchiveReader::inspect(
                    $pkg,$version,$scriptSize,$read,$compact,$position,$family);
            }catch(RuntimeException $e){
                // Original May17 1998 UStruct::Serialize reports its iCode
                // mismatch after SerializeExpr has consumed the expression.
                // Preserve the actual cursor and use the compiled error text;
                // the generic decoder's earlier budget exception is not the
                // source-selected C98 diagnostic. Other families are unchanged.
                if($family===CatalogUE1ClassicSourceFamily::EPIC_UE1
                    &&preg_match('/^UE1 SerializeExpr exceeded decoded script-memory ScriptSize \\(memory=(\\d+)\\/(\\d+),.*archive=(\\d+)\\)$/',
                        $e->getMessage(),$m)){
                    throw new RuntimeException(sprintf(
                        'UE1 Script serialization mismatch: Got %d, expected %d at archive cursor %d',
                        (int)$m[1],(int)$m[2],(int)$m[3]),0,$e);
                }
                throw $e;
            }
            $record['script_archive']=$script;
            if($script['status']!=='script_archive_expressions_decoded'){
                return array_replace($record,[
                    'status'=>'native_struct_script_expr_unimplemented',
                    'unparsed_bytes'=>$limit-$position,
                ]);
            }
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
        $legacy=$family===CatalogUE1ClassicSourceFamily::EPIC_UE1||$version<=63;
        $record['legacy_parms_size']=$legacy?$u16():null;
        $record['native_index']=$u16();
        $record['legacy_num_parms']=$legacy?$u8():null;
        $record['operator_precedence']=$u8();
        $record['legacy_return_value_offset']=$legacy?$u16():null;
        $record['function_flags']=$u32();
        $record['rep_offset']=($record['function_flags']&0x40)!==0?$u16():null;
        // UT99 v400 Core/Src/UnClass.cpp::UFunction::Serialize always
        // discards the archived old ABI fields on LOAD, resetting
        // NumParms/ParmsSize/ReturnValueOffset before recomputing
        // from the linked Children UProperty chain. Unreal v1.200
        // instead leaves the three archived values in memory.
        $ut99=$family===CatalogUE1ClassicSourceFamily::UT99_V432;
        $record['loaded_parms_size']=$ut99?null:$record['legacy_parms_size'];
        $record['loaded_num_parms']=$ut99?null:$record['legacy_num_parms'];
        $record['loaded_return_value_offset']=$ut99?null:$record['legacy_return_value_offset'];
        $record['ut99_function_recomputed_from_children']=$ut99;
        $record['ut99_function_precompute_initial_values']=$ut99
            ?['parms_size'=>0,'num_parms'=>0,'return_value_offset'=>65535]:null;
        $record['runtime_function_param_chain_linked']=false;
        return array_replace($record,[
            'status'=>$position===$limit?'native_function_record_complete':'native_function_has_extension',
            'extension_bytes'=>$limit-$position,
        ]);
    }
}
