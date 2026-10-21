<?php
/**
 * Explicit original Unrealfile v22/v23 imported-UClass property-chain resolver.
 *
 * Epic v0.867 serialized class imports use one-based ResMap references and
 * UClass::SerializeBin traverses current-class then parent-class FProperties.
 * Only source-declared dependency keys with caller-provided file paths are
 * eligible. There is NO directory guessing or in-memory UClass construction.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileExternalSchemaResolver
{
    /**
     * Source-declared same-directory providers only. This is a conservative
     * static metadata lookup, NOT the engine's global runtime file search.
     * No recursive walking, guessed paths, or ambiguous-name selection.
     *
     * @return array{providers:array<string,string>,diagnostics:array<int,array<string,string>>}
     */
    public static function discoverSiblingProviders(string $path): array
    {
        $real=self::canonical($path);
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($real);
        if($meta['dependencies']===[])return ['providers'=>[],'diagnostics'=>[]];
        $dir=dirname($real);
        $files=[];
        foreach(new \DirectoryIterator($dir) as $entry){
            if(!$entry->isFile())continue;
            $candidate=$entry->getRealPath();
            if($candidate===false || strcasecmp($candidate,$real)===0
                || strcasecmp(dirname($candidate),$dir)!==0)continue;
            $files[]=$candidate;
        }
        $providers=[];$diagnostics=[];$seen=[];
        foreach($meta['dependencies'] as $dependency){
            $dependency=(string)$dependency;
            if($dependency==='' || str_contains($dependency,'/')
                || str_contains($dependency,'\\') || str_contains($dependency,'..')){
                $diagnostics[]=['dependency'=>$dependency,'status'=>'unsafe_declared_dependency_name'];
                continue;
            }
            $exact=[];$stem=[];
            foreach($files as $candidate){
                $filename=basename($candidate);
                if(strcasecmp($filename,$dependency)===0)$exact[]=$candidate;
                if(strcasecmp(pathinfo($filename,PATHINFO_FILENAME),
                    pathinfo($dependency,PATHINFO_FILENAME))===0)$stem[]=$candidate;
            }
            $candidates=$exact!==[]?$exact:$stem;
            if(count($candidates)!==1){
                $diagnostics[]=['dependency'=>$dependency,
                    'status'=>count($candidates)===0
                        ? 'source_declared_sibling_unavailable'
                        : 'ambiguous_sibling_dependency'];
                continue;
            }
            try{
                CatalogUE1OriginalUnrealfileMetadataReader::read($candidates[0]);
            }catch(RuntimeException){
                $diagnostics[]=['dependency'=>$dependency,'status'=>'sibling_not_original_unrealfile'];
                continue;
            }
            $key=strtolower($dependency);
            if(isset($seen[$key])){
                $diagnostics[]=['dependency'=>$dependency,'status'=>'duplicate_declared_dependency'];
                unset($providers[$seen[$key]]);
                continue;
            }
            $seen[$key]=$dependency;
            $providers[$dependency]=$candidates[0];
            $diagnostics[]=['dependency'=>$dependency,'status'=>'source_declared_sibling_selected'];
        }
        return ['providers'=>$providers,'diagnostics'=>$diagnostics];
    }

    /**
     * @param array<string,string> $providers declared dependency name => file path
     * @return array<string,mixed> explicit status, property descriptors, provenance
     */
    public static function resolve(
        string $rootPath,
        int $importObjectIndex,
        array $providers
    ): array {
        $cache=[];
        $visited=[];
        $normalized=[];
        if(count($providers)>64) throw new RuntimeException('Too many original external class provider files');
        foreach($providers as $name=>$providerPath){
            if(!is_string($name)||trim($name)===''||!is_string($providerPath)||$providerPath===''){
                throw new RuntimeException('External class providers require dependency name => path');
            }
            $key=strtolower($name);
            if(isset($normalized[$key])) throw new RuntimeException('Ambiguous provider dependency key');
            $normalized[$key]=$providerPath;
        }
        $result=self::chain($rootPath,$importObjectIndex,$normalized,$cache,$visited,0);
        $result['runtime_class_loaded']=false;
        $result['runtime_resmap_linked']=false;
        return $result;
    }

    /** @return array<string,mixed> */
    private static function chain(
        string $file,
        int $index,
        array $providers,
        array &$cache,
        array &$visited,
        int $depth
    ): array {
        if($depth>32)throw new RuntimeException('Original external UClass ancestry exceeds bounded depth');
        $info=self::file($file,$cache);
        $objects=$info['objects'];
        if(!isset($objects[$index])){
            throw new RuntimeException('Imported native UClass reference outside ResMap');
        }
        $record=$objects[$index];
        if(strcasecmp((string)$record['class_name'],'Class')!==0){
            return self::missing('reference_is_not_a_UClass');
        }
        $key=$info['real_path'].'#'.$index;
        if(isset($visited[$key]))throw new RuntimeException('Original external UClass dependency cycle');
        $visited[$key]=true;
        try {
            if($record['kind']==='export'){
                $schema=$info['schemas'][$index]??null;
                if(!is_array($schema)||($schema['status']??null)!=='native_UClass_schema_decoded'){
                    return self::missing('exported_UClass_schema_incomplete');
                }
                $source=$file;
                $sourceIndex=$index;
            }else{
                // Original Unrealfile declares the files to load before ResMap
                // entries become usable. Explicit path bindings are compulsory.
                $matches=[];
                foreach($info['dependencies'] as $dependency){
                    $provider=$providers[strtolower((string)$dependency)]??null;
                    if($provider===null)continue;
                    $possible=self::file($provider,$cache);
                    foreach($possible['schemas'] as $candidateIndex=>$candidate){
                        if(($candidate['status']??null)==='native_UClass_schema_decoded'
                            && strcasecmp((string)$candidate['class_name'],(string)$record['name'])===0){
                            $matches[]=['file'=>$provider,'index'=>$candidateIndex,'schema'=>$candidate];
                        }
                    }
                }
                if(count($matches)!==1){
                    return self::missing(count($matches)===0
                        ?'declared_external_UClass_provider_unavailable'
                        :'ambiguous_external_UClass_provider');
                }
                $schema=$matches[0]['schema'];
                $source=$matches[0]['file'];
                $sourceIndex=(int)$matches[0]['index'];
                $externalKey=self::file($source,$cache)['real_path'].'#'.$sourceIndex;
                if(isset($visited[$externalKey])){
                    throw new RuntimeException('External UClass provider cycle');
                }
                // Reuse the provider export itself for parent traversal, without
                // recursing back through this imported ResMap entry.
                $visited[$externalKey]=true;
            }
            $properties=$schema['properties'];
            $sources=[[
                'file_sha256'=>$cache[self::canonical($source)]['sha256'],
                'source_filename'=>basename($source),
                'class_object_index'=>$sourceIndex,
                'class_name'=>$schema['class_name'],
            ]];
            $parent=(int)$schema['parent_class_ref'];
            if($parent!==0){
                $sourceInfo=self::file($source,$cache);
                if($parent>count($sourceInfo['objects'])){
                    throw new RuntimeException('External UClass parent ref exceeds provider ResMap');
                }
                $parentResult=self::chain($source,$parent-1,$providers,$cache,$visited,$depth+1);
                if(($parentResult['status']??null)!=='resolved'){
                    return self::missing('parent_'.$parentResult['status']);
                }
                $properties=array_merge($properties,$parentResult['property_descriptors']);
                $sources=array_merge($sources,$parentResult['sources']);
            }
            return ['status'=>'resolved','property_descriptors'=>$properties,'sources'=>$sources];
        }finally{
            unset($visited[$key]);
            if(isset($externalKey))unset($visited[$externalKey]);
        }
    }

    /** @return array<string,mixed> */
    private static function file(string $path,array &$cache):array
    {
        $real=self::canonical($path);
        if(isset($cache[$real]))return $cache[$real];
        $metadata=CatalogUE1OriginalUnrealfileObjectBaseReader::read($real);
        $schemas=CatalogUE1OriginalUnrealfileClassSchemaReader::read($real,false)['class_schemas'];
        $byIndex=[];
        foreach($schemas as $schema)$byIndex[(int)$schema['object_index']]=$schema;
        return $cache[$real]=[
            'real_path'=>$real,
            'sha256'=>hash_file('sha256',$real),
            'objects'=>$metadata['object_bases'],
            'dependencies'=>$metadata['dependencies'],
            'schemas'=>$byIndex,
        ];
    }

    private static function canonical(string $path):string
    {
        $resolved=realpath($path);
        if(!is_string($resolved)||!is_file($resolved)){
            throw new RuntimeException('Original class dependency provider is not a file');
        }
        return $resolved;
    }

    /** @return array<string,mixed> */
    private static function missing(string $status):array
    {
        return ['status'=>$status,'property_descriptors'=>[],'sources'=>[]];
    }
}
