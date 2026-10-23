<?php
/**
 * Original 1997 UStackTree persisted FStackNode records.
 *
 * May v0.86x: Src/Engine/UnScript.cpp::operator<<(FStackNode)
 *    four FStackNodeLink fields: Parent, ChildFunctions, ChildStates, Next.
 * July v0.867: same function archives FIVE FStackNodePtr fields:
 *    ParentItem, ParentNest, ChildFunctions, ChildStates, Next.
 *
 * The two builds share Unrealfile version 22/23: never use the file version
 * alone as source provenance. With no explicit lineage, accept only a UNIQUE
 * physically complete and structurally valid source-specific archive layout.
 * Ambiguous layouts stay explicitly unverified, even in strict mode.
 * No native runtime FStackNode linking or Preload side effects are claimed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileStackTreeDataReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false,?string $sourceLineage=null):array
    {
        CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $special=CatalogUE1OriginalUnrealfileSpecialHeadersReader::read($path,false);
        $by=[];foreach($special['special_headers'] as $h)
            $by[(int)$h['object_index']]=$h;
        $maxRef=(int)$meta['summary']['object_count'];
        $maxName=count($meta['names']);
        $rows=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original stack tree');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'StackTree')!==0)
                    continue;
                $h=$by[(int)$o['index']]??[];
                $partial=['object_index'=>(int)$o['index'],'source_class'=>'UStackTree',
                    'status'=>'incomplete_source_stacktree_header',
                    'runtime_stack_linked'=>false,'source_lineage'=>$sourceLineage];
                if(($h['status']??null)!=='native_special_header_decoded'){
                    if($strict)throw new RuntimeException('StackTree source header unavailable');
                    $rows[]=$partial;continue;
                }
                $n=(int)$h['record_count'];$length=(int)$o['file_data_size'];
                // May null-link function record: 4*DWORD + 5*WORD + 4*BYTE + 5*WORD = 40B.
                if($n>100000||$length>67108864||$n>intdiv($length,40))
                    throw new RuntimeException('UStackTree record count exceeds source minimum archived bytes');
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UStackTree archive');
                $raw='';while(strlen($raw)<$length){
                    $part=fread($fp,min(65536,$length-strlen($raw)));
                    if(!is_string($part)||$part==='')throw new RuntimeException('Truncated UStackTree archive');
                    $raw.=$part;
                }
                // Both Epic versions serialize zero FStackNode elements
                // to an empty data archive when Num==0. A missing game
                // lineage cannot change the meaning of an empty array.
                if($n===0 && $length===0){
                    $rows[]=array_merge($partial,[
                        'status'=>'native_stacktree_records_decoded',
                        'record_count'=>0,
                        'record_capacity'=>(int)$h['record_capacity'],
                        'class_ref_raw_one_based'=>(int)$h['class_raw_one_based_ref'],
                        'serialized_data_bytes'=>0,
                        'records'=>[],
                        'source_lineage'=>$sourceLineage,
                        'source_lineage_wire_equivalent'=>true,
                        'source_lineage_inferred_from_unique_archive_layout'=>false,
                        'source_compiled_runtime_oracle_verified'=>false,
                        'runtime_stack_linked'=>false,
                    ]);
                    continue;
                }
                $families=$sourceLineage===null
                    ? [CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY,
                       CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY]
                    : [$sourceLineage];
                $valid=[];$errors=[];
                foreach($families as $family){
                    try{
                        $valid[$family]=self::decodeRecords(
                            $raw,$n,$maxRef,$maxName,$meta['names'],$family);
                    }catch(RuntimeException $e){
                        $errors[$family]=$e->getMessage();
                    }
                }
                if(count($valid)!==1){
                    $ambiguous=count($valid)>1;
                    if($strict)throw new RuntimeException(
                        $ambiguous
                          ? 'May/July UStackTree archive source lineage ambiguous'
                          : 'UStackTree does not match the requested/known original source layout: '
                            .implode('; ',$errors));
                    $rows[]=array_merge($partial,[
                        'status'=>$ambiguous
                            ? 'source_1997_stacktree_lineage_required'
                            : 'source_1997_stacktree_layout_unverified',
                        'record_count'=>$n,
                        'candidate_source_families'=>array_keys($valid),
                        'source_layout_errors'=>$errors,
                        'runtime_stack_linked'=>false
                    ]);
                    continue;
                }
                $family=array_key_first($valid);
                $rows[]=array_merge($partial,[
                    'status'=>'native_stacktree_records_decoded',
                    'record_count'=>$n,'record_capacity'=>(int)$h['record_capacity'],
                    'class_ref_raw_one_based'=>(int)$h['class_raw_one_based_ref'],
                    'serialized_data_bytes'=>$length,
                    'records'=>$valid[$family],
                    'source_lineage'=>$family,
                    'source_lineage_inferred_from_unique_archive_layout'=>$sourceLineage===null,
                    'source_compiled_runtime_oracle_verified'=>false,
                    'runtime_stack_linked'=>false
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','stacktree_records'=>$rows,
            'runtime_stack_linked'=>false];
    }

    /**
     * Decode ONLY the source-defined FStackNode archive fields.
     * All count, link and name checks apply independently for each source.
     * @param array<int,array<string,mixed>> $names
     * @return array<int,array<string,mixed>>
     */
    private static function decodeRecords(
        string $raw,int $n,int $maxRef,int $maxName,array $names,string $family
    ):array {
        $pos=0;
        $take=static function(int $size)use($raw,&$pos):string{
            if($size<0||$size>strlen($raw)-$pos)
                throw new RuntimeException('Truncated source FStackNode archive');
            $part=substr($raw,$pos,$size);$pos+=$size;return $part;
        };
        $u32=static fn():int=>(int)unpack('V',$take(4))[1];
        $u16=static fn():int=>(int)unpack('v',$take(2))[1];
        $signed=static fn(int $value):int=>$value>=0x80000000
            ? $value-0x100000000:$value;
        $linkKeys=$family===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY
            ? ['parent','child_functions','child_states','next']
            : ['parent_item','parent_nest','child_functions','child_states','next'];
        $records=[];
        for($i=0;$i<$n;$i++){
            $links=[];
            foreach($linkKeys as $key){
                $ref=$u32();
                if($ref>$maxRef)throw new RuntimeException('FStackNode Class exceeds ResMap');
                $links[$key]=[
                    'class_ref'=>$ref,
                    'node_index'=>$ref===0?null:$signed($u32())
                ];
            }
            $name=$u16();
            if($name>$maxName)throw new RuntimeException('FStackNode Name exceeds NameMap');
            $icode=$u16();$label=$u16();$line=$u16();$sourcePos=$u16();
            $flags=array_values(unpack('C4',$take(4)));
            $nest=$flags[0];
            if($nest>10)throw new RuntimeException('FStackNode unknown NestType');
            if($nest===1||$nest===2){
                $v=array_values(unpack('V4',$take(16)));
                $union=['probe_mask_words_le'=>array_slice($v,0,2),
                    'ignore_mask_words_le'=>array_slice($v,2,2)];
            }else{
                $v=array_values(unpack('v5',$take(10)));
                $union=['locals_size'=>$v[0],'parms_size'=>$v[1],
                    'intrinsic_index'=>$v[2],'first_property_index'=>$v[3],
                    'num_properties'=>$v[4]];
            }
            $records[]=['index'=>$i,'links'=>$links,'name_ref'=>$name,
                'name'=>$name===0?null:$names[$name-1]['name'],
                'code_offset'=>$icode,'code_label_offset'=>$label,
                'source_line'=>$line,'source_position'=>$sourcePos,
                'stack_flags'=>$flags[1],'nest_type'=>$nest,
                'num_parms'=>$flags[2],'operator_precedence'=>$flags[3],
                'source_union'=>$union,'runtime_stack_linked'=>false];
        }
        if($pos!==strlen($raw))throw new RuntimeException(
            'Unconsumed source FStackNode archive bytes');
        return $records;
    }
}
