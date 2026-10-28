<?php
/**
 * Exact original Unreal 227k C++ UProperty link sizes for two source-defined
 * nested script structs. Verify the physical original Core.u/Engine.u fields,
 * owners, types, array dimensions and nested native Core struct referents
 * before returning any size. Never trust only a structure's name.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicFunctionStructShapeReader
{
    /** @return int|null */
    public static function elementSize(
        string $file,CatalogUE1PackageReader $pkg,int $structReference,
        ?string $structName,string $profile
    ):?int{
        if(!in_array($profile,[
            CatalogUE1ClassicSourceFamily::UNREAL_V227,
            CatalogUE1ClassicSourceFamily::UT99_V469F,
        ],true)||strtolower(basename($file))!=='engine.u')return null;
        $exp=$pkg->getExports();$imp=$pkg->getImports();
        $nativePkg=$pkg;$nativeFile=$file;$nativeIndex=null;
        $required=null;
        if($profile===CatalogUE1ClassicSourceFamily::UT99_V469F
            &&$structReference>0&&$structName==='HUDLocalizedMessage'
            &&(int)($pkg->getHeader()['version']??0)===69
            &&(int)($pkg->getHeader()['licenseeVersion']??0)===0){
            $nativeIndex=$structReference-1;
            // The original 469f SDK udemo/inc/udemoprivate.h declares
            // HUDLocalizedMessage with these exact 14 members, in this
            // precise order. The original Engine.u Struct/Children chain
            // must confirm every field and Core.Color identity; a name
            // match alone never authorizes these binary sizes.
            $required=[
                ['Message','ClassProperty',1,null,4],
                ['Switch','IntProperty',1,null,4],
                ['RelatedPRI','ObjectProperty',1,null,4],
                ['OptionalObject','ObjectProperty',1,null,4],
                ['EndOfLife','FloatProperty',1,null,4],
                ['LifeTime','FloatProperty',1,null,4],
                ['bDrawing','BoolProperty',1,null,4],
                ['numLines','IntProperty',1,null,4],
                ['StringMessage','StrProperty',1,null,12],
                ['DrawColor','StructProperty',1,'Color',4],
                ['StringFont','ObjectProperty',1,null,4],
                ['XL','FloatProperty',1,null,4],
                ['YL','FloatProperty',1,null,4],
                ['YPos','FloatProperty',1,null,4],
            ];
        }elseif($profile===CatalogUE1ClassicSourceFamily::UNREAL_V227
            &&$structReference>0&&$structName==='MeshSocketInfo'){
            $nativeIndex=$structReference-1;
            // Exact declared Engine.MeshSocketInfo struct, including its
            // fixed-size array and two Core native math members.
            $required=[
                ['iVerts','IntProperty',3,null,4],
                ['BoneName','NameProperty',1,null,4],
                ['SocketName','NameProperty',1,null,4],
                ['Offset','StructProperty',1,'Vector',12],
                ['RotOffset','StructProperty',1,'Rotator',12],
            ];
        }elseif($profile===CatalogUE1ClassicSourceFamily::UNREAL_V227
            &&$structReference<0&&$structName==='BoundingBox'){
            $import=$imp[-$structReference-1]??null;
            if(!is_array($import)
                ||($import['classPackageText']??null)!=='Core'
                ||($import['classNameText']??null)!=='Struct'
                ||($import['objectNameText']??null)!=='BoundingBox')return null;
            $core=dirname($file).DIRECTORY_SEPARATOR.'Core.u';
            if(!is_file($core))return null;
            try{$nativePkg=new CatalogUE1PackageReader($core);}
            catch(\Throwable){return null;}
            $nativeFile=$core;
            $matching=[];
            $ce=$nativePkg->getExports();$ci=$nativePkg->getImports();
            foreach($ce as $i=>$entry){
                if(($entry['objectNameText']??null)!=='BoundingBox')continue;
                $cls=(int)($entry['classIndex']??0);
                $classname=$cls<0?($ci[-$cls-1]['objectNameText']??null)
                    :($cls>0?($ce[$cls-1]['objectNameText']??null):'Class');
                if($classname==='Struct')$matching[]=$i;
            }
            if(count($matching)!==1)return null;
            $nativeIndex=$matching[0];
            $required=[
                ['Min','StructProperty',1,'Vector',12],
                ['Max','StructProperty',1,'Vector',12],
                ['IsValid','ByteProperty',1,null,1],
            ];
        }else return null;
        $exports=$nativePkg->getExports();$imports=$nativePkg->getImports();
        $obj=$exports[$nativeIndex]??null;
        if(!is_array($obj))return null;
        $cl=(int)($obj['classIndex']??0);
        $kind=$cl<0?($imports[-$cl-1]['objectNameText']??null)
            :($cl>0?($exports[$cl-1]['objectNameText']??null):'Class');
        if($kind!=='Struct')return null;
        try{
            $meta=self::readNative($nativeFile,$nativePkg,$nativeIndex,$profile);
        }catch(\Throwable){return null;}
        if(($meta['status']??null)!=='native_struct_record_complete'
            ||($meta['super_field_reference']??null)!==0
            ||($meta['script_size']??null)!==0)return null;
        $next=(int)($meta['children_reference']??0);
        $byteSize=0;$seen=[];
        foreach($required as [$name,$type,$dim,$nested,$element]){
            if($next<=0||isset($seen[$next]))return null;
            $seen[$next]=true;
            $field=$exports[$next-1]??null;
            if(!is_array($field)||($field['objectNameText']??null)!==$name
                ||(int)($field['outerIndex']??0)!==$nativeIndex+1)return null;
            $class=(int)($field['classIndex']??0);
            $actual=$class<0?($imports[-$class-1]['objectNameText']??null)
                :($class>0?($exports[$class-1]['objectNameText']??null):'Class');
            if($actual!==$type)return null;
            try{$prop=self::readNative($nativeFile,$nativePkg,$next-1,$profile);}
            catch(\Throwable){return null;}
            if(($prop['status']??null)!=='native_property_record_complete'
                ||($prop['array_dim']??null)!==$dim)return null;
            if($nested!==null){
                $ref=(int)($prop['subclass_fields']['struct_reference']??0);
                $target=$ref>0?($exports[$ref-1]??null)
                    :($ref<0?($imports[-$ref-1]??null):null);
                if(($target['objectNameText']??null)!==$nested)return null;
                if($ref<0&&(($target['classPackageText']??null)!=='Core'
                    ||($target['classNameText']??null)!=='Struct'))return null;
                if($ref>0){
                    $targetClass=(int)($target['classIndex']??0);
                    $targetKind=$targetClass<0
                        ?($imports[-$targetClass-1]['objectNameText']??null)
                        :($targetClass>0?($exports[$targetClass-1]['objectNameText']??null):'Class');
                    if($targetKind!=='Struct')return null;
                }
            }
            $align=$type==='ByteProperty'?1:4;
            $byteSize=intdiv($byteSize+$align-1,$align)*$align+$dim*$element;
            $next=(int)($prop['next_field_reference']??0);
        }
        if($next!==0)return null;
        // Original v227k / 469f x86 native UProperty layout aligns
        // fields to 4, with a 4-byte tail alignment. A real
        // HUDLocalizedMessage contains 14 source-defined members = 64 bytes.
        return intdiv($byteSize+3,4)*4;
    }

    /** @return array<string,mixed> */
    private static function readNative(
        string $file,CatalogUE1PackageReader $pkg,int $index,string $family
    ):array{
        return CatalogUE1ClassicNativeObjectReader::inspect(
            $file,$pkg,$index,
            CatalogUE1ClassicTaggedPropertyReader::inspect($file,$pkg,$index,$family),
            $family
        );
    }
}
