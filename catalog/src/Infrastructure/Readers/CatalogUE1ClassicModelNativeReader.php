<?php
/**
 * Classic UE1 UModel source-defined native archive.
 *
 * Unreal v1.200 Engine/Src/UnModel.cpp:291-332 and
 * UT99 v400 Engine/Src/UnModel.cpp:182-239. Inherits the real
 * UPrimitive FBox/FSphere serializer, not a guessed fixed offset.
 *
 * Historical object-referenced BSP (<=61) is decoded with the
 * source-defined lightmap, bounds, leaf and light tables. In modern
 * model arrays, nonempty FBspNode/FBspSurf/FVert and FZoneProperties
 * remain explicitly incomplete instead of shifting later fields.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicModelNativeReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $version,callable $read,
        callable $compact,callable $u32,int &$position,int $limit
    ):array{
        $result=CatalogUE1ClassicPrimitiveNativeReader::readBase(
            $version,$read,$position,$limit);
        $i32=static function()use($u32):int{
            $v=$u32();return $v>=0x80000000?$v-0x100000000:$v;
        };
        $f32=static fn():float=>(float)unpack('g',$read(4))[1];
        $vector=static fn():array=>['x'=>$f32(),'y'=>$f32(),'z'=>$f32()];
        $box=static fn():array=>['min'=>$vector(),'max'=>$vector(),
            'is_valid'=>ord($read(1))];
        $ref=static function(string $field)use($compact,$pkg):int{
            $value=$compact();
            if(($value<0&&-$value>count($pkg->getImports()))
                ||($value>0&&$value>count($pkg->getExports()))){
                throw new RuntimeException('Invalid UModel '.$field.' reference');
            }
            return $value;
        };
        $count=static function(string $field,int $minimumBytes=1)use($compact,&$position,$limit):int{
            $value=$compact();
            if($value<0||$minimumBytes<1||$value>intdiv($limit-$position,$minimumBytes)){
                throw new RuntimeException('Invalid UModel '.$field.' array count');
            }
            return $value;
        };
        $readMany=static function(string $field,callable $item,int $minimumBytes=1)
            use($count):array{
            $n=$count($field,$minimumBytes);
            $rows=[];
            for($i=0;$i<$n;$i++){$rows[]=$item();}
            return $rows;
        };
        $rawBytes=static function(string $field)use($count,$read,&$position):array{
            $n=$count($field);
            $start=$position;$hash=hash_init('sha256');$remaining=$n;
            while($remaining){
                $size=min($remaining,1024*1024);
                hash_update($hash,$read($size));
                $remaining-=$size;
            }
            return ['count'=>$n,'offset'=>$start,'sha256'=>hash_final($hash)];
        };
        $model=['archive_branch'=>$version<=61?'object_referenced_legacy':'inline_geometry',
            'runtime_bsp_links_resolved'=>false];
        if($version<=61){
            // Both sources serialize Vectors/Points/Nodes/Surfs/Verts/Polys
            // object indices in this order (v400 Polys after first five).
            foreach(['vectors','points','nodes','surfs','verts','polys'] as $field){
                $model[$field.'_reference']=$ref($field);
            }
        }else{
            // UT99 v400 TTransArray<> serializes as TArray<> for !IsTrans().
            $model['vectors']=$readMany('Vectors',$vector,12);
            $model['points']=$readMany('Points',$vector,12);
            foreach(['nodes','surfs','verts'] as $field){
                $n=$count($field);
                $model[$field.'_count']=$n;
                if($n!==0){
                    return $result+[
                        'status'=>'native_model_inline_geometry_unimplemented',
                        'model'=>$model,'unparsed_bytes'=>$limit-$position,
                    ];
                }
            }
            $model['num_shared_sides']=$i32();
            $zoneCount=$i32();
            if($zoneCount<0||$zoneCount>64){
                throw new RuntimeException('Invalid UModel NumZones');
            }
            $model['num_zones']=$zoneCount;
            if($zoneCount!==0){
                return $result+[
                    'status'=>'native_model_zone_properties_unimplemented',
                    'model'=>$model,'unparsed_bytes'=>$limit-$position,
                ];
            }
            $model['polys_reference']=$ref('Polys');
        }
        $model['light_map']=$readMany('LightMap',static function()use(
            $i32,$f32,$vector,$compact,$read,$version):array{
            $r=['data_offset'=>$i32()];
            if($version<=37){
                $r['pan_x']=$f32();$r['pan_y']=$f32();
                $r['u_clamp']=$compact();$r['v_clamp']=$compact();
                $spacing=$i32();$r['u_scale']=$spacing;$r['v_scale']=$spacing;
                $r['light_actor_index']=$i32();
                $r['old_mesh_shift']=ord($read(1));
            }else{
                $r['pan']=$vector();
                $r['u_clamp']=$compact();$r['v_clamp']=$compact();
                if($version<53){
                    $scale=$f32();$r['u_scale']=$scale;$r['v_scale']=$scale;
                }else{
                    $r['u_scale']=$f32();$r['v_scale']=$f32();
                }
                $r['light_actor_index']=$i32();
            }
            return $r;
        },18);
        $model['light_bits']=$rawBytes('LightBits');
        $model['bounds']=$readMany('Bounds',$box,25);
        $model['leaf_hulls']=$readMany('LeafHulls',$i32,4);
        $model['leaves']=$readMany('Leaves',static function()use($compact,$read):array{
            return [
                'zone_index'=>$compact(),
                'permeating_index'=>$compact(),
                'volumetric_index'=>$compact(),
                'visible_zones_hex'=>bin2hex($read(8)),
            ];
        },11);
        $model['lights']=$readMany('Lights',static fn():int=>$ref('Light'),1);
        if($version<=61){
            $model['legacy_leaf_zone_reference']=$ref('LeafZone');
            $model['legacy_leaf_leaf_reference']=$ref('LeafLeaf');
        }
        if($version<=41){
            $a=$i32();$b=$i32();$c=$i32();
            $model['old_flag_values']=[$a,$b,$c];
            $model['root_outside']=$a;$model['linked']=$c;
        }elseif($version<=42){
            $a=ord($read(1));$b=ord($read(1));$c=ord($read(1));
            $model['old_flag_values']=[$a,$b,$c];
            $model['root_outside']=$a;$model['linked']=$c;
        }elseif($version<=43){
            $a=ord($read(1));$b=ord($read(1));
            $model['old_flag_values']=[$a,$b];
            $model['root_outside']=$a;$model['linked']=$b;
        }else{
            $model['root_outside']=$i32();
            $model['linked']=$i32();
        }
        return $result+[
            'status'=>$position===$limit
                ?'native_model_record_complete':'native_model_has_extension',
            'model'=>$model,'extension_bytes'=>$limit-$position,
        ];
    }
}
