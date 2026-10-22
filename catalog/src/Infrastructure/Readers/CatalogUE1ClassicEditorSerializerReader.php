<?php
/**
 * UT99 v400 file-archive fields for UExporter and UFactory.
 *
 * Core/Src/UExporter.cpp: Super::Serialize, SupportedClass, TArray<FString> Formats.
 * Core/Src/UFactory.cpp:   Super::Serialize; SupportedClass and ContextClass
 *   only when !Ar.IsLoading() && !Ar.IsSaving() (GC/reference archives).
 *
 * Direct class-name dispatch only. Subclass inheritance is not inferred.
 * Does not instantiate or execute editor factory/exporter objects.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicEditorSerializerReader
{
    /**
     * @param callable(int):string $read Bounded to the export's serial extent
     * @param callable():int $compact Epic signed compact integer/FString size
     * @return array<string,mixed>
     */
    public static function inspect(
        string $kind, CatalogUE1PackageReader $pkg, int $version,
        callable $read, callable $compact, int &$pos, int $limit
    ): array {
        if($kind==='factory'){
            // Only the inherited UObject tag stream is on disk.
            return [
                'status'=>$pos===$limit?'native_factory_no_file_fields':
                    'native_factory_unexpected_tail',
                'file_fields_serialized'=>false,
                'gc_only_references'=>['SupportedClass','ContextClass'],
                'extension_bytes'=>$limit-$pos,
            ];
        }
        if(in_array($kind,['levelfactorynew','classfactorynew','texturefactorynew'],true)){
            // UT99 Editor/Src/UnEdFact.cpp; each Super::Serialize calls
            // UFactory::Serialize, whose extra refs are GC-only.
            $ref=static function(string $field)use($compact,$pkg):int{
                $v=$compact();
                if(($v<0 && -$v>count($pkg->getImports()))
                    ||($v>0 && $v>count($pkg->getExports()))){
                    throw new RuntimeException('UT99 '.$field.' package object reference invalid');
                }
                return $v;
            };
            $name=static function(string $field)use($compact,$pkg):array{
                $v=$compact();$names=$pkg->getNames();
                if($v<0||!isset($names[$v])){
                    throw new RuntimeException('UT99 '.$field.' FName outside name table');
                }
                return ['name_index'=>$v,'name'=>(string)$names[$v]['name']];
            };
            $signed=static function()use($read):int{
                $v=(int)unpack('V',$read(4))[1];
                return $v>=0x80000000?$v-0x100000000:$v;
            };
            $fstring=static function()use($compact,$read,$version,&$pos,$limit):string{
                $n=$compact();$wide=$n<0;
                if($wide&&$version<64){throw new RuntimeException('UT99 factory wide FString on ANSI archive');}
                $count=abs($n);$unit=$wide?2:1;
                if($count>intdiv($limit-$pos,$unit)){
                    throw new RuntimeException('UT99 factory FString outside object SerialSize');
                }
                $raw=$read($count*$unit);$null=$wide?"\x00\x00":"\x00";
                if($count>0&&!str_ends_with($raw,$null)){
                    throw new RuntimeException('UT99 factory FString missing terminator');
                }
                $body=$count===0?'':substr($raw,0,-strlen($null));
                $value=$body===''?'':@iconv($wide?'UTF-16LE':'Windows-1252','UTF-8',$body);
                if($value===false){throw new RuntimeException('UT99 factory FString invalid encoding');}
                return $value;
            };
            if($kind==='levelfactorynew'){
                $fields=['level_title'=>$fstring(),'author'=>$fstring(),
                    'close_existing_windows_bitfield'=>$signed()];
            }elseif($kind==='classfactorynew'){
                $fields=['class_name'=>$name('ClassName'),
                    'class_package_reference'=>$ref('ClassPackage'),
                    'superclass_reference'=>$ref('Superclass')];
            }else{
                $fields=['texture_name'=>$name('TextureName'),
                    'texture_package_reference'=>$ref('TexturePackage'),
                    'texture_class_reference'=>$ref('TextureClass'),
                    'u_size'=>$signed(),'v_size'=>$signed()];
            }
            return array_merge([
                'status'=>$pos===$limit?'native_'.$kind.'_record_complete':
                    'native_'.$kind.'_has_extension',
                'gc_only_factory_references_not_in_file'=>true,
                'extension_bytes'=>$limit-$pos,
            ],$fields);
        }
        if($kind!=='exporter'){
            throw new RuntimeException('Not a UT99 editor class serializer');
        }
        $supportedClass=$compact();
        if(($supportedClass<0&&-$supportedClass>count($pkg->getImports()))
            ||($supportedClass>0&&$supportedClass>count($pkg->getExports()))){
            throw new RuntimeException('UExporter SupportedClass reference outside package map');
        }
        $count=$compact();
        if($count<0||$count>$limit-$pos){
            throw new RuntimeException('UExporter Formats count exceeds export bounds');
        }
        $preview=[];$bytes=0;$widths=['ANSI'=>0,'UTF-16LE'=>0];
        $ctx=hash_init('sha256');
        for($i=0;$i<$count;$i++){
            $length=$compact();
            if($length===PHP_INT_MIN){
                throw new RuntimeException('UExporter FString signed count overflow');
            }
            $wide=$length<0;$charCount=abs($length);
            if($wide && $version<64){
                throw new RuntimeException('UExporter wide FString incompatible with legacy ANSI archive');
            }
            $bytesPerChar=$wide?2:1;
            if($charCount>intdiv($limit-$pos,$bytesPerChar)){
                throw new RuntimeException('UExporter FString exceeds export SerialSize');
            }
            $stringBytes=$charCount*$bytesPerChar;
            $raw=$read($stringBytes);
            $bytes+=$stringBytes;
            $encoding=$wide?'UTF-16LE':'ANSI';
            $widths[$encoding]++;
            hash_update($ctx,pack('V',$i));
            hash_update($ctx,$raw);
            $null=$wide?"\x00\x00":"\x00";
            if($charCount>0&&!str_ends_with($raw,$null)){
                throw new RuntimeException('UExporter FString lacks source-required NUL');
            }
            if(count($preview)<16){
                $body=$charCount===0?'':substr($raw,0,-strlen($null));
                $decoded=$body===''?'':@iconv($wide?'UTF-16LE':'Windows-1252','UTF-8',$body);
                if($decoded===false){
                    throw new RuntimeException('UExporter FString has invalid encoding');
                }
                $preview[]=['format'=>$decoded,'encoding'=>$encoding,
                    'code_units'=>$charCount];
            }
        }
        return [
            'status'=>$pos===$limit?'native_exporter_record_complete':'native_exporter_has_extension',
            'supported_class_reference'=>$supportedClass,
            'format_count'=>$count,'formats_preview'=>$preview,
            'format_data_bytes'=>$bytes,'format_encodings'=>$widths,
            'format_bytes_sha256'=>hash_final($ctx),
            'extension_bytes'=>$limit-$pos,
        ];
    }
}
