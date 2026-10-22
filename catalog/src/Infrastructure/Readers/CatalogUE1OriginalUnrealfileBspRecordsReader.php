<?php
/**
 * Epic original v0.867 FBspNode / FBspSurf FArchive operators.
 * Src/Inc/UnObj.h:692-700,795-805; UDatabase::SerializeData emits Num
 * records, NOT an in-memory fixed C++ sizeof(FBspNode) dump.
 *
 * FBspNode: FPlane 16, ZoneMask QWORD 8, NodeFlags BYTE 1,
 * 7 INDEX 28, iZone[2] 2, NumVertices BYTE 1 => 56 bytes.
 * FBspSurf: 9 DWORD/INDEX/reference 36, PanU/PanV SWORD 4,
 * Actor reference 4 => 44 bytes.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileBspRecordsReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $by=[];foreach($stacks as $s)$by[(int)$s['object_index']]=$s;
        $objectCount=(int)$meta['summary']['object_count'];
        $result=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open source BSP records');
        try {
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export')continue;
                $type=strtolower((string)$o['class_name']);
                if($type!=='bspnodes' && $type!=='bspsurfs')continue;
                $s=$by[(int)$o['index']]??[];
                $row=['object_index'=>(int)$o['index'],'source_class'=>$type==='bspnodes'?'UBspNodes':'UBspSurfs',
                    'status'=>'incomplete_native_bsp_records','runtime_references_resolved'=>false];
                if(($s['status']??'')!=='native_stack_prefix_decoded'
                    ||(int)($s['class_member_bytes_remaining']??0)<8){
                    if($strict)throw new RuntimeException('BSP UDatabase Num/Max missing');
                    $result[]=$row;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('BSP native header seek failed');
                $h=fread($fp,8);
                if(!is_string($h)||strlen($h)!==8)throw new RuntimeException('BSP truncated Num/Max');
                $n=unpack('Vnum/Vmax',$h);
                $num=(int)$n['num'];$max=(int)$n['max'];
                if($num>$max||$num>100000||$max>100000)
                    throw new RuntimeException('BSP invalid Num/Max');
                $stride=$type==='bspnodes'?56:44;
                if($num*$stride!==(int)$o['file_data_size'])
                    throw new RuntimeException('BSP archive Num does not match record byte count');
                if($type==='bspnodes'){
                    $special=CatalogUE1OriginalUnrealfileSpecialHeadersReader::read($path,false);
                    $match=null;
                    foreach($special['special_headers'] as $item)
                        if((int)$item['object_index']===(int)$o['index']){$match=$item;break;}
                    if(($match['status']??null)!=='native_special_header_decoded')
                        throw new RuntimeException('BSP zone metadata must decode before nodes');
                }else{
                    $extra=(int)$s['class_member_bytes_remaining']-8;
                    if($strict&&$extra!==0)
                        throw new RuntimeException('BspSurfs unexpected native header bytes');
                }
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek native BSP records');
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $s16=static fn(int $v):int=>$v>=0x8000?$v-0x10000:$v;
                $rows=[];
                for($i=0;$i<$num;$i++){
                    $v=fread($fp,$stride);
                    if(!is_string($v)||strlen($v)!==$stride)
                        throw new RuntimeException('Truncated original BSP archive record');
                    if($type==='bspnodes'){
                        $plane=array_values(unpack('g4',substr($v,0,16)));
                        $mask=array_values(unpack('V2',substr($v,16,8)));
                        $flags=ord($v[24]);
                        $idx=array_map($signed,array_values(unpack('V7',substr($v,25,28))));
                        $zones=[ord($v[53]),ord($v[54])];
                        $vertices=ord($v[55]);
                        if($vertices>16)throw new RuntimeException('Original FBspNode NumVertices exceeds 16');
                        $rows[]=['index'=>$i,'plane'=>$plane,'zone_mask_words_le'=>$mask,
                            'node_flags'=>$flags,'vertex_pool_index'=>$idx[0],
                            'surface_index'=>$idx[1],'children'=>array_slice($idx,2,3),
                            'collision_bound'=>$idx[5],'render_bound'=>$idx[6],
                            'zone_indices'=>$zones,'num_vertices'=>$vertices];
                    }else{
                        $w=array_values(unpack('V9',substr($v,0,36)));
                        $p=array_values(unpack('v2',substr($v,36,4)));
                        $actor=(int)unpack('V',substr($v,40,4))[1];
                        if($w[0]>$objectCount||$w[1]>$objectCount||$actor>$objectCount)
                            throw new RuntimeException('BspSurf serialized object reference exceeds ResMap');
                        $rows[]=['index'=>$i,'texture_ref'=>$w[0],'brush_ref'=>$w[1],
                            'polygon_flags'=>$w[2],
                            'point_index'=>$signed($w[3]),'normal_index'=>$signed($w[4]),
                            'u_vector_index'=>$signed($w[5]),'v_vector_index'=>$signed($w[6]),
                            'light_mesh_index'=>$signed($w[7]),'brush_polygon_index'=>$signed($w[8]),
                            'pan_u'=>$s16($p[0]),'pan_v'=>$s16($p[1]),'actor_ref'=>$actor];
                    }
                }
                $result[]=array_merge($row,[
                    'status'=>'native_bsp_records_decoded',
                    'source_record_stride'=>$stride,'record_count'=>$num,
                    'record_capacity'=>$max,'records'=>$rows,
                    'runtime_references_resolved'=>false
                ]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','bsp_records'=>$result,
            'runtime_references_resolved'=>false];
    }
}
