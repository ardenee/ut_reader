<?php
/**
 * Original Unreal v0.84a 32-bit AActorBase native member value reader.
 *
 * Offsets from Epic's UnRoot.h AActorBase declaration compiled with x86
 * MSVC /Zp4 (/d1reportSingleClassLayoutAActorBase); verified against
 * sizeof(AActorBase)=304 and sizeof(AActor)=1148.
 *
 * Serialized process pointers, private property bins and FNames are raw
 * words. The AActor PropertyPad and private AIInfo have no general
 * class-defined meaning without their linked UClass; they stay opaque.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalNativeActorValueReader
{
    private const BYTES=1148;
    private const BOOL_FLAGS1=[
        'bStatic','bHidden','bHiddenEd','bDirectional','bTemplateClass',
        'bSelected','bNoDelete','bTempLightChanged','bTempDynamicLight',
        'bUser0','bUser1','bUser2','bUser3','bUser4','bUser5','bUser6',
        'bUser7','bSpecialLit','bOnHorizon','bActorShadows','bAnimate',
        'bMeshWet','bMeshShadowCast','bMeshEnviroMap','bUnlit','bNoSmooth',
        'bCollideActors','bCollideWorld','bBlocksActors','bBlocksPlayers',
        'bBehindView','bPad0'
    ];
    private const BOOL_FLAGS2=['bPad1','bPad2','bPad3','bPad4','bPad5','bPad6','bPad7'];
    private const BOOL_FLAGS3=[
        'bGravity','bMomentum','bDifficulty1','bDifficulty2','bDifficulty3',
        'bDifficulty4','bNetCooperative','bNetDeathMatch','bNetPersistent',
        'bNetNoMonsters','bProjTarget','bCanBeTeleported','bIsSecretGoal',
        'bIsKillGoal','bIsItemGoal'
    ];
    private const BOOL_FLAGS4=[
        'bWasTouchedByPlayer','bSleeping','bTempEditor','bJustDeleted'
    ];
    private const POINTER_FIELDS=[
        'Class'=>0,'Texture'=>72,'MeshMap'=>76,'Brush'=>80,
        'AmbientSound'=>92
    ];
    private const INDEX_FIELDS=[
        'iParent'=>60,'iWeapon'=>64,'iMe'=>152,'iTarget'=>156,
        'iInventory'=>160,'iFloor'=>164,'iInstigator'=>168,
        'iPendingTeleporter'=>296
    ];
    private const INT_FIELDS=[
        'AnimMessage'=>112,'TimerCountdown'=>208,'LifeSpan'=>244,
        'Age'=>248,'Era'=>252,'TextureList'=>288
    ];
    private const FLOAT_FIELDS=[
        'DrawScale'=>84,'AnimRate'=>100,'AnimBase'=>104,
        'CollisionRadius'=>116,'CollisionHeight'=>120,'Mass'=>172,
        'WaterSinkRate'=>176
    ];
    private const BYTE_FIELDS=[
        'DrawType'=>68,'BlitType'=>69,'InherentGlow'=>88,
        'SoundRadius'=>96,'SoundVolume'=>97,'AnimSeq'=>98,
        'AnimCount'=>108,'AnimFirst'=>109,'AnimLast'=>110,
        'LightType'=>124,'LightEffect'=>125,'LightBrightness'=>126,
        'LightHue'=>127,'LightSaturation'=>128,'LightRadius'=>129,
        'LightPeriod'=>130,'LightPhase'=>131,'LightCone'=>132,
        'VolumeBrightness'=>133,'VolumeRadius'=>134,'Zone'=>148,
        'ScriptCountdown'=>204,'ScriptTickRate'=>205,
        'ChanceOfExistence'=>240,'RandomizerDivider'=>241,
        'RandomizerRemainder'=>242,'WhichTriggers'=>286,
        'TextureCount'=>292,'AITask'=>293,'TeleportDelay'=>300
    ];
    private const NAME_FIELDS=[
        'Name'=>144,'Event'=>146,'TimerMessage'=>212,
        'DefaultEdCategory'=>214
    ];

    /** @return array<string,mixed> */
    public static function read(string $raw):array
    {
        if(strlen($raw)!==self::BYTES) {
            throw new RuntimeException('Original AActor native record must be exactly 1148 bytes');
        }
        $word=static fn(int $o):int=>(int)unpack('V',substr($raw,$o,4))[1];
        $signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
        $float=static fn(int $o):float=>(float)unpack('g',substr($raw,$o,4))[1];
        $byte=static fn(int $o):int=>ord($raw[$o]);
        $name=static fn(int $o):int=>(int)unpack('v',substr($raw,$o,2))[1];
        $vector=static fn(int $o):array=>[
            'xyz'=>array_values(unpack('g3',substr($raw,$o,12))),
            'w_or_flags_raw_word'=>$word($o+12)
        ];
        $rotation=static fn(int $o):array=>[
            $signed($word($o)),$signed($word($o+4)),$signed($word($o+8))
        ];
        $flags=static function(int $offset,array $names)use($word):array{
            $bits=$word($offset);$out=[];
            foreach($names as $i=>$flag)$out[$flag]=(($bits >> $i)&1)!==0;
            return $out;
        };
        $pick=static function(array $positions,callable $reader):array{
            $out=[];foreach($positions as $key=>$offset)$out[$key]=$reader($offset);
            return $out;
        };
        $bytes=static fn(int $offset,int $size):array=>
            array_values(unpack('C'.$size,substr($raw,$offset,$size)));
        $indexArray=static function(int $offset,int $n)use($word,$signed):array{
            $out=[];for($i=0;$i<$n;$i++)$out[]=$signed($word($offset+4*$i));
            return $out;
        };
        $pointerArray=static function(int $offset,int $n)use($word):array{
            $out=[];for($i=0;$i<$n;$i++)$out[]=$word($offset+4*$i);
            return $out;
        };
        $padding=[
            70=>2,89=>3,99=>1,111=>1,135=>1,149=>3,
            206=>2,243=>1,287=>1,294=>2,301=>3,947=>1
        ];
        $padBytes=[];
        foreach($padding as $offset=>$count)$padBytes[(string)$offset]=strtoupper(
            bin2hex(substr($raw,$offset,$count))
        );
        $base=[
            'resource_pointers_raw_words'=>$pick(self::POINTER_FIELDS,$word),
            'index_members'=>$pick(self::INDEX_FIELDS,static fn(int $o):int=>$signed($word($o))),
            'signed_int_members'=>$pick(self::INT_FIELDS,static fn(int $o):int=>$signed($word($o))),
            'float_members'=>$pick(self::FLOAT_FIELDS,$float),
            'byte_members'=>$pick(self::BYTE_FIELDS,$byte),
            'fname_index_raw_words'=>$pick(self::NAME_FIELDS,$name),
            'location'=>$vector(4),'velocity'=>$vector(20),
            'draw_rotation'=>$rotation(36),'view_rotation'=>$rotation(48),
            'bool_flags_136'=>$flags(136,self::BOOL_FLAGS1),
            'bool_flags_140'=>$flags(140,self::BOOL_FLAGS2),
            'bool_flags_180'=>$flags(180,self::BOOL_FLAGS3),
            'bool_flags_200'=>$flags(200,self::BOOL_FLAGS4),
            'bool_raw_words'=>[
                'offset_136'=>$word(136),'offset_140'=>$word(140),
                'offset_180'=>$word(180),'offset_200'=>$word(200)
            ],
            'touching_actor_indices'=>$indexArray(184,4),
            'blocked_class_refs_raw_words'=>$pointerArray(216,3),
            'passed_class_refs_raw_words'=>$pointerArray(228,3),
            'trigger_sequences'=>$bytes(256,10),
            'trigger_frames'=>$bytes(266,10),
            'trigger_values'=>$bytes(276,10),
            'source_alignment_bytes_hex'=>$padBytes,
            'native_x86_base_size_bytes'=>304,
            'source_abi_layout'=>'AActorBase x86 MSVC v0.84a'
        ];
        $privatePointers=$pointerArray(948,3);
        return [
            'native_base_members'=>$base,
            'native_base_member_values_decoded'=>true,
            'unresolved_native_property_pad'=>[
                'serialized_offset'=>304,'byte_length'=>643,
                'sha256'=>hash('sha256',substr($raw,304,643)),
                'meaning'=>'class_specific_property_values_require_UClass'
            ],
            'unresolved_native_private_state'=>[
                'serialized_offset'=>948,'byte_length'=>200,
                'property_bins_raw_pointer_words'=>$privatePointers,
                'ai_info_sha256'=>hash('sha256',substr($raw,960,188)),
                'runtime_private_state_loaded'=>false
            ],
            'runtime_resource_refs_resolved'=>false,
            'runtime_name_refs_resolved'=>false,
            'all_native_member_values_decoded'=>false
        ];
    }
}
