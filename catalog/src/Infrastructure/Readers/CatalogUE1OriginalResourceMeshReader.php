<?php
/**
 * UE1 original 1996 UMesh v9/v10 class-native serializer.
 *
 * Epic v0.84a Src/Inc/UnRes.h, Src/Engine/UnMesh.cpp GetMeshResInfo:
 * Header: UResource 76, FVector Origin 16, FRotation 12, FBoundingRect
 * 32, five Num/Max INT pairs 40 -> 176 bytes.
 * Sections by Max capacities:
 * FMeshTriangle 16, packed FMeshVertex 4, FMeshVertLinkIndex 8,
 * WORD VertLink 2, FBoundingRect 32, FMeshAnimSeq 16.
 * FName references and mesh animation scripting remain unresolved.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceMeshReader
{
    private const HEADER=176;
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original UMesh');
        try{
            foreach($headers as $h){
                if((int)$h['resource_type']!==14)continue;
                $row=['export_index'=>(int)$h['export_index'],'source_class'=>'UMesh',
                    'status'=>'incomplete_source_mesh_header',
                    'runtime_name_references_resolved'=>false];
                if((int)$h['serialized_length']<self::HEADER){
                    if($strict)throw new RuntimeException('UMesh header is shorter than 176-byte source layout');
                    $out[]=$row;continue;
                }
                if(fseek($fp,(int)$h['serialized_offset']+76,SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UMesh header');
                $bytes=fread($fp,100);
                if(!is_string($bytes)||strlen($bytes)!==100)
                    throw new RuntimeException('Truncated native UMesh header');
                $origin=array_values(unpack('g3',substr($bytes,0,12)));
                $originW=(int)unpack('V',substr($bytes,12,4))[1];
                $rotation=array_values(unpack('V3',substr($bytes,16,12)));
                $boundMin=array_values(unpack('g3',substr($bytes,28,12)));
                $boundMinW=(int)unpack('V',substr($bytes,40,4))[1];
                $boundMax=array_values(unpack('g3',substr($bytes,44,12)));
                $boundMaxW=(int)unpack('V',substr($bytes,56,4))[1];
                $cap=array_values(unpack('V10',substr($bytes,60,40)));
                $names=['triangles','vertices','vertex_links','frames','sequences'];
                $counts=[];
                for($i=0;$i<5;$i++){
                    $num=(int)$cap[$i*2];$max=(int)$cap[$i*2+1];
                    if($num>$max||$max>10000000)
                        throw new RuntimeException('UMesh invalid Num/Max capacity: '.$names[$i]);
                    $counts[$names[$i]]=['num'=>$num,'max'=>$max];
                }
                $max=$counts;
                $layout=[
                    'triangles'=>[$max['triangles']['max'],16],
                    'vertices'=>[$max['frames']['max']*$max['vertices']['max'],4],
                    'vertex_link_indices'=>[$max['vertices']['max'],8],
                    'vertex_links'=>[$max['vertex_links']['max'],2],
                    'bounds'=>[$max['frames']['max'],32],
                    'animation_sequences'=>[$max['sequences']['max'],16],
                ];
                $ranges=[];$cursor=(int)$h['file_data_offset'];
                $total=0;
                foreach($layout as $kind=>[$count,$stride]){
                    if($count>intdiv(PHP_INT_MAX,$stride))
                        throw new RuntimeException('UMesh section integer overflow');
                    $length=$count*$stride;
                    $ranges[$kind]=['offset'=>$cursor,'size'=>$length,'stride'=>$stride,'capacity'=>$count];
                    $total+=$length;$cursor+=$length;
                }
                if($total!==(int)$h['file_data_size'])
                    throw new RuntimeException('UMesh GetMeshResInfo TotalMax differs from serialized byte length');
                $decode=static function(int $offset,int $count,int $stride,callable $fn)
                    use($fp):array{
                    if(fseek($fp,$offset,SEEK_SET)!==0)throw new RuntimeException('UMesh section seek failed');
                    $result=[];
                    for($i=0;$i<$count;$i++){
                        $data=fread($fp,$stride);
                        if(!is_string($data)||strlen($data)!==$stride)
                            throw new RuntimeException('Truncated UMesh native record');
                        $result[]=$fn($data,$i);
                    }
                    return $result;
                };
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $tri=$decode($ranges['triangles']['offset'],$max['triangles']['num'],16,
                    static function(string $v,int $i):array{
                        $vertex=array_values(unpack('v3',substr($v,0,6)));
                        return ['index'=>$i,'vertex_indices'=>$vertex,
                            'type'=>ord($v[6]),'color'=>ord($v[7]),
                            'texture_uv_bytes'=>array_values(unpack('C6',substr($v,8,6))),
                            'texture_number'=>ord($v[14]),'flags'=>ord($v[15])];
                    });
                $numAnimated=$max['frames']['num']*$max['vertices']['num'];
                // Vertex storage has MaxVertices * MaxFrames stride. Active
                // vertices must be indexed per frame using MaxVertices.
                $vert=[];
                foreach(range(0,max(0,$max['frames']['num']-1)) as $frame){
                    if($frame >= $max['frames']['num'])break;
                    $off=$ranges['vertices']['offset']+$frame*$max['vertices']['max']*4;
                    $v=$decode($off,$max['vertices']['num'],4,
                        static function(string $s,int $i)use($frame):array{
                            $w=(int)unpack('V',$s)[1];
                            $unpackSigned=static function(int $n,int $bits):int{
                                $mask=(1<<$bits)-1;$n&=$mask;$sign=1<<($bits-1);
                                return ($n&$sign)!==0?$n-($mask+1):$n;
                            };
                            return ['frame'=>$frame,'vertex_index'=>$i,
                                'x'=>$unpackSigned($w,11),
                                'y'=>$unpackSigned($w>>11,11),
                                'z'=>$unpackSigned($w>>22,10)];
                        });
                    array_push($vert,...$v);
                }
                $links=$decode($ranges['vertex_link_indices']['offset'],$max['vertices']['num'],8,
                    static function(string $v,int $i)use($signed):array{
                        $fields=array_values(unpack('V2',$v));
                        return ['vertex_index'=>$i,'num_triangles'=>$signed($fields[0]),
                            'triangle_list_offset'=>$signed($fields[1])];
                    });
                $linkWords=$decode($ranges['vertex_links']['offset'],$max['vertex_links']['num'],2,
                    static fn(string $v,int $i):int=>(int)unpack('v',$v)[1]);
                $bounds=$decode($ranges['bounds']['offset'],$max['frames']['num'],32,
                    static function(string $v,int $i):array{
                        $low=array_values(unpack('g3',substr($v,0,12)));
                        $high=array_values(unpack('g3',substr($v,16,12)));
                        return ['frame'=>$i,'min'=>$low,'max'=>$high,
                            'min_w_or_flags_raw_word'=>(int)unpack('V',substr($v,12,4))[1],
                            'max_w_or_flags_raw_word'=>(int)unpack('V',substr($v,28,4))[1]];
                    });
                $seq=$decode($ranges['animation_sequences']['offset'],$max['sequences']['num'],16,
                    static function(string $v,int $i)use($signed):array{
                        $w=array_values(unpack('V3',substr($v,4,12)));
                        return ['index'=>$i,'name_reference_raw_word'=>(int)unpack('v',substr($v,0,2))[1],
                            'start_frame'=>$signed($w[0]),'frame_count'=>$signed($w[1]),
                            'rate'=>$signed($w[2])];
                    });
                $out[]=['export_index'=>(int)$h['export_index'],'source_class'=>'UMesh',
                    'status'=>'native_mesh_records_decoded',
                    'header_bytes'=>self::HEADER,'origin'=>$origin,
                    'origin_w_or_flags_raw_word'=>$originW,
                    'rotation_raw_ints'=>array_map($signed,$rotation),
                    'bound_min'=>$boundMin,'bound_max'=>$boundMax,
                    'bound_min_w_or_flags_raw_word'=>$boundMinW,
                    'bound_max_w_or_flags_raw_word'=>$boundMaxW,
                    'counts'=>$counts,'sections'=>$ranges,
                    'triangles'=>$tri,'animated_vertices'=>$vert,'vertex_link_indices'=>$links,
                    'vertex_links'=>$linkWords,'frame_bounds'=>$bounds,'animation_sequences'=>$seq,
                    'runtime_name_references_resolved'=>false,
                    'internal_mesh_animation_evaluated'=>false];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','mesh_records'=>$out,
            'runtime_references_resolved'=>false];
    }
}
