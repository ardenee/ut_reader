<?php
/**
 * Unreal v0.867 UStackTree(FStackNode) archive.
 * Epic Src/Engine/UnScript.cpp:5167-5197, Src/Inc/UnScript.h:449-456.
 * Five FStackNodePtr links write Class ref DWORD and only when non-null
 * a signed node INDEX DWORD. FName/offsets 5 WORD, flags 4 BYTE;
 * class/state union QWORD ProbeMask+IgnoreMask (16 bytes),
 * other nests five WORDs (10 bytes).
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileStackTreeDataReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
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
                    'runtime_stack_linked'=>false];
                if(($h['status']??null)!=='native_special_header_decoded'){
                    if($strict)throw new RuntimeException('StackTree source header unavailable');
                    $rows[]=$partial;continue;
                }
                $n=(int)$h['record_count'];$length=(int)$o['file_data_size'];
                if($n>100000||$length>67108864||$n>intdiv($length,44))
                    throw new RuntimeException('UStackTree record count exceeds minimum archived bytes');
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UStackTree archive');
                $raw='';while(strlen($raw)<$length){
                    $part=fread($fp,min(65536,$length-strlen($raw)));
                    if(!is_string($part)||$part==='')throw new RuntimeException('Truncated UStackTree archive');
                    $raw.=$part;
                }
                $pos=0;
                $take=static function(int $n)use($raw,&$pos):string{
                    if($n<0||$n>strlen($raw)-$pos)throw new RuntimeException('Truncated FStackNode archive');
                    $s=substr($raw,$pos,$n);$pos+=$n;return $s;
                };
                $u32=static fn():int=>(int)unpack('V',$take(4))[1];
                $u16=static fn():int=>(int)unpack('v',$take(2))[1];
                $signed=static fn(int $n):int=>$n>=0x80000000?$n-0x100000000:$n;
                $records=[];
                for($i=0;$i<$n;$i++){
                    $links=[];
                    foreach(['parent_item','parent_nest','child_functions','child_states','next'] as $key){
                        $ref=$u32();
                        if($ref>$maxRef)throw new RuntimeException('FStackNodePtr Class exceeds ResMap');
                        $links[$key]=[
                            'class_ref'=>$ref,
                            'node_index'=>$ref===0?null:$signed($u32())
                        ];
                    }
                    $name=$u16();
                    if($name>$maxName)throw new RuntimeException('FStackNode Name exceeds NameMap');
                    $icode=$u16();$label=$u16();$line=$u16();$sourcePos=$u16();
                    $flags=array_values(unpack('C4',$take(4)));
                    $nest=$flags[1];
                    if($nest>10)throw new RuntimeException('FStackNode unknown NestType');
                    $union=[];
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
                        'name'=>$name===0?null:$meta['names'][$name-1]['name'],
                        'code_offset'=>$icode,'code_label_offset'=>$label,
                        'source_line'=>$line,'source_position'=>$sourcePos,
                        'stack_flags'=>$flags[0],'nest_type'=>$nest,
                        'num_parms'=>$flags[2],'operator_precedence'=>$flags[3],
                        'source_union'=>$union,'runtime_stack_linked'=>false];
                }
                if($pos!==$length)throw new RuntimeException('Unconsumed source FStackNode archive bytes');
                $rows[]=array_merge($partial,[
                    'status'=>'native_stacktree_records_decoded',
                    'record_count'=>$n,'record_capacity'=>(int)$h['record_capacity'],
                    'class_ref_raw_one_based'=>(int)$h['class_raw_one_based_ref'],
                    'serialized_data_bytes'=>$pos,'records'=>$records,
                    'runtime_stack_linked'=>false
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','stacktree_records'=>$rows,
            'runtime_stack_linked'=>false];
    }
}
