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
        int &$position,int $archiveBase=0
    ):?array{
        $hasStack=($objectFlags & self::RF_HAS_STACK)!==0;
        if(!$hasStack&&$version>=47)return null;

        $start=$position;
        $validate=static function(int $ref,string $member)use($pkg):int{
            if(($ref<0&&-$ref>count($pkg->getImports()))
                ||($ref>0&&$ref>count($pkg->getExports()))){
                throw new RuntimeException('Invalid UE1 execution-frame '.$member.' reference');
            }
            return $ref;
        };
        $node=0;$stateNode=0;$oldClass=null;$oldNode=null;
        if($version>=51){
            $node=$validate($compact(),'Node');
            $stateNode=$validate($compact(),'StateNode');
        }else{
            $oldClass=$validate($compact(),'OldClass');
            if($oldClass!==0)$oldNode=$i32();
        }
        $temp=$version<52?$validate($compact(),'TempClass'):null;
        $probeMask=bin2hex($read(8));
        $latent=$version>=55?$i32():0;
        $codeOffset=$node!==0?$compact():null;
        if($codeOffset!==null&&$codeOffset< -1){
            throw new RuntimeException('Invalid UE1 execution-frame script code offset');
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
            'has_stack_object_flag'=>$hasStack,
            'temporary_legacy_frame_discarded_on_load'=>!$hasStack&&$version<47,
            'runtime_frame_constructed'=>false,
            'runtime_node_preloaded'=>false,
            'runtime_script_code_pointer_resolved'=>false,
        ];
    }
}
