<?php
/**
 * Game-source overrides layered over the Epic UE1 object/tagged archive.
 *
 * Unreal v1.227 Emitter/Src implementations supply no additional native
 * archive bytes for the listed actors; their save-time mutations affect
 * the inherited tagged property stream. Never substitute game source for
 * the engine's base UObject::Serialize operation.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicGameSourceOverlay
{
    /**
     * @param array<string,mixed> $header
     * @param array<string,mixed> $propertyStream
     * @return array<string,mixed>|null
     */
    public static function inspectNative(
        array $header,string $className,int $nativeLength,array $propertyStream,
        string $selectedSource,?string $classSourcePackage
    ):?array{
        // Game source applies to the selected LOADER, which can load older
        // engine .u packages. The source-identifying header 69/227 is
        // authoritative when present, but legacy packages require caller-
        // provided loader provenance.
        if($selectedSource!==CatalogUE1ClassicSourceFamily::UNREAL_V227
            ||strcasecmp((string)$classSourcePackage,'Emitter')!==0){
            return null;
        }
        // Package ClassIndex names are UnrealScript identities without
        // the native C++ A prefix (Emitter/Classes/*.uc).
        $kind=strtolower($className);
        if(!in_array($kind,[
            'xbeamemitter','xparticleemitter','xropedeco'
        ],true)){
            return null;
        }
        $version=(int)($header['version']??0);
        $licensee=(int)($header['licenseeVersion']??($header['licensee']??0));
        $base=[
            'source_game_overlay'=>'unreal-v227',
            'source_game_package_version'=>$version,
            'source_game_licensee_version'=>$licensee,
            'source_loader_family'=>$selectedSource,
            'source_game_class_identity'=>$className,
            'source_class_defining_package'=>'Emitter',
            'source_game_cpp_prefix'=>'A',
            'source_game_identity_provenance'=>$version===69&&$licensee===227
                ?'game_source_version_licensee_pair'
                :'explicit_loader_source_selection',
            'base_serialization'=>'Epic UE1 UObject::Serialize tagged properties',
            'game_native_archive_bytes'=>0,
            'extension_bytes'=>$nativeLength,
            'source_game_override_verified_from_cpp'=>true,
            'read_only_no_runtime_state_mutation'=>true,
            'tagged_property_count'=>count($propertyStream['tags']??[]),
        ];
        // AXEmitter does not override Serialize; its inherited method is
        // AXParticleEmitter::Serialize and then AActor/UObject::Serialize.
        if($kind==='xparticleemitter'){
            return $base+[
                'status'=>$nativeLength===0
                    ?'native_game_actor_super_only_complete'
                    :'native_game_actor_unexpected_tail',
                'source_serialize_method'=>'Emitter/Src/AXEmitter.cpp:101',
                'source_save_condition'=>'Ar.IsSaving() && bIsInternalEmitter',
                'save_time_inherited_property_overrides'=>[
                    'Location'=>'FVector(0,0,0)',
                    'Rotation'=>'FRotator(0,0,0)',
                    'Level'=>null,
                    'XLevel'=>null,
                    'HitActor'=>null,
                ],
                'save_time_post_serialize_recovery'=>
                    'ParentEmitter restores Level/XLevel/HitActor when non-null',
                'reference_only_traversal'=>
                    'PartPtr::Serialize when neither loading nor saving',
                'save_time_mutations_replayed'=>false,
            ];
        }
        if($kind==='xbeamemitter'){
            return $base+[
                'status'=>$nativeLength===0
                    ?'native_game_actor_super_only_complete'
                    :'native_game_actor_unexpected_tail',
                'source_serialize_method'=>'Emitter/Src/BeamEmitter.cpp:42',
                'source_parent_serializer'=>
                    'AXEmitter -> AXParticleEmitter -> AActor -> UObject',
                'source_extra_archive_on_load_save'=>false,
                'reference_only_traversal'=>'RenderDataModel when Ar.SerializeRefs()',
                'save_time_inherited_property_overrides'=>
                    'AXParticleEmitter override when bIsInternalEmitter',
                'save_time_mutations_replayed'=>false,
            ];
        }
        if($kind==='xropedeco'){
            return $base+[
                'status'=>$nativeLength===0
                    ?'native_game_actor_super_only_complete'
                    :'native_game_actor_unexpected_tail',
                'source_serialize_method'=>'Emitter/Src/EmitterPhysX.cpp:1392',
                'source_parent_serializer'=>'ADecoration -> AActor -> UObject',
                'source_save_condition'=>'Ar.IsSaving()',
                'source_save_before_super'=>[
                    'RbPhysicsData articulation SaveState() when present',
                    'Mesh=nullptr',
                ],
                'source_save_after_super'=>'Mesh=RopeMeshPtr',
                'reference_only_traversal'=>
                    'RopeMeshPtr and articulation Serialize when Ar.SerializeRefs()',
                'save_time_mutations_replayed'=>false,
            ];
        }
        return null;
    }
}
