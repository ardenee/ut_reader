<?php
/** Source-exact static VerifyImport projection for audited UE2 profiles only. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;

final class PdoUe2VerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    public const PROFILE_UNREAL2_V69_2000 = 'ue2-unreal2-2000-v69';
    public const PROFILE_UT2003_V2107 = 'ue2-ut2003-v2107';
    public const PROFILE_UT2004_V129 = 'ue2-ut2004-ut2004src-v129';


    /** @param list<array<string,mixed>> $consumerImports @return array<int,array<string,mixed>> */
    public static function resolveProviderOutcome(PDO $db,int $providerFileId,array $consumerImports,string $profile): array
    {
        if($providerFileId<1)return[];
        if(!function_exists('catalog_config'))throw new RuntimeException('Catalog configuration is required for authoritative UE2 VerifyImport resolution.');
        $storageRoot=trim((string)(\catalog_config()['storage_path']??''));
        if($storageRoot==='')throw new RuntimeException('Catalog storage_path is required for authoritative UE2 VerifyImport resolution.');
        $snapshot=(new BlockedCompressedMetadataSnapshotLoader($db,$storageRoot))->load($providerFileId);
        $file=(array)($snapshot['file']??[]);
        return self::resolveInMemoryOutcome(
            $profile,$consumerImports,array_values((array)($snapshot['imports']??[])),
            array_values((array)($snapshot['exports']??[])),(string)($file['package_name']??'')
        );
    }

    /**
     * @param list<array<string,mixed>> $consumerImports
     * @param list<array<string,mixed>> $providerImports
     * @param list<array<string,mixed>> $providerExports
     * @return array<int,array<string,mixed>>
     */
    public static function resolveInMemoryOutcome(
        string $profile,
        array $consumerImports,
        array $providerImports,
        array $providerExports,
        string $providerPackageName
    ): array {
        if (!in_array($profile,[self::PROFILE_UNREAL2_V69_2000,self::PROFILE_UT2003_V2107,self::PROFILE_UT2004_V129],true)) {
            throw new RuntimeException('Unsupported UE2 VerifyImport source profile: '.$profile);
        }
        $imports=self::indexImports($consumerImports);
        $providerImportMap=self::indexImports($providerImports);
        $exports=[];
        foreach($providerExports as$fallback=>$row){
            if(!is_array($row))continue;
            $index=isset($row['export_index'])?(int)$row['export_index']:(isset($row['index'])?(int)$row['index']:(int)$fallback);
            $row['export_index']=$index;
            $exports[$index]=$row+self::exportClassIdentity($row,$providerImportMap,$providerExports,$providerPackageName);
        }
        krsort($exports,SORT_NUMERIC); // ExportHash prepends each export; matching tuple visits later indices first.

        $results=[];$resolving=[];
        foreach(array_keys($imports)as$index){
            self::resolveOne($profile,$imports,$exports,(int)$index,$results,$resolving);
        }
        ksort($results,SORT_NUMERIC);
        return $results;
    }

    /** @param array<int,array<string,mixed>> $imports @param array<int,array<string,mixed>> $exports */
    private static function resolveOne(string $profile,array $imports,array $exports,int $index,array &$results,array &$resolving): array
    {
        if(isset($results[$index]))return $results[$index];
        if(isset($resolving[$index]))return $results[$index]=self::result('invalid','import_parent_cycle',false,null);
        $import=$imports[$index]??null;
        if(!is_array($import))return $results[$index]=self::result('invalid','import_index_unavailable',false,null);
        $resolving[$index]=true;
        if(self::hasNameNone($import)){
            unset($resolving[$index]);
            return $results[$index]=self::result('ignored','name_none',false,null);
        }

        $outer=(int)($import['outer_index']??$import['package_index']??0);
        $sourceLinker=false;$isPackageLinkerImport=false;$expectedOuter=null;
        if($outer===0){
            if(!self::same((string)($import['class_package']??''),'Core')||!self::same((string)($import['class_name']??''),'Package')){
                unset($resolving[$index]);
                return $results[$index]=self::result('invalid','root_import_is_not_core_package',false,null);
            }
            $sourceLinker=true;$isPackageLinkerImport=true;
        }elseif($outer<0){
            $parentIndex=-$outer-1;
            $parent=self::resolveOne($profile,$imports,$exports,$parentIndex,$results,$resolving);
            $sourceLinker=!empty($parent['source_linker']);
            if(!$sourceLinker){
                unset($resolving[$index]);
                $parentReason = match($profile) {
                    self::PROFILE_UNREAL2_V69_2000 => 'parent_source_linker_unavailable',
                    self::PROFILE_UT2003_V2107 => 'ut2003_parent_source_linker_unavailable_tolerated',
                    self::PROFILE_UT2004_V129 => 'ut2004_parent_source_linker_unavailable_tolerated',
                };
                return $results[$index]=self::result(
                    $profile===self::PROFILE_UNREAL2_V69_2000?'invalid':'unresolved',
                    $parentReason,
                    false,
                    null,
                    ['parent_import_index'=>$parentIndex]
                );
            }
            $parentSourceIndex=array_key_exists('source_index',$parent)&&$parent['source_index']!==null?(int)$parent['source_index']:null;
            $expectedOuter=$parentSourceIndex===null?0:$parentSourceIndex+1;
        }else{
            unset($resolving[$index]);
            return $results[$index]=self::result('invalid','positive_import_outer',false,null);
        }

        $match=self::findDirectMatch($profile,$import,$exports,$expectedOuter);
        $isMesh=self::same((string)($import['class_name']??''),'Mesh');
        if(($match['status']??'')==='private_export'){
            unset($resolving[$index]);
            return $results[$index]=$match+['source_linker'=>$sourceLinker];
        }
        if(($match['status']??'')==='resolved'&&!$isMesh){
            unset($resolving[$index]);
            return $results[$index]=$match+['source_linker'=>$sourceLinker];
        }
        $meshMatch=($match['status']??'')==='resolved'?$match:null;
        if($isMesh){
            $lodImport=$import;$lodImport['class_name']='LodMesh';
            $lod=self::findDirectMatch($profile,$lodImport,$exports,$expectedOuter);
            if(in_array((string)($lod['status']??''),['resolved','private_export'],true)){
                unset($resolving[$index]);
                $lod['reason']=($lod['status']??'')==='resolved'?'mesh_to_lodmesh_rehack':'private_export';
                return $results[$index]=$lod+['source_linker'=>$sourceLinker];
            }
            if(is_array($meshMatch)){
                unset($resolving[$index]);
                $meshMatch['reason']='mesh_exact_retained_after_lodmesh_rehack';
                return $results[$index]=$meshMatch+['source_linker'=>$sourceLinker];
            }
        }

        unset($resolving[$index]);
        if($isPackageLinkerImport)return $results[$index]=self::result('package_linker','top_level_package_linker',true,null);
        return $results[$index]=self::result(
            'runtime_only',
            match($profile) {
                self::PROFILE_UNREAL2_V69_2000 => 'unreal2_v69_runtime_native_transient_safe_replace_or_shareware_hack',
                self::PROFILE_UT2003_V2107 => 'ut2003_runtime_native_transient_safe_replace_or_forgiving',
                self::PROFILE_UT2004_V129 => 'ut2004_v129_runtime_native_transient_safe_replace_or_forgiving',
            },
            true,
            null
        );
    }

    /** @param array<int,array<string,mixed>> $exports */
    private static function findDirectMatch(string $profile,array $import,array $exports,?int $expectedOuter): array
    {
        foreach($exports as$export){
            if(!self::same((string)($export['object_name']??''),(string)($import['object_name']??'')))continue;
            if(!self::same((string)($export['class_name_resolved']??''),(string)($import['class_name']??'')))continue;
            $classPackageMatch=self::same((string)($export['class_package_resolved']??''),(string)($import['class_package']??''));
            if(!$classPackageMatch&&$profile===self::PROFILE_UNREAL2_V69_2000){
                $classPackageMatch=self::same((string)($import['class_package']??''),'UnrealI')
                    &&self::same((string)($export['class_package_resolved']??''),'UnrealShare');
            }
            if(!$classPackageMatch)continue;
            if($expectedOuter!==null){
                $actual=(int)($export['outer_index']??0);
                if($actual!==0&&$actual!==$expectedOuter)continue;
            }
            $exportIndex=(int)$export['export_index'];
            if((((int)($export['object_flags']??0))&self::RF_PUBLIC)===0){
                return self::result('private_export','private_export',true,null,['candidate_export_index'=>$exportIndex]);
            }
            $reason=$profile===self::PROFILE_UNREAL2_V69_2000
                &&!self::same((string)($export['class_package_resolved']??''),(string)($import['class_package']??''))
                ? 'unreal2_v69_unreali_unrealshare_class_package'
                : 'verify_import_match';
            return self::result('resolved',$reason,true,$exportIndex);
        }
        return self::result('not_found','verify_import_not_found',true,null);
    }

    /** @return array{class_name_resolved:string,class_package_resolved:string} */
    private static function exportClassIdentity(array $export,array $providerImports,array $providerExports,string $providerPackageName): array
    {
        $classIndex=(int)($export['class_index']??0);
        if($classIndex===0)return['class_name_resolved'=>'Class','class_package_resolved'=>'Core'];
        if($classIndex>0){
            $classExport=$providerExports[$classIndex-1]??null;
            return['class_name_resolved'=>is_array($classExport)?(string)($classExport['object_name']??''):'','class_package_resolved'=>$providerPackageName];
        }
        $classImport=$providerImports[-$classIndex-1]??null;
        if(!is_array($classImport))return['class_name_resolved'=>'','class_package_resolved'=>''];
        $className=(string)($classImport['object_name']??'');
        $outer=(int)($classImport['outer_index']??$classImport['package_index']??0);
        if($outer>=0)return['class_name_resolved'=>$className,'class_package_resolved'=>''];
        $packageImport=$providerImports[-$outer-1]??null;
        return['class_name_resolved'=>$className,'class_package_resolved'=>is_array($packageImport)?(string)($packageImport['object_name']??''):''];
    }

    /** @param list<array<string,mixed>> $rows @return array<int,array<string,mixed>> */
    private static function indexImports(array $rows): array
    {
        $out=[];foreach($rows as$fallback=>$row){if(!is_array($row))continue;$idx=isset($row['import_index'])?(int)$row['import_index']:(isset($row['index'])?(int)$row['index']:(int)$fallback);$out[$idx]=self::normalizeImport($row);}ksort($out,SORT_NUMERIC);return$out;
    }
    private static function normalizeImport(array $row): array
    {
        foreach(['class_package','class_name','object_name']as$field){$v=$row[$field]??'';if(is_array($v))$v=$v['text']??'';$row[$field]=(string)$v;}
        $row['outer_index']=(int)($row['outer_index']??$row['package_index']??0);return$row;
    }
    private static function hasNameNone(array $import): bool
    {
        foreach(['class_package','class_name','object_name']as$f)if(self::same((string)($import[$f]??''),'None'))return true;return false;
    }
    private static function same(string $a,string $b): bool
    {
        $lower=static fn(string$v):string=>function_exists('mb_strtolower')?mb_strtolower($v,'UTF-8'):strtolower($v);return $lower($a)===$lower($b);
    }
    /** @return array<string,mixed> */
    private static function result(string $status,string $reason,bool $sourceLinker,?int $sourceIndex,array $detail=[]): array
    {
        return ['status'=>$status,'reason'=>$reason,'source_linker'=>$sourceLinker,'source_index'=>$sourceIndex,'export_index'=>$sourceIndex]+$detail;
    }
}
