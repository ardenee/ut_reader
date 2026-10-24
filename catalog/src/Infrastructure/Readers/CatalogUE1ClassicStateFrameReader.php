<?php
/**
 * Source-defined UE1 UObject file execution-frame archive.
 *
 * Unreal v1.200 Core/Src/UnObj.cpp::UObject::Serialize:
 * - v35..39 legacy TempNum/TempMax are serialized by the caller first.
 * - v<47 always serializes FMainFrame, even without RF_HasStack.
 * - v<51 archives OldClass and, when non-null, a 32-bit iOldNode.
 * - v<52 archives the additional temporary class reference.
 * - v>=51 archives Node and StateNode; when Node is non-null the
 *   code offset is a compact AR_INDEX, after ProbeMask and LatentAction.
 * - v>=55 archives 32-bit LatentAction; old versions set it to zero.
 *
 * UT99 v400 Core/Src/UnObj.cpp::UObject::Serialize uses the
 * RF_HasStack-conditional FStateFrame branch for modern versions.
 *
 * This only parses persistent bytes; Preload(), frame construction,
 * script-byte-pointer fixups and runtime execution are not performed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicStateFrameReader
{
    private const RF_HAS_STACK=0x02000000;

    /**
     * Native UObject::Serialize validates the restored script offset against
     * StateFrame->Node->Script.Num(), not just whether the AR_INDEX is signed.
     * Only resolve the referenced native class when the archive actually
     * supplies a nonnegative offset. The common INDEX_NONE path performs
     * no package lookups. Missing dependencies remain UNKNOWN, never zero.
     */
    private static function referencedNodeScriptNum(
        CatalogUE1PackageReader $pkg,int $node,string $archivePath,string $family
    ):?int{
        static $cache=[];
        $targetPath=$archivePath;$targetName='';
        if($node>0){
            $entry=$pkg->getExports()[$node-1]??null;
            if(!is_array($entry)||(int)$entry['classIndex']!==0)return null;
            $targetName=(string)$entry['objectNameText'];
        }elseif($node<0){
            $imports=$pkg->getImports();
            $entry=$imports[-$node-1]??null;
            if(!is_array($entry))return null;
            $targetName=(string)$entry['objectNameText'];
            $outer=(int)$entry['outerIndex'];
            $root='';
            for($depth=0;$outer<0&&$depth<20;$depth++){
                $o=$imports[-$outer-1]??null;
                if(!is_array($o))return null;
                $root=(string)$o['objectNameText'];
                $outer=(int)$o['outerIndex'];
            }
            if($outer!==0||$root==='')return null;
            $directory=dirname($archivePath);
            $candidates=[
                $directory.DIRECTORY_SEPARATOR.$root.'.u',
                dirname($directory).DIRECTORY_SEPARATOR.'System'
                    .DIRECTORY_SEPARATOR.$root.'.u',
            ];
            $targetPath='';
            foreach($candidates as $candidate){
                if(is_file($candidate)){$targetPath=$candidate;break;}
            }
            if($targetPath==='')return null;
        }else return null;

        $key=$targetPath.'|'.strtolower($targetName).'|'.$family;
        if(array_key_exists($key,$cache))return $cache[$key];
        $cache[$key]=null;
        try{
            $nodePackage=$targetPath===$archivePath
                ?$pkg:new CatalogUE1PackageReader($targetPath);
            foreach($nodePackage->getExports() as $export){
                if((int)$export['classIndex']!==0
                    ||strcasecmp((string)$export['objectNameText'],$targetName)!==0
                    ||(int)$export['serialSize']<=0)continue;
                $metadata=CatalogUE1ClassicClassMetadataReader::inspect(
                    $targetPath,$nodePackage,(int)$export['index'],false,$family);
                $size=$metadata['script_size']??null;
                if(is_int($size)&&$size>=0)return $cache[$key]=$size;
            }
        }catch(\Throwable $error){
            // Absent or unparseable dependency: never invent Script.Num=0.
        }
        return null;
    }

    /**
     * Decode the *whole* source-selected state-frame archive.
     *
     * $position is an absolute archive offset in UClass; in ordinary
     * UObject payloads it is relative to the export's SerialOffset.
     * $archiveBase is the corresponding physical file offset.
     *
     * @return array<string,mixed>|null
     */
    public static function read(
        CatalogUE1PackageReader $pkg,int $version,int $objectFlags,
        callable $read,callable $compact,callable $i32,
        int &$position,int $archiveBase=0,?string $sourceFamily=null,
        ?string $archivePath=null,?int $exportIndex=null
    ):?array{
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        $ut99=$family===CatalogUE1ClassicSourceFamily::UT99_V432;
        $hasStack=($objectFlags & self::RF_HAS_STACK)!==0;
        // UT99 always checks RF_HasStack. Unreal v1.200 also loads
        // temporary MainFrames for file versions below 47.
        if(!$hasStack&&($ut99||$version>=47))return null;

        $start=$position;
        $validate=static function(int $ref,string $member)use($pkg):int{
            if(($ref<0&&-$ref>count($pkg->getImports()))
                ||($ref>0&&$ref>count($pkg->getExports()))){
                throw new RuntimeException('Invalid UE1 execution-frame '.$member.' reference');
            }
            return $ref;
        };
        $node=0;$stateNode=0;$oldClass=null;$oldNode=null;
        if($ut99||$version>=51){
            $node=$validate($compact(),'Node');
            $stateNode=$validate($compact(),'StateNode');
        }else{
            $oldClass=$validate($compact(),'OldClass');
            if($oldClass!==0)$oldNode=$i32();
        }
        $temp=!$ut99&&$version<52?$validate($compact(),'TempClass'):null;
        $probeMask=bin2hex($read(8));
        $latent=$ut99||$version>=55?$i32():0;
        $codeOffset=$node!==0?$compact():null;
        $nodeScriptNum=null;
        if($codeOffset!==null&&$codeOffset!==-1&&$archivePath!==null){
            $nodeScriptNum=self::referencedNodeScriptNum(
                $pkg,$node,$archivePath,$family);
            if($codeOffset< -1||($nodeScriptNum!==null&&$codeOffset>=$nodeScriptNum)){
                if($nodeScriptNum===null){
                    throw new RuntimeException('Invalid UE1 execution-frame script code offset');
                }
                $object='UE1 object';
                if($exportIndex!==null){
                    $export=$pkg->getExports()[$exportIndex]??null;
                    if(is_array($export)){
                        $classRef=(int)$export['classIndex'];
                        $className=$classRef<0
                            ?(string)($pkg->getImports()[-$classRef-1]['objectNameText']??'Object')
                            :'Object';
                        $object=$className.' '
                            .pathinfo($archivePath,PATHINFO_FILENAME)
                            .'.'.(string)$export['objectNameText'];
                    }
                }
                throw new RuntimeException(
                    $object.': Offset mismatch: '.$codeOffset.' '.$nodeScriptNum);
            }
        }

        return [
            'serialized_start'=>$archiveBase+$start,
            'serialized_size'=>$position-$start,
            'node_reference'=>$node,
            'state_node_reference'=>$stateNode,
            'legacy_class_reference'=>$oldClass,
            'legacy_node_number'=>$oldNode,
            'legacy_temp_reference'=>$temp,
            'probe_mask_le_hex'=>$probeMask,
            'latent_action'=>$latent,
            'script_code_offset'=>$codeOffset,
            'script_code_offset_node_script_num'=>$nodeScriptNum,
            'script_code_offset_native_bound_verified'=>
                $codeOffset===null||$codeOffset===-1||$nodeScriptNum!==null,
            'has_stack_object_flag'=>$hasStack,
            'selected_source_family'=>$family,
            'temporary_legacy_frame_discarded_on_load'=>!$ut99&&!$hasStack&&$version<47,
            'runtime_frame_constructed'=>false,
            'runtime_node_preloaded'=>false,
            'runtime_script_code_pointer_resolved'=>false,
        ];
    }
}
