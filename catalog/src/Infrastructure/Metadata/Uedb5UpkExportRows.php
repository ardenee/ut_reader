<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
final class Uedb5UpkExportRows {
    public static function fromSnapshot(array $snapshot): array {
        $sections=(array)($snapshot['sections']??[]);
        $imports=array_values((array)($sections['imports']??[]));
        $exports=array_values((array)($sections['exports']??[]));
        $package=(string)($snapshot['file']['package_name']??'');
        $name=static fn($v):string=>is_array($v)?(string)($v['text']??''):(is_string($v)?$v:'');
        $refName=static function(int $ref)use($imports,$exports,$name):string {
            return $ref<0?$name($imports[-$ref-1]['object_name']??null):($ref>0?$name($exports[$ref-1]['object_name']??null):'');
        };
        $cache=[];
        $path=static function(int $ref,array $seen=[])use(&$path,&$cache,$imports,$exports,$refName):string{
            if($ref===0||isset($seen[$ref]))return '';
            if(isset($cache[$ref]))return $cache[$ref];
            $seen[$ref]=true;
            $row=$ref<0?($imports[-$ref-1]??null):($exports[$ref-1]??null);
            if(!is_array($row))return '';
            $parent=$path((int)($row['outer_index']??0),$seen);
            $self=$refName($ref);
            return $cache[$ref]=$parent!==''&&$self!==''?$parent.'.'.$self:($self!==''?$self:$parent);
        };
        $rows=[];
        foreach($exports as $i=>$row){
            $row=(array)$row;$local=$path($i+1);
            $flags=$row['object_flags']??0;
            if(is_string($flags)&&preg_match('/^[0-9a-fA-F]{16}$/D',$flags))$flags=hexdec($flags);
            $rows[]=[
                'export_index'=>(int)($row['index']??$i),
                'class_name'=>$path((int)($row['class_index']??0)),
                'object_name'=>$refName($i+1),
                'outer_index'=>(int)($row['outer_index']??0),
                'local_path'=>$local,
                'full_path'=>$package!==''&&$local!==''?$package.'.'.$local:$local,
                'object_flags'=>(int)$flags,
                'serial_size'=>(int)($row['serial_size']??0),
                'serial_offset'=>(int)($row['serial_offset']??0),
            ];
        }
        return $rows;
    }
}