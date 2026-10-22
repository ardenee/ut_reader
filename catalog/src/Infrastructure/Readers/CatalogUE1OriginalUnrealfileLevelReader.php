<?php
/**
 * Unreal v0.867 (original v22/v23) ULevel source archive serializer.
 *
 * Epic May v0.86x Src/Inc/UnLevel.h::SerializeHeader writes UDatabase
 * Num/Max, Model, BrushArray, Misc, ReachSpecs and 16 TextBlocks; July
 * v0.867 writes the same fields EXCEPT Misc. Both source branches use
 * version 23: source lineage must be explicit to avoid shifted refs.
 * July then serializes Model, BrushArray, ReachSpecs and 16 TextBlocks as
 * one-based UObject references. SerializeData serializes Num UObject*
 * elements, NOT capacity Max. FExecStackMain prefix is handled by the
 * sibling CatalogUE1OriginalUnrealfileStackReader. No level is loaded.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1OriginalUnrealfileLevelReader
{
    private const JULY_MEMBER_BYTES=84; // Num/Max + (3+16)*4
    private const MAY_MEMBER_BYTES=88;  // Num/Max + (4+16)*4

    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false,?string $sourceLineage=null):array
    {
        CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
        $memberBytes=$sourceLineage===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY
            ?self::MAY_MEMBER_BYTES:self::JULY_MEMBER_BYTES;
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $objectCount=(int)$meta['summary']['object_count'];
        $prefix=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $prefixBy=[];
        foreach($prefix as $row)$prefixBy[(int)$row['object_index']]=$row;
        $result=[];$fp=@fopen($path,'rb');
        if(!is_resource($fp))throw new RuntimeException('Cannot open original ULevel archive');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Level')!==0)continue;
                $stack=$prefixBy[(int)$o['index']]??[];
                $entry=['object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'ULevel','status'=>'incomplete_original_level_header',
                    'runtime_level_instantiated'=>false,'runtime_references_resolved'=>false];
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($stack['class_member_bytes_remaining']??0)<$memberBytes){
                    if($strict)throw new RuntimeException('Original ULevel missing native serialized member fields');
                    $result[]=$entry;continue;
                }
                if($sourceLineage===null){
                    if($strict)throw new RuntimeException('Original May/July 1997 ULevel header has different number of persisted references; source lineage required');
                    $entry['status']='source_1997_level_lineage_required';
                    $entry['source_lineage']=null;
                    $entry['ambiguity']='May serializes an extra Misc object reference; July does not (both version 23)';
                    $result[]=$entry;continue;
                }
                $head=(int)$o['file_header_offset']+(int)$stack['serialized_prefix_bytes'];
                if(fseek($fp,$head,SEEK_SET)!==0)throw new RuntimeException('Cannot seek native ULevel members');
                $binary=fread($fp,$memberBytes);
                if(!is_string($binary)||strlen($binary)!==$memberBytes)
                    throw new RuntimeException('Truncated native ULevel member bytes');
                $parts=array_values(unpack('V'.intdiv($memberBytes,4),$binary));
                $num=(int)$parts[0];$max=(int)$parts[1];
                if($num>$max||$max>10000000)
                    throw new RuntimeException('Invalid original ULevel Num/Max');
                $dataSize=(int)$o['file_data_size'];
                if($num>intdiv(PHP_INT_MAX,4)||$dataSize!==$num*4)
                    throw new RuntimeException('Original ULevel SerializeData Num does not match object-ref bytes');
                $refs=array_slice($parts,2); // May: Model, BrushArray, Misc, ReachSpecs, TextBlocks[16]; July lacks Misc.
                $may=$sourceLineage===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY;
                if(count($refs)!==($may?20:19))throw new RuntimeException('Invalid original ULevel source reference count');
                foreach($refs as $reference){
                    if((int)$reference>$objectCount)
                        throw new RuntimeException('Original ULevel header UObject* exceeds ResMap');
                }
                if(fseek($fp,(int)$o['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek original ULevel data elements');
                $elements=[];
                for($i=0;$i<$num;$i++){
                    $part=fread($fp,4);
                    if(!is_string($part)||strlen($part)!==4)
                        throw new RuntimeException('Truncated original ULevel actor reference');
                    $ref=(int)unpack('V',$part)[1];
                    if($ref>$objectCount)
                        throw new RuntimeException('Original ULevel element UObject* exceeds ResMap');
                    $elements[]=['element_index'=>$i,'raw_one_based_ref'=>$ref,
                        'object_index'=>$ref===0?null:$ref-1];
                }
                $remaining=(int)$stack['class_member_bytes_remaining']-$memberBytes;
                if($strict&&$remaining!==0)
                    throw new RuntimeException('Original ULevel header has unexpected additional serialized members');
                $result[]=[
                    'object_index'=>(int)$o['index'],'object_name'=>$o['name'],
                    'native_class'=>'ULevel',
                    'status'=>$remaining===0?'native_level_header_and_data_decoded':'native_level_extra_header',
                    'active_actor_count'=>$num,'actor_capacity'=>$max,
                    'model_raw_one_based_ref'=>(int)$refs[0],
                    'brush_array_raw_one_based_ref'=>(int)$refs[1],
                    'misc_raw_one_based_ref'=>$may?(int)$refs[2]:null,
                    'reach_specs_raw_one_based_ref'=>(int)$refs[$may?3:2],
                    'text_block_raw_one_based_refs'=>array_map('intval',array_slice($refs,$may?4:3)),
                    'actor_records'=>$elements,'native_member_bytes'=>$memberBytes,
                    'source_lineage'=>$sourceLineage,
                    'unconsumed_header_bytes'=>$remaining,
                    'runtime_level_instantiated'=>false,'runtime_references_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unrealfile','level_records'=>$result,
            'runtime_level_instantiated'=>false,'runtime_actor_initialization_verified'=>false];
    }
}
