<?php
/**
 * Source-specific Unreal v0.867 SerializeHeader contracts.
 * Epic: UnCamera.h UCamera; UnObj.h UBspNodes, ULightMesh, UVerts, UFont;
 * UnScript.h UStackTree. FExecStackMain is handled separately.
 *
 * This reads source-serialized fields, not Win32 object memory layout;
 * no runtime UObject is constructed and no provider is preloaded.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileSpecialHeadersReader
{
    private const CLASSES = [
        'camera' => ['Camera', false, 'texture'],
        'bspnodes' => ['BspNodes', true, 'zones'],
        'lightmesh' => ['LightMesh', true, 'bits'],
        'verts' => ['Verts', true, 'sides'],
        'font' => ['Font', true, 'texture'],
        'stacktree' => ['StackTree', true, 'class'],
    ];

    /** @return array<string,mixed> */
    public static function read(string $path, bool $strict=false): array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $objectCount=(int)$meta['summary']['object_count'];
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $by=[];foreach($stacks as $s)$by[(int)$s['object_index']]=$s;
        $result=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open 1997 original header file');
        try {
            foreach($meta['object_bases'] as $obj){
                if($obj['kind']!=='export')continue;
                $key=strtolower((string)$obj['class_name']);
                if(!isset(self::CLASSES[$key]))continue;
                [$nativeName,$isDB,$subtype]=self::CLASSES[$key];
                $s=$by[(int)$obj['index']]??[];
                $row=['object_index'=>(int)$obj['index'],'object_name'=>$obj['name'],
                    'native_class'=>'U'.$nativeName,
                    'status'=>'incomplete_original_special_header',
                    'runtime_references_resolved'=>false,
                    'class_specific_data_decoded'=>false];
                $min=($isDB?8:0)+4;
                if(($s['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($s['class_member_bytes_remaining']??0)<$min){
                    if($strict)throw new RuntimeException('Short native U'.$nativeName.' header');
                    $result[]=$row;continue;
                }
                $left=(int)$s['class_member_bytes_remaining'];
                $offset=(int)$obj['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$offset,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek original special native header');
                $raw=fread($fp,$left);
                if(!is_string($raw)||strlen($raw)!==$left)
                    throw new RuntimeException('Truncated original native special header');
                $pos=0;
                $u32=static function()use($raw,&$pos):int{
                    if(strlen($raw)-$pos<4)throw new RuntimeException('Truncated native DWORD');
                    $v=(int)unpack('V',substr($raw,$pos,4))[1];$pos+=4;return $v;
                };
                $fields=[];
                if($isDB){
                    $num=$u32();$max=$u32();
                    if($num>$max||$max>0x7fffffff)
                        throw new RuntimeException('Invalid original UDatabase Num/Max');
                    $fields['record_count']=$num;
                    $fields['record_capacity']=$max;
                }
                if($subtype==='zones'){
                    $n=$u32();
                    if($n>64||$left<$pos+$n*20)
                        throw new RuntimeException('Invalid UBspNodes NumZones or truncated zone header');
                    $zones=[];
                    for($i=0;$i<$n;$i++){
                        $ref=$u32();
                        if($ref>$objectCount)throw new RuntimeException('UBspNodes ZoneActor exceeds ResMap');
                        $lo=$u32();$hi=$u32();
                        $vlo=$u32();$vhi=$u32();
                        $zones[]=['index'=>$i,'zone_actor_ref'=>$ref,
                            'connectivity_words_le'=>[$lo,$hi],
                            'visibility_words_le'=>[$vlo,$vhi]];
                    }
                    $fields['num_zones']=$n;$fields['zones']=$zones;
                }elseif($subtype==='sides'){
                    $value=$u32();
                    $fields['num_shared_sides']=$value>=0x80000000?$value-0x100000000:$value;
                }else{
                    $ref=$u32();
                    if($ref>$objectCount)throw new RuntimeException('Original U'.$nativeName.' object reference exceeds ResMap');
                    $fields[$subtype.'_raw_one_based_ref']=$ref;
                    $fields[$subtype.'_object_index']=$ref===0?null:$ref-1;
                }
                if($isDB && in_array($key,['verts','font'],true)){
                    $stride=$key==='verts'?8:16;
                    if($fields['record_count']>intdiv(PHP_INT_MAX,$stride)
                        ||$fields['record_count']*$stride!==(int)$obj['file_data_size'])
                        throw new RuntimeException('U'.$nativeName.' source FRecord length mismatch');
                    $fields['source_record_bytes']=$stride;
                    $fields['source_data_length']=(int)$obj['file_data_size'];
                    if($fields['record_count']>65536)
                        throw new RuntimeException('U'.$nativeName.' record count exceeds safe decoded record budget');
                    if(fseek($fp,(int)$obj['file_data_offset'],SEEK_SET)!==0)
                        throw new RuntimeException('Cannot seek U'.$nativeName.' data records');
                    $records=[];
                    $signed=static fn(int $word):int=>
                        $word>=0x80000000?$word-0x100000000:$word;
                    for($i=0;$i<$fields['record_count'];$i++){
                        $part=fread($fp,$stride);
                        if(!is_string($part)||strlen($part)!==$stride)
                            throw new RuntimeException('Truncated U'.$nativeName.' record');
                        if($key==='verts'){
                            $v=array_values(unpack('V2',$part));
                            $records[]=['index'=>$i,
                                'point_vertex'=>$signed($v[0]),'side'=>$signed($v[1])];
                        }else{
                            $v=array_values(unpack('V4',$part));
                            $records[]=['index'=>$i,
                                'start_u'=>$signed($v[0]),'start_v'=>$signed($v[1]),
                                'u_size'=>$signed($v[2]),'v_size'=>$signed($v[3])];
                        }
                    }
                    $fields['record_values']=$records;
                }
                $extra=$left-$pos;
                if($strict&&$extra!==0)
                    throw new RuntimeException('Unexpected U'.$nativeName.' header trailing bytes');
                $result[]=array_merge($row,$fields,[
                    'status'=>$extra===0?'native_special_header_decoded':'native_special_header_extra_bytes',
                    'serialized_member_bytes'=>$pos,'unconsumed_member_bytes'=>$extra,
                    'runtime_references_resolved'=>false,
                    'class_specific_data_decoded'=>in_array($key,['verts','font'],true)
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','special_headers'=>$result,
            'runtime_references_resolved'=>false,
            'class_specific_data_decoded'=>false];
    }
}
