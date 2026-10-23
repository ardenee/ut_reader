<?php
/**
 * Resolve a source-declared UE1 imported UStruct using its signed FPackageIndex
 * chain. Epic UT99 Core/Src/UnLinker.h::VerifyImport and GetImportFullName
 * resolve the imported object's name/class/outer, not just its short name.
 *
 * This file reader has no ULinkerLoad runtime package manager: only an actual
 * sibling Package.u with exact class/outer/export identity is accepted.
 * Ambiguous, missing, version-incompatible and external roots fail closed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicStructImportResolver
{
    /** @var array<string,CatalogUE1PackageReader> */
    private static array $packages=[];

    /** @return array{path:string,package:CatalogUE1PackageReader,ref:int}|null */
    public static function resolve(
        string $path,CatalogUE1PackageReader $pkg,int $reference
    ):?array {
        if($reference>=0){return null;}
        $imports=$pkg->getImports();
        $visited=[];$chain=[];$next=$reference;
        while($next<0){
            if(isset($visited[$next])||count($visited)>=64){return null;}
            $visited[$next]=true;
            $item=$imports[-$next-1]??null;
            if(!is_array($item)){return null;}
            $name=(string)($item['objectNameText']??'');
            $kind=(string)($item['classNameText']??'');
            $classPackage=(string)($item['classPackageText']??'');
            // These are native Core UClass/UStruct/UPackage kinds
            // in the pinned UT99 v400 family, not user-defined aliases.
            if(strcasecmp($classPackage,'Core')!==0){return null;}
            if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,127}$/D',$name)){
                return null;
            }
            $outer=(int)($item['outerIndex']??1);
            if(strcasecmp($kind,'Package')===0){
                if($outer!==0||!$chain){return null;}
                $packageName=$name;break;
            }
            // The root item must itself be a native Struct import.
            if(!$chain&&strcasecmp($kind,'Struct')!==0){return null;}
            if(!in_array(strtolower($kind),['struct','class'],true)){
                return null;
            }
            $chain[]=['name'=>$name,'kind'=>$kind];
            if($outer>=0){return null;}
            $next=$outer;
        }
        if(!isset($packageName)){return null;}
        $candidate=dirname($path).DIRECTORY_SEPARATOR.$packageName.'.u';
        if(!is_file($candidate)){return null;}
        $real=realpath($candidate);
        if($real===false
            ||strcasecmp((string)pathinfo($real,PATHINFO_FILENAME),$packageName)!==0){
            return null;
        }
        if(!isset(self::$packages[$real])){
            try{
                $target=new CatalogUE1PackageReader($real);
                if($target->validatePackage()!==[]){return null;}
                // Cross-version inheritance must not silently switch a
                // source implementation family on overlapping archive gates.
                $ver=(int)($target->getHeader()['version']??0);
                if($ver<62||$ver>69){return null;}
                self::$packages[$real]=$target;
            }catch(\RuntimeException){return null;}
        }
        $target=self::$packages[$real];
        $exports=$target->getExports();$imp=$target->getImports();
        $owner=0;
        foreach(array_reverse($chain) as $part){
            $matches=[];
            foreach($exports as $i=>$ex){
                if((int)($ex['outerIndex']??-999)!==$owner
                    ||strcasecmp((string)($ex['objectNameText']??''),$part['name'])!==0){
                    continue;
                }
                $classIndex=(int)($ex['classIndex']??0);
                $class=$classIndex===0?'Class':($classIndex<0
                    ?(string)($imp[-$classIndex-1]['objectNameText']??'')
                    :(string)($exports[$classIndex-1]['objectNameText']??''));
                $nativeCore=$classIndex===0;
                if($classIndex<0){
                    $classImport=$imp[-$classIndex-1]??null;
                    $nativeCore=is_array($classImport)
                        &&strcasecmp((string)($classImport['classPackageText']??''),'Core')===0
                        &&strcasecmp((string)($classImport['classNameText']??''),'Class')===0;
                }
                if($nativeCore&&strcasecmp($class,$part['kind'])===0){
                    $matches[]=$i+1;
                }
                if(count($matches)>1){return null;}
            }
            if(count($matches)!==1){return null;}
            $owner=$matches[0];
        }
        return $owner>0?['path'=>$real,'package'=>$target,'ref'=>$owner]:null;
    }
}
