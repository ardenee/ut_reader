<?php
/**
 * Resolves UE1/UE2 Imports against the indexed projection of ULinkerLoad::VerifyImport identity.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;

require_once dirname(__DIR__) . '/Metadata/CatalogUnrealIdentityHash.php';

final class PdoLegacyVerifyImportProjectionResolver
{
    private const RF_PUBLIC = 0x00000004;
    private const HASH_BATCH_SIZE = 400;
    private const PRIVATE_FAILURE = -2147483648;

    /** @return array{standard:array<int,int>,unreal2:array<int,int>,unreal2_only:array<int,int>} */
    public static function resolveProviderVariants(PDO $db, int $providerFileId, array $consumerImports, array $classRemaps = []): array
    {
        if ($providerFileId < 1 || $consumerImports === []) return ['standard'=>[],'unreal2'=>[],'unreal2_only'=>[]];
        $imports=[]; $hashes=[];
        foreach ($consumerImports as $fallback=>$row) {
            if (!is_array($row)) continue;
            $index=isset($row['import_index'])?(int)$row['import_index']:(int)$fallback; $imports[$index]=$row;
            $objectName=trim((string)($row['object_name']??'')); $className=trim((string)($row['class_name']??'')); $classPackage=trim((string)($row['class_package']??''));
            if ($objectName===''||$className===''||$classPackage==='') continue;
            foreach (self::classNames($className,$classRemaps) as $candidateClass) {
                $hashes[bin2hex(self::identityHash($objectName,$candidateClass,$classPackage))]=true;
                if (self::key($candidateClass)==='mesh') $hashes[bin2hex(self::identityHash($objectName,'LodMesh',$classPackage))]=true;
            }
        }
        if ($hashes===[]) return ['standard'=>[],'unreal2'=>[],'unreal2_only'=>[]];
        $candidates=self::loadCandidates($db,$providerFileId,array_keys($hashes));
        $standard=self::resolveVariant($imports,$candidates,true,$classRemaps); $unreal2=self::resolveVariant($imports,$candidates,false,$classRemaps);
        $unreal2Only=[]; foreach($unreal2 as $i=>$e) if(!isset($standard[$i])) $unreal2Only[(int)$i]=(int)$e;
        return ['standard'=>$standard,'unreal2'=>$unreal2,'unreal2_only'=>$unreal2Only];
    }

    public static function resolveInMemoryVariants(array $consumerImports,array $providerImports,array $providerExports,string $providerPackageName,array $classRemaps=[]): array
    {
        $imports=[]; foreach($consumerImports as $fallback=>$row) if(is_array($row)) $imports[isset($row['import_index'])?(int)$row['import_index']:(int)$fallback]=$row;
        $providerImportsByIndex=[]; foreach($providerImports as $fallback=>$row) if(is_array($row)) $providerImportsByIndex[isset($row['import_index'])?(int)$row['import_index']:(int)$fallback]=$row;
        $providerExportsByIndex=[]; foreach($providerExports as $fallback=>$row) if(is_array($row)) $providerExportsByIndex[isset($row['export_index'])?(int)$row['export_index']:(int)$fallback]=$row;
        $candidates=[];
        foreach($providerExportsByIndex as $exportIndex=>$export){
            [$classPackage,$className]=self::exportClassIdentity($export,$providerImportsByIndex,$providerExportsByIndex,$providerPackageName);
            $objectName=trim((string)($export['object_name']??'')); if($objectName===''||$classPackage===''||$className==='') continue;
            $k=bin2hex(self::identityHash($objectName,$className,$classPackage));
            $candidates[$k][]=['export_index'=>(int)$exportIndex,'outer_index'=>(int)($export['outer_index']??0),'object_flags'=>(int)($export['object_flags']??0),'object_name'=>$objectName,'class_name'=>$className,'class_package'=>$classPackage];
        }
        foreach($candidates as &$rows) usort($rows,static fn(array $a,array $b):int=>$b['export_index']<=>$a['export_index']); unset($rows);
        $standard=self::resolveVariant($imports,$candidates,true,$classRemaps); $unreal2=self::resolveVariant($imports,$candidates,false,$classRemaps); $only=[];
        foreach($unreal2 as $i=>$e) if(!isset($standard[$i])) $only[(int)$i]=(int)$e;
        return ['standard'=>$standard,'unreal2'=>$unreal2,'unreal2_only'=>$only];
    }

    private static function exportClassIdentity(array $export,array $providerImports,array $providerExports,string $providerPackageName): array
    {
        $classIndex=(int)($export['class_index']??0);
        if($classIndex<0){$ci=$providerImports[-$classIndex-1]??null;if(!is_array($ci))return['',''];$cn=trim((string)($ci['object_name']??''));$co=(int)($ci['outer_index']??0);if($co>=0)return['',$cn];$cp=$providerImports[-$co-1]??null;return[is_array($cp)?trim((string)($cp['object_name']??'')):'',$cn];}
        if($classIndex>0){$ce=$providerExports[$classIndex-1]??null;return[trim($providerPackageName),is_array($ce)?trim((string)($ce['object_name']??'')):''];}
        return ['Core','Class'];
    }

    private static function resolveVariant(array $imports,array $candidates,bool $requirePublic,array $classRemaps): array
    {
        $resolved=[];$visiting=[];foreach(array_keys($imports) as $i) self::resolveImport((int)$i,$imports,$candidates,$requirePublic,$classRemaps,$resolved,$visiting);
        $matches=[];foreach($resolved as $i=>$e) if($e!==null&&$e!==self::PRIVATE_FAILURE)$matches[(int)$i]=(int)$e;return $matches;
    }

    private static function resolveImport(int $importIndex,array $imports,array $candidates,bool $requirePublic,array $classRemaps,array &$resolved,array &$visiting): ?int
    {
        if(array_key_exists($importIndex,$resolved))return $resolved[$importIndex]; if(isset($visiting[$importIndex]))return $resolved[$importIndex]=null;
        $import=$imports[$importIndex]??null;if(!is_array($import))return $resolved[$importIndex]=null;
        $objectName=trim((string)($import['object_name']??''));$className=trim((string)($import['class_name']??''));$classPackage=trim((string)($import['class_package']??''));
        if($objectName===''||$className===''||$classPackage==='')return $resolved[$importIndex]=null;
        $outerIndex=(int)($import['outer_index']??0);if($outerIndex===0||$outerIndex>0)return $resolved[$importIndex]=null;
        $visiting[$importIndex]=true;$parent=-$outerIndex-1;$parentSource=self::resolveImport($parent,$imports,$candidates,$requirePublic,$classRemaps,$resolved,$visiting);
        if($parentSource===self::PRIVATE_FAILURE){unset($visiting[$importIndex]);return $resolved[$importIndex]=self::PRIVATE_FAILURE;}
        $matched=null;
        // A configured ClassRemap makes the mapped class an additional valid class identity.
        // ObjectName, ClassPackage and outer identity remain unchanged.
        foreach(self::classNames($className,$classRemaps) as $candidateClass){
            $matched=self::findCandidate($candidates,self::identityHash($objectName,$candidateClass,$classPackage),$objectName,$candidateClass,$classPackage,$parentSource,$requirePublic);
            if($matched!==null)break;
            if(self::key($candidateClass)==='mesh'){
                $matched=self::findCandidate($candidates,self::identityHash($objectName,'LodMesh',$classPackage),$objectName,'LodMesh',$classPackage,$parentSource,$requirePublic);
                if($matched!==null)break;
            }
        }
        unset($visiting[$importIndex]);return $resolved[$importIndex]=$matched;
    }

    private static function classNames(string $className,array $classRemaps): array
    {
        $names=[$className];$mapped=trim((string)($classRemaps[self::key($className)]??''));
        if($mapped!==''&&self::key($mapped)!==self::key($className))$names[]=$mapped;
        return $names;
    }

    private static function findCandidate(array $candidates,string $identityHash,string $objectName,string $className,string $classPackage,?int $parentSourceIndex,bool $requirePublic): ?int
    {
        foreach($candidates[bin2hex($identityHash)]??[] as $candidate){
            if(CatalogUnrealIdentityHash::nameKey((string)$candidate['object_name'])!==CatalogUnrealIdentityHash::nameKey($objectName)||CatalogUnrealIdentityHash::nameKey((string)$candidate['class_name'])!==CatalogUnrealIdentityHash::nameKey($className)||CatalogUnrealIdentityHash::nameKey((string)$candidate['class_package'])!==CatalogUnrealIdentityHash::nameKey($classPackage))continue;
            $sourceOuter=(int)$candidate['outer_index'];if($parentSourceIndex===null){if($sourceOuter!==0)continue;}elseif($sourceOuter!==0&&$sourceOuter!==$parentSourceIndex+1)continue;
            if($requirePublic&&(((int)$candidate['object_flags']&self::RF_PUBLIC)===0))return self::PRIVATE_FAILURE;return(int)$candidate['export_index'];
        }return null;
    }

    private static function loadCandidates(PDO $db,int $providerFileId,array $hashHex): array
    {
        $result=[];foreach(array_chunk($hashHex,self::HASH_BATCH_SIZE) as $chunk){if($chunk===[])continue;$ph=implode(',',array_fill(0,count($chunk),'UNHEX(?)'));
            $s=$db->prepare('SELECT l.export_index,l.identity_hash,l.outer_index,l.object_flags,ot.value_prefix object_name,ct.value_prefix class_name,pt.value_prefix class_package FROM ue_legacy_export_identity_lookup l JOIN ue_terms ot ON ot.id=l.object_term_id JOIN ue_terms ct ON ct.id=l.class_name_term_id JOIN ue_terms pt ON pt.id=l.class_package_term_id WHERE l.file_id=? AND l.identity_hash IN ('.$ph.') ORDER BY l.export_index DESC');
            $s->execute(array_merge([$providerFileId],$chunk));while(($row=$s->fetch(PDO::FETCH_ASSOC))!==false){$k=bin2hex((string)$row['identity_hash']);$result[$k][]=['export_index'=>(int)$row['export_index'],'outer_index'=>(int)$row['outer_index'],'object_flags'=>(int)$row['object_flags'],'object_name'=>(string)$row['object_name'],'class_name'=>(string)$row['class_name'],'class_package'=>(string)$row['class_package']];}
        }return $result;
    }

    public static function identityHash(string $objectName,string $className,string $classPackage): string{return CatalogUnrealIdentityHash::verifyImportBinary($objectName,$className,$classPackage);}
    private static function key(string $value): string{return CatalogUnrealIdentityHash::nameKey($value);}
}
