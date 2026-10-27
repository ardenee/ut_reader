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
        if($profile!==CatalogUE1ClassicSourceFamily::UNREAL_V227
            ||strtolower(basename($file))!=='engine.u')return null;
        $exp=$pkg->getExports();$imp=$pkg->getImports();
        $nativePkg=$pkg;$nativeFile=$file;$nativeIndex=null;
        $required=null;
        if($structReference>0&&$structName==='MeshSocketInfo'){
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
        }elseif($structReference<0&&$structName==='BoundingBox'){
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
            $meta=self::readNative($nativeFile,$nativePkg,$nativeIndex);
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
            try{$prop=self::readNative($nativeFile,$nativePkg,$next-1);}
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
            }
            $align=$type==='ByteProperty'?1:4;
            $byteSize=intdiv($byteSize+$align-1,$align)*$align+$dim*$element;
            $next=(int)($prop['next_field_reference']??0);
        }
        if($next!==0)return null;
        // Original v227k native UProperty struct allocation has 4-byte
        // tail alignment (BoundingBox 12+12+1 =>28).
        return intdiv($byteSize+3,4)*4;
    }

    /** @return array<string,mixed> */
    private static function readNative(
        string $file,CatalogUE1PackageReader $pkg,int $index
    ):array{
        $family=CatalogUE1ClassicSourceFamily::UNREAL_V227;
        return CatalogUE1ClassicNativeObjectReader::inspect(
            $file,$pkg,$index,
            CatalogUE1ClassicTaggedPropertyReader::inspect($file,$pkg,$index,$family),
            $family
        );
    }
}
