<?php
/**
 * Unreal v0.867 UPolys UDatabase/FPoly::operator<< archive.
 * Epic Src/Inc/UnObj.h near 1026-1046 and 1065-1078.
 * FPoly serialized length is 82 + 12*NumVertices (MAX_VERTICES=16),
 * NOT sizeof(FPoly) and never its unused FVector[16] slots.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfilePolysDataReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stack=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $by=[];foreach($stack as $s)$by[(int)$s['object_index']]=$s;
        $refMax=(int)$meta['summary']['object_count'];
        $nameMax=count($meta['names']);$rows=[];
        $fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original UPolys archive');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Polys')!==0)continue;
                $s=$by[(int)$o['index']]??[];
                $partial=['object_index'=>(int)$o['index'],'source_class'=>'UPolys',
                    'status'=>'incomplete_original_polys_header',
                    'runtime_references_resolved'=>false];
                if(($s['status']??'')!=='native_stack_prefix_decoded'
                    ||(int)($s['class_member_bytes_remaining']??0)<8){
                    if($strict)throw new RuntimeException('UPolys original UDatabase header missing');
                    $rows[]=$partial;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('Cannot seek UPolys Num/Max');
                $hdr=fread($fp,8);
                if(!is_string($hdr)||strlen($hdr)!==8)
                    throw new RuntimeException('Truncated UPolys Num/Max');
                $fields=unpack('Vnum/Vmax',$hdr);
                $num=(int)$fields['num'];$max=(int)$fields['max'];
                if($num>$max||$num>100000||$max>100000)
                    throw new RuntimeException('Invalid UPolys Num/Max');
                $extra=(int)$s['class_member_bytes_remaining']-8;
                if($strict&&$extra!==0)
                    throw new RuntimeException('Unexpected source UPolys header member');
                $length=(int)$o['file_data_size'];
                if($num>intdiv($length,82)||$length>67108864)
                    throw new RuntimeException('UPolys archive cannot contain Num FPoly records');
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek UPolys archive data');
                $data='';
                while(strlen($data)<$length){
                    $part=fread($fp,min(65536,$length-strlen($data)));
                    if(!is_string($part)||$part==='')throw new RuntimeException('Truncated UPolys archive');
                    $data.=$part;
                }
                $pos=0;
                $take=static function(int $n)use($data,&$pos):string{
                    if($n<0||$n>strlen($data)-$pos)
                        throw new RuntimeException('FPoly archive record truncated');
                    $s=substr($data,$pos,$n);$pos+=$n;return $s;
                };
                $u32=static fn():int=>(int)unpack('V',$take(4))[1];
                $u16=static fn():int=>(int)unpack('v',$take(2))[1];
                $signed=static fn(int $n):int=>$n>=0x80000000?$n-0x100000000:$n;
                $signed16=static fn(int $n):int=>$n>=0x8000?$n-0x10000:$n;
                $vec=static fn():array=>array_values(unpack('g3',$take(12)));
                $records=[];
                for($i=0;$i<$num;$i++){
                    $vCount=$signed($u32());
                    if($vCount<0||$vCount>16)
                        throw new RuntimeException('FPoly NumVertices exceeds MAX_VERTICES');
                    $base=$vec();$normal=$vec();$texU=$vec();$texV=$vec();
                    $vertices=[];
                    for($j=0;$j<$vCount;$j++)$vertices[]=$vec();
                    $flags=$u32();$brush=$u32();$texture=$u32();
                    if($brush>$refMax||$texture>$refMax)
                        throw new RuntimeException('FPoly brush/texture reference exceeds ResMap');
                    $group=$u16();$item=$u16();
                    if($group>$nameMax||$item>$nameMax)
                        throw new RuntimeException('FPoly name reference exceeds NameMap');
                    $link=$signed($u32());$brushPoly=$signed($u32());
                    $panU=$signed16($u16());$panV=$signed16($u16());
                    $zoneBytes=$take(2);
                    $records[]=['index'=>$i,'num_vertices'=>$vCount,
                        'base'=>$base,'normal'=>$normal,'texture_u'=>$texU,'texture_v'=>$texV,
                        'vertices'=>$vertices,'poly_flags'=>$flags,
                        'brush_ref'=>$brush,'texture_ref'=>$texture,
                        'group_name_ref'=>$group,'item_name_ref'=>$item,
                        'group_name'=>$group===0?null:$meta['names'][$group-1]['name'],
                        'item_name'=>$item===0?null:$meta['names'][$item-1]['name'],
                        'link_index'=>$link,'brush_poly_index'=>$brushPoly,
                        'pan_u'=>$panU,'pan_v'=>$panV,
                        'zone_indices'=>[ord($zoneBytes[0]),ord($zoneBytes[1])]];
                }
                if($pos!==$length)throw new RuntimeException('UPolys FArchive records leave trailing bytes');
                $rows[]=array_merge($partial,[
                    'status'=>'native_fpoly_records_decoded','record_count'=>$num,
                    'record_capacity'=>$max,'serialized_data_bytes'=>$pos,
                    'records'=>$records,'runtime_references_resolved'=>false]);
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','polygon_records'=>$rows,
            'runtime_references_resolved'=>false];
    }
}
