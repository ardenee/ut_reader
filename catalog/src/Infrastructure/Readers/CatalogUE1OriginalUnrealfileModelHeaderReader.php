<?php
/**
 * Original Unrealfile v22/v23 UModel::SerializeHeader (Epic v0.867).
 *
 * UPrimitive::LocalBound = FBoundingVolume (44 archive bytes);
 * UModel: nine one-based UObject* refs (36), Location/Rotation/PrePivot
 * (36), Scale (FVector+FLOAT+ESheerAxis 20), CsgOper/Color/PolyFlags/
 * ModelFlags/RootOutside (20), PostPivot (12), PostScale/TempScale (40),
 * TransformedBound (44) => total 252 bytes after FExecStackMain.
 *
 * This is typed on-disk archive data, NOT an instantiated model or BSP
 * evaluation. Child arrays are resolved only as serialized references.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileModelHeaderReader
{
    private const NATIVE_BYTES=252;
    private const REF_NAMES=['Vectors','Points','Nodes','Surfs','Verts',
        'Polys','LightMesh','Bounds','LeafHulls'];

    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $total=(int)$meta['summary']['object_count'];
        $stack=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $lookup=[];foreach($stack as $s)$lookup[(int)$s['object_index']]=$s;
        $out=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Unable to open original UModel archive');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Model')!==0)continue;
                $s=$lookup[(int)$o['index']]??[];
                $partial=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UModel','status'=>'incomplete_original_model_header',
                    'runtime_model_constructed'=>false];
                if(($s['status']??null)!=='native_stack_prefix_decoded'
                   ||(int)($s['class_member_bytes_remaining']??0)<self::NATIVE_BYTES){
                    if($strict)throw new RuntimeException('Original UModel source archive member bytes incomplete');
                    $out[]=$partial;continue;
                }
                $off=(int)$o['file_header_offset']+(int)$s['serialized_prefix_bytes'];
                if(fseek($fp,$off,SEEK_SET)!==0)throw new RuntimeException('UModel header seek failure');
                $data=fread($fp,self::NATIVE_BYTES);
                if(!is_string($data)||strlen($data)!==self::NATIVE_BYTES)
                    throw new RuntimeException('Truncated original UModel header');
                $at=0;
                $take=static function(int $n)use($data,&$at):string{
                    if($n<0||$n>strlen($data)-$at)
                        throw new RuntimeException('UModel native archive exceeds member range');
                    $bytes=substr($data,$at,$n);$at+=$n;return $bytes;
                };
                $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                $bounding=static function()use($take):array{
                    $min=array_values(unpack('g3',$take(12)));
                    $max=array_values(unpack('g3',$take(12)));
                    $valid=(int)unpack('V',$take(4))[1];
                    if($valid!==0&&$valid!==1)
                        throw new RuntimeException('Original UModel bounding IsValid is not Boolean');
                    $sphere=array_values(unpack('g4',$take(16)));
                    return ['min'=>$min,'max'=>$max,'is_valid'=>$valid,
                        'sphere_center'=>array_slice($sphere,0,3),
                        'sphere_radius'=>$sphere[3]];
                };
                $scale=static function()use($take,$signed):array{
                    $v=array_values(unpack('g3',$take(12)));
                    $sheer=(float)unpack('g',$take(4))[1];
                    $axis=$signed((int)unpack('V',$take(4))[1]);
                    return ['xyz'=>$v,'sheer_rate'=>$sheer,'sheer_axis'=>$axis];
                };
                $local=$bounding();
                $refs=array_values(unpack('V9',$take(36)));
                $named=[];
                foreach(self::REF_NAMES as $i=>$name){
                    $ref=(int)$refs[$i];
                    if($ref>$total)throw new RuntimeException('Original UModel resource reference exceeds ResMap');
                    $named[$name]=['raw_one_based_ref'=>$ref,
                        'object_index'=>$ref===0?null:$ref-1];
                }
                $location=array_values(unpack('g3',$take(12)));
                $rotation=array_map($signed,array_values(unpack('V3',$take(12))));
                $prePivot=array_values(unpack('g3',$take(12)));
                $firstScale=$scale();
                $flags=array_values(unpack('V4',$take(16)));
                $root=$signed((int)unpack('V',$take(4))[1]);
                $postPivot=array_values(unpack('g3',$take(12)));
                $postScale=$scale();
                $tempScale=$scale();
                $transformed=$bounding();
                if($at!==self::NATIVE_BYTES)
                    throw new RuntimeException('UModel source serializer byte accounting mismatch');
                $remain=(int)$s['class_member_bytes_remaining']-self::NATIVE_BYTES;
                if($strict&&($remain!==0||(int)$o['file_data_size']!==0))
                    throw new RuntimeException('Unexpected UModel additional serialized header/data');
                $out[]=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'UModel',
                    'status'=>$remain===0&&(int)$o['file_data_size']===0
                        ?'native_model_header_decoded':'native_model_extra_bytes',
                    'local_bound'=>$local,'child_object_refs'=>$named,
                    'location'=>$location,'rotation_signed'=>$rotation,
                    'pre_pivot'=>$prePivot,'scale'=>$firstScale,
                    'csg_operator'=>(int)$flags[0],'color_raw_word'=>(int)$flags[1],
                    'polygon_flags'=>(int)$flags[2],'model_flags'=>(int)$flags[3],
                    'root_outside'=>$root,'post_pivot'=>$postPivot,
                    'post_scale'=>$postScale,'temporary_scale'=>$tempScale,
                    'transformed_bound'=>$transformed,
                    'native_header_bytes'=>self::NATIVE_BYTES,
                    'unconsumed_header_bytes'=>$remain,
                    'unconsumed_data_bytes'=>(int)$o['file_data_size'],
                    'runtime_model_constructed'=>false,
                    'runtime_child_references_resolved'=>false];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','model_headers'=>$out,
            'runtime_model_constructed'=>false];
    }
}
