<?php
/**
 * Source-specific UFunction post-LOAD parameter-layout resolver:
 * UT99 v432/469f and Unreal v227k/explicit original 2012 v227i.
 * Do not infer either runtime build from package version/number.
 *
 * Does not claim runtime UObject pointer linking, script execution, or
 * arbitrary user-defined struct sizes. Unknown members fail closed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
final class CatalogUE1ClassicFunctionLinkReader
{
    /** @return array<string,mixed> */
    public static function recompute(
        string $path, CatalogUE1PackageReader $pkg, int $functionIndex, array $saved,
        string $sourceProfile=CatalogUE1ClassicSourceFamily::UT99_V432
    ):array {
        if(!in_array($sourceProfile,[
            CatalogUE1ClassicSourceFamily::UT99_V432,
            CatalogUE1ClassicSourceFamily::UT99_V469F,
            CatalogUE1ClassicSourceFamily::UNREAL_V227,
            CatalogUE1ClassicSourceFamily::UNREAL_V227I,
        ],true))return ['source_function_link_status'=>'source_profile_unverified'];
        $exports=$pkg->getExports();
        $imports=$pkg->getImports();
        $function=$exports[$functionIndex]??null;
        if(!is_array($function))return ['source_function_link_status'=>'missing_function'];
        // Source: UT99 v400 inherited by v432 UnClass.cpp UFunction::Serialize.
        // UFunction::MergeBools()=false and GetInheritanceSuper()=NULL.
        $memberSizes=[
            'ByteProperty'=>1, 'IntProperty'=>4, 'FloatProperty'=>4,
            'BoolProperty'=>4, 'ObjectProperty'=>4, 'ClassProperty'=>4,
            'NameProperty'=>4, 'StrProperty'=>12, 'ArrayProperty'=>12,
        ];
        $sourceKind=static function(array $e)use($exports,$imports):?string {
            $class=(int)($e['classIndex']??0);
            if($class===0)return 'Class';
            return (string)($class<0
                ?($imports[-$class-1]['objectNameText']??null)
                :($exports[$class-1]['objectNameText']??null));
        };
        $reason=null;
        $size=0;$computedParmsSize=0;$count=0;$return=65535;$next=(int)($saved['children_reference']??0);
        $visited=[];$pathBase=strtolower(basename($path));
        if($next<0)$reason='imported_function_child_not_linked';
        while($next>0){
            if(isset($visited[$next])||count($visited)>=512){
                $reason='cycle_or_excessive_field_count';break;
            }
            $visited[$next]=true;
            $entry=$exports[$next-1]??null;
            if(!is_array($entry)){$reason='missing_field';break;}
            // Epic's UFunction::Serialize iterates from Cast<UProperty>
            // until a non-property, non-Parm, or foreign owner.
            $kind=$sourceKind($entry);
            if(!is_string($kind)||!str_ends_with($kind,'Property'))break;
            if((int)($entry['outerIndex']??0)!==$functionIndex+1){
                $reason='foreign_field_owner';break;
            }
            try {
                $property=self::loadProperty($path,$pkg,$next-1,$sourceProfile);
            }catch(\Throwable $e){$reason='unreadable_property';break;}
            if(($property['status']??null)!=='native_property_record_complete'){
                $reason='unverified_property_archive';break;
            }
            $flags=$property['effective_property_flags']??null;
            if(!is_int($flags)){$reason='missing_loaded_flags';break;}
            if(($flags&0x80)===0)break; // CPF_Parm
            $dim=$property['array_dim']??null;
            if(!is_int($dim)||$dim<1||$dim>100000){
                $reason='unverified_array_dim';break;
            }
            // Original compiled 227k output params with CPF_Parm|OutParm
            // and no ReturnParm occupy one indirect four-byte pointer,
            // regardless of the underlying AnyProperty/struct value size.
            $indirect=$sourceProfile===CatalogUE1ClassicSourceFamily::UNREAL_V227
                &&($flags&0x580)===0x180;
            $element=$indirect?4:($memberSizes[$kind]??null);
            if($kind==='StructProperty'&&!$indirect){
                $ref=(int)($property['subclass_fields']['struct_reference']??0);
                $struct=$ref>0?($exports[$ref-1]??null)
                    :($ref<0?($imports[-$ref-1]??null):null);
                $name=$struct['objectNameText']??null;
                // Only trusted Core builtins have source-proven byte sizes.
                // Imported Core.Struct and local Core.u.Struct refer to the
                // original FVector, FRotator and FColor definitions.
                $coreBuiltin=$ref<0
                    &&strcasecmp((string)($struct['classPackageText']??''),'Core')===0
                    &&strcasecmp((string)($struct['classNameText']??''),'Struct')===0;
                if($ref>0&&$pathBase==='core.u'&&is_array($struct)){
                    $coreBuiltin=$sourceKind($struct)==='Struct';
                }
                // Extra Unreal 227k native Core math classes only belong
                // to the explicit Unreal-v227 compiler profile, never
                // to UT99 just because names and package version coincide.
                $sizes=['Vector'=>12,'Rotator'=>12,'Color'=>4];
                if(in_array($sourceProfile,[
                    CatalogUE1ClassicSourceFamily::UNREAL_V227,
                    CatalogUE1ClassicSourceFamily::UNREAL_V227I,
                ],true)){
                    $sizes+=['Quat'=>16,'InterpCurve'=>12,'Coords'=>48];
                }
                $element=$coreBuiltin?($sizes[$name]??null):null;
                if($element===null&&in_array($sourceProfile,[
                    CatalogUE1ClassicSourceFamily::UNREAL_V227,
                    CatalogUE1ClassicSourceFamily::UT99_V469F,
                ],true)){
                    $element=CatalogUE1ClassicFunctionStructShapeReader::elementSize(
                        $path,$pkg,$ref,is_string($name)?$name:null,$sourceProfile);
                }
            }
            if($element===null||$element<=0||$element>1048576){
                $reason='unverified_element_size:'.$kind;break;
            }
            // UByteProperty::Link aligns to 1; all other known sizes align
            // to 4. UFunction::MergeBools returns false, so bools do NOT
            // share the previous bitfield word.
            $align=$kind==='ByteProperty'?1:4;
            $offset=intdiv($size+$align-1,$align)*$align;
            if($dim>intdiv(1048576,$element)
                ||$offset+$element*$dim>1048576){
                $reason='unbounded_parameter_layout';break;
            }
            // Source 227k UFunction::Serialize: an indirect out parameter
            // advances exactly one 4-byte pointer regardless of ArrayDim.
            $size=$offset+($indirect?4:$element*$dim);
            $computedParmsSize=$size;
            ++$count;
            if(($flags&0x400)!==0)$return=$offset; // CPF_ReturnParm
            $next=(int)($property['next_field_reference']??0);
            if($next<0){$reason='imported_next_field_not_linked';break;}
        }
        if($reason!==null){
            return ['source_function_link_status'=>'source_layout_unverified',
                'source_function_link_reason'=>$reason,
                'runtime_function_param_chain_linked'=>false];
        }
        return [
            'loaded_parms_size'=>$computedParmsSize,
            'loaded_num_parms'=>$count,
            'loaded_return_value_offset'=>$return,
            // These are source-computed *values*, not linked engine pointers.
            'source_function_link_status'=>'source_property_layout_computed',
            'source_function_compiled_runtime_profile'=>$sourceProfile,
            'source_function_loaded_fields_computed'=>true,
            'runtime_function_param_chain_linked'=>false,
        ];
    }

    /** @return array<string,mixed> */
    private static function loadProperty(
        string $path,CatalogUE1PackageReader $pkg,int $index,string $sourceProfile
    ):array{
        return CatalogUE1ClassicNativeObjectReader::inspect(
            $path,$pkg,$index,
            CatalogUE1ClassicTaggedPropertyReader::inspect(
                $path,$pkg,$index,$sourceProfile),
            $sourceProfile
        );
    }
}
