<?php
/**
 * Source-scoped UClass default-tag validation against serialized UProperty
 * declarations in the same genuine package, not a global name guess.
 *
 * UE1 UnClass.cpp: UStruct.Children / UField.Next form an ordered field
 * chain. UProperty::Serialize stores ArrayDim and its subclass determines
 * the FPropertyTag type. SuperField can point to a local UClass or an
 * external import; never infer imported definitions without that package.
 *
 * This proves matching names/type and ArrayDim for locally resolvable
 * fields. It does NOT prove imported superclasses, native CDO values,
 * opaque struct payload semantics or runtime property offsets.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicClassDefaultSchemaReader
{
    /** @var array<string,array<string,mixed>> */
    private static array $fieldCache=[];

    /**
     * @param array<string,mixed> $classRecord
     * @param list<array<string,mixed>> $tags
     * @return array<string,mixed>
     */
    public static function inspect(
        string $path,CatalogUE1PackageReader $pkg,array $classRecord,array $tags
    ): array {
        $classRef=(int)($classRecord['export_index']??-1)+1;
        $current=$classRecord;
        $visited=[];$declarations=[];$inherited=false;$importBlocked=false;
        $ownFields=0;$parentFields=0;
        while($classRef>0){
            if(isset($visited[$classRef])||count($visited)>=128){
                throw new RuntimeException('UE1 UClass inheritance cycle or depth overflow');
            }
            $visited[$classRef]=true;
            $fields=self::fieldChain(
                $path,$pkg,(int)($current['children_reference']??0));
            foreach($fields as $name=>$decl){
                // Child definitions shadow declarations from superclasses.
                if(!isset($declarations[$name])){
                    $declarations[$name]=$decl+['owner_ref'=>$classRef,
                        'inherited'=>$inherited];
                    if($inherited){$parentFields++;}else{$ownFields++;}
                }
            }
            $next=(int)($current['super_field_reference']??0);
            if($next<0){$importBlocked=true;break;}
            if($next===0){break;}
            $export=$pkg->getExports()[$next-1]??null;
            if(!is_array($export)||(int)$export['classIndex']!==0){
                throw new RuntimeException('UE1 UClass superclass is not a local class export');
            }
            // Prevent recursive default-schema checking while resolving
            // the local parent; only source-defined UClass header is needed.
            $current=CatalogUE1ClassicClassMetadataReader::inspect(
                $path,$pkg,$next-1,false);
            $classRef=$next;$inherited=true;
        }
        $matched=0;$matchedDirect=0;$matchedInherited=0;$unresolved=0;
        $mismatches=[];$samples=[];
        foreach($tags as $tag){
            $key=strtolower((string)($tag['name']??''));
            $decl=$declarations[$key]??null;
            if($decl===null){
                $unresolved++;
                if(count($samples)<8){$samples[]=['name'=>$tag['name'],
                    'reason'=>$importBlocked?'imported_superclass_unavailable':
                        'property_not_in_available_class_declarations'];}
                continue;
            }
            $tagKind=strtolower((string)($tag['type']??''));
            $declKind=(string)$decl['kind'];
            // Epic UClassProperty derives from UObjectProperty; older
            // FPropertyTag encodes it as an object reference type.
            $typeValid=$tagKind===$declKind
                ||($tagKind==='objectproperty'&&$declKind==='classproperty');
            $arrayValid=(int)($tag['array_index']??-1)>=0
                &&(int)($tag['array_index']??-1)<(int)$decl['array_dim'];
            if(!$typeValid||!$arrayValid){
                $mismatches[]=['name'=>$tag['name'],
                    'actual_type'=>$tagKind,'declared_kind'=>$declKind,
                    'array_index'=>$tag['array_index'],
                    'declared_array_dim'=>$decl['array_dim'],
                    'field_export_ref'=>$decl['ref']];
                if(count($mismatches)>=16){break;}
                continue;
            }
            $matched++;
            if($decl['inherited']){$matchedInherited++;}else{$matchedDirect++;}
        }
        if($mismatches!==[]){
            // A locally known declaration/type mismatch is not safely
            // reinterpretable as an unverified inherited field.
            throw new RuntimeException('UE1 class default tag disagrees with UProperty source declaration: '.
                json_encode($mismatches[0],JSON_UNESCAPED_SLASHES));
        }
        return [
            'status'=>$unresolved===0?'all_available_declarations_match':
                'imported_or_unknown_properties_unverified',
            'tag_count'=>count($tags),
            'declaration_type_and_array_index_matches'=>$matched,
            'matches_in_this_class'=>$matchedDirect,
            'matches_in_local_superclasses'=>$matchedInherited,
            'unresolved_tag_count'=>$unresolved,
            'unresolved_examples'=>$samples,
            'source_declarations_in_this_class'=>$ownFields,
            'source_declarations_in_local_superclasses'=>$parentFields,
            'has_unavailable_imported_superclass'=>$importBlocked,
            'value_payload_semantics_verified'=>false,
            'runtime_property_offsets_verified'=>false,
        ];
    }

    /** @return array<string,array{ref:int,kind:string,array_dim:int}> */
    private static function fieldChain(
        string $path,CatalogUE1PackageReader $pkg,int $reference
    ):array {
        $seen=[];$fields=[];
        while($reference>0){
            if(isset($seen[$reference])||count($seen)>=16000){
                throw new RuntimeException('UE1 UField.Next cycle or count exceeded');
            }
            $seen[$reference]=true;
            $field=self::readField($path,$pkg,$reference);
            $data=$field['native'];
            if(($data['status']??'')==='native_property_record_complete'){
                $key=strtolower((string)$field['name']);
                $fields[$key]=['ref'=>$reference,
                    'kind'=>(string)$data['property_kind'],
                    'array_dim'=>(int)$data['array_dim']];
            }
            $reference=(int)($data['next_field_reference']??0);
        }
        return $fields;
    }

    /** @return array<string,mixed> */
    private static function readField(
        string $path,CatalogUE1PackageReader $pkg,int $ref
    ):array {
        $key=$path."\0".$ref;
        if(isset(self::$fieldCache[$key])){return self::$fieldCache[$key];}
        $ex=$pkg->getExports()[$ref-1]??null;
        if(!is_array($ex)){throw new RuntimeException('Invalid UE1 class field export ref');}
        $tag=CatalogUE1ClassicTaggedPropertyReader::inspect($path,$pkg,$ref-1);
        if(($tag['status']??'')!=='tag_envelope_decoded'){
            throw new RuntimeException('Invalid UE1 UField property prefix');
        }
        $native=CatalogUE1ClassicNativeObjectReader::inspect(
            $path,$pkg,$ref-1,$tag);
        return self::$fieldCache[$key]=[
            'name'=>$ex['objectNameText'],'native'=>$native];
    }
}
