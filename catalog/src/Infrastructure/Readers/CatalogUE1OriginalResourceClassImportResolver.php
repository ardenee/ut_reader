<?php
/**
 * Source-conservative 1996 UClass import provider discovery.
 *
 * Epic v0.84a UnRes.cpp::AddFile resolves an import using
 * PrivateLookup(Name, ResType), then appends it to file ResMap before exports.
 *
 * A static reader has no global loaded resource registry. This resolver
 * accepts ONLY a unique (case-insensitive name, RES_Class=10) match exported
 * by an original v9/v10 resource file in the SAME directory. It returns
 * class descriptors and serialized default actor, not a linked UClass.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;
use UnrealDb\Catalog\Domain\Package\CatalogUE1OriginalResourceFormat;

final class CatalogUE1OriginalResourceClassImportResolver
{
    /** @return array<string,mixed> */
    public static function discover(string $path,array $imports):array
    {
        $base=realpath($path);
        if($base===false||!is_file($base))
            throw new RuntimeException('Original class importer source is not a file');
        $dir=dirname($base);
        $wanted=[];
        foreach($imports as $import){
            if((int)$import['resource_type']===10){
                $name=(string)$import['name'];
                if($name==='')continue;
                $wanted[(int)$import['index']]=$name;
            }
        }
        if($wanted===[])return ['class_providers'=>[],'diagnostics'=>[]];
        $candidates=[];
        $errors=[];
        foreach(new \DirectoryIterator($dir) as $entry){
            if(!$entry->isFile())continue;
            $other=$entry->getRealPath();
            if($other===false||strcasecmp($other,$base)===0
                ||strcasecmp(dirname($other),$dir)!==0)continue;
            try{
                $ident=CatalogUE1OriginalResourceFormat::identify($other);
                if(!is_array($ident)||($ident['format']??'')!=='early_unreal_resource'
                    ||!in_array((int)($ident['version']??-1),[9,10],true))
                    continue;
                $meta=CatalogUE1OriginalResourceTableReader::read($other);
            }catch(RuntimeException){continue;}
            foreach($meta['exports'] as $export){
                if((int)$export['resource_type']!==10)continue;
                foreach($wanted as $importId=>$name){
                    if(strcasecmp((string)$export['name'],$name)!==0)continue;
                    $candidates[$importId][]=[$other,(int)$export['index']];
                }
            }
        }
        $providers=[];$diag=[];
        foreach($wanted as $index=>$name){
            $list=$candidates[$index]??[];
            if(count($list)!==1){
                $diag[]=['import_index'=>$index,'name'=>$name,
                    'status'=>count($list)===0?
                        'source_UClass_import_provider_not_found':
                        'source_UClass_import_provider_ambiguous'];
                continue;
            }
            [$providerPath,$exportIndex]=$list[0];
            try{
                $meta=CatalogUE1OriginalResourceHeaderReader::read($providerPath);
                $h=$meta['resource_headers'][$exportIndex]??null;
                if($h===null||(int)$h['resource_type']!==10
                    ||(int)$h['serialized_length']<1272
                    ||!$h['name_matches_table']||!$h['type_matches_table'])
                    throw new RuntimeException('Imported source UClass header mismatch');
                $tables=CatalogUE1OriginalResourceClassPropertyReader::read($providerPath,false)
                    ['class_property_tables'];
                $matched=null;
                foreach($tables as $table){
                    if((int)$table['export_index']===$exportIndex
                        && ($table['status']??null)==='native_UClass_property_records_decoded'){
                        $matched=$table['properties'];break;
                    }
                }
                if($matched===null)throw new RuntimeException('Imported source UClass FClassProperty records incomplete');
                $fh=fopen($providerPath,'rb');
                if($fh===false)throw new RuntimeException('Cannot open original class provider');
                try{
                    if(fseek($fh,(int)$h['serialized_offset']+124,SEEK_SET)!==0)
                        throw new RuntimeException('Provider default actor seek failed');
                    $bytes=fread($fh,1148);
                    if(!is_string($bytes)||strlen($bytes)!==1148)
                        throw new RuntimeException('Truncated provider default actor');
                }finally{fclose($fh);}
                $providers[$index]=[
                    'raw_default_actor'=>$bytes,
                    'properties'=>$matched,
                    'provider_file_sha256'=>hash_file('sha256',$providerPath),
                    'provider_file_name'=>basename($providerPath),
                    'provider_export_index'=>$exportIndex,
                    'resource_name'=>$name
                ];
                $diag[]=['import_index'=>$index,'name'=>$name,
                    'status'=>'unique_source_UClass_provider_decoded'];
            }catch(RuntimeException){
                $diag[]=['import_index'=>$index,'name'=>$name,
                    'status'=>'source_UClass_provider_structure_invalid'];
            }
        }
        return ['class_providers'=>$providers,'diagnostics'=>$diag,
            'runtime_registry_lookup_performed'=>false];
    }
}
