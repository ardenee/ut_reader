<?php
/**
 * Source-native Unrealfile v22/v23 UScript::SerializeExpr archive traversal.
 * Epic May v0.86x Src/Engine/UnScript.cpp:4260+ and July v0.867
 * Src/Engine/UnScript.cpp:4894+, Inc/UnScript.h EExprToken.
 * May conversion opcode range 0x30–0x52 and WORD rotations differ from
 * July's 0x38–0x59 conversions, iterator tokens 0x2F–0x31 and INT rotations.
 *
 * Reads archive fields in source order; preserves source token and raw refs.
 * Does NOT execute script, compile expressions, resolve runtime classes
 * or reconstruct live FStackNode pointers.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileScriptExpressionReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false,?string $sourceLineage=null):array
    {
        CatalogUE1OriginalUnrealfilePropertySerializationGate::validate($sourceLineage);
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks=[];foreach(CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'] as $s)
            $stacks[(int)$s['object_index']]=$s;
        $nameCount=count($meta['names']);$objectCount=count($meta['object_bases']);
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open original serialized UScript');
        try{
            foreach($meta['object_bases'] as $o){
                if($o['kind']!=='export'||strcasecmp((string)$o['class_name'],'Script')!==0)
                    continue;
                $stack=$stacks[(int)$o['index']]??[];
                $result=['object_index'=>(int)$o['index'],'native_class'=>'UScript',
                    'status'=>'incomplete_native_script_archive_header',
                    'bytecode_executed'=>false,'runtime_class_links_resolved'=>false];
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($stack['class_member_bytes_remaining']??0)<12){
                    if($strict)throw new RuntimeException('UScript archive missing Num/Max/ClassRef');
                    $out[]=$result;continue;
                }
                $hdr=self::slice($fp,(int)$o['file_header_offset']+
                    (int)$stack['serialized_prefix_bytes'],12);
                [$num,$max,$classRef]=array_values(unpack('V3',$hdr));
                if($num>$max||$num>16000000||$max>16000000)
                    throw new RuntimeException('UScript native Num/Max size invalid');
                if($classRef>$objectCount)
                    throw new RuntimeException('UScript class reference exceeds ResMap');
                $length=(int)$o['file_data_size'];
                if($length<0||$length>16000000)
                    throw new RuntimeException('UScript serialized expression stream exceeds bound');
                // Epic UScript::SerializeData advances iCode until Num, and
                // every on-disk token/archive field occupies its native
                // serialized width. An unrelated byte range cannot be
                // silently promoted to a complete expression stream.
                if($num!==$length)
                    throw new RuntimeException('UScript serialized byte count differs from Num');
                $bytes=self::slice($fp,(int)$o['file_data_offset'],$length);
                $families=$sourceLineage===null
                    ?[CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY,
                      CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY]
                    :[$sourceLineage];
                $success=[];$errors=[];
                foreach($families as $family){
                    try{$success[$family]=self::parseStream($bytes,$objectCount,$nameCount,$family);}
                    catch(RuntimeException $e){$errors[$family]=$e;}
                }
                if($success===[]){
                    throw reset($errors);
                }
                if(count($success)===2){
                    $may=$success[CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY];
                    $july=$success[CatalogUE1OriginalUnrealfilePropertySerializationGate::JULY];
                    // Identical decoded trees are safe regardless of version.
                    // Distinct, *both valid* token interpretations are not.
                    if($may['nodes']!==$july['nodes']){
                        if($strict)throw new RuntimeException(
                            'Unrealfile v22/v23 UScript bytes parse as distinct May/July opcode semantics; source lineage required'
                        );
                        $result['status']='source_1997_script_lineage_required';
                        $result['serialized_byte_count']=$length;
                        $result['source_branch_candidates']=array_keys($success);
                        $out[]=$result;continue;
                    }
                }
                $effective=count($success)===1?array_key_first($success):null;
                $decoded=reset($success);
                $nodes=$decoded['nodes'];
                $ctx=$decoded['context'];
                $unconsumed=$length-$ctx['pos'];
                if($strict&&$unconsumed!==0)
                    throw new RuntimeException('Serialized script data includes unconsumed bytes');
                $out[]=[
                    'object_index'=>(int)$o['index'],'native_class'=>'UScript',
                    'status'=>'native_script_expressions_decoded',
                    'script_memory_num_bytes'=>$num,
                    'script_memory_max_bytes'=>$max,
                    'serialized_byte_count'=>$length,
                    'class_reference_raw'=>$classRef,
                    'expression_count'=>count($nodes),'parsed_token_count'=>$ctx['nodes'],
                    'serialized_bytes_consumed'=>$ctx['pos'],
                    'expression_tree'=>$nodes,
                    'source_lineage'=>$effective,
                    'source_lineage_inferred_from_exact_script_parse'=>$sourceLineage===null
                        &&$effective!==null,
                    'source_lineage_wire_equivalent'=>$effective===null,
                    'bytecode_executed'=>false,
                    'runtime_class_links_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_format'=>'early_unrealfile','script_expressions'=>$out,
            'bytecode_executed'=>false];
    }
    /** @return array{nodes:array<int,array<string,mixed>>,context:array<string,mixed>} */
    private static function parseStream(string $bytes,int $objectCount,int $nameCount,string $family):array
    {
        $c=['bytes'=>$bytes,'pos'=>0,'steps'=>0,'objectCount'=>$objectCount,
            'nameCount'=>$nameCount,'nodes'=>0,'sourceLineage'=>$family];
        $nodes=[];
        while($c['pos']<strlen($bytes)){
            $nodes[]=self::expression($c,0);
            if($c['nodes']>1000000)throw new RuntimeException('Script token traversal exceeds node limit');
        }
        return ['nodes'=>$nodes,'context'=>$c];
    }

    /** @param array<string,mixed> $c @return array<string,mixed> */
    private static function expression(array &$c,int $depth):array
    {
        if($depth>128)throw new RuntimeException('UScript recursive expression depth over 128');
        $start=$c['pos'];$token=self::byte($c);$c['nodes']++;
        $children=[];$fields=[];
        $next=static function()use(&$c,$depth):array{
            return self::expression($c,$depth+1);
        };
        $untilEnd=static function()use(&$c,$depth):array{
            $args=[];$guard=0;
            do{
                $sub=self::expression($c,$depth+1);
                $args[]=$sub;
                if(++$guard>100000)throw new RuntimeException('UScript function args exceed source-bound limit');
            }while($sub['token']!==0x16);
            return $args;
        };
        $may=$c['sourceLineage']===CatalogUE1OriginalUnrealfilePropertySerializationGate::MAY;
        $minConversion=$may?0x30:0x38;
        $maxConversion=$may?0x53:0x5a;
        if($token>=$minConversion&&$token<$maxConversion){
            // Epic's EX_*To* conversion IDs shifted by eight in July.
            // The same archive byte (e.g. 0x40) means DIFFERENT casts,
            // even when the nested byte stream has identical length.
            // Keep source-defined semantics, not only the token byte.
            $names=[
                'RotationToVector','ByteToInt','ByteToBool','ByteToFloat',
                'IntToByte','IntToBool','IntToFloat','BoolToByte',
                'BoolToInt','BoolToFloat','FloatToByte','FloatToInt',
                'FloatToBool','ObjectToInt','ObjectToBool','NameToBool',
                'StringToByte','StringToInt','StringToBool','StringToFloat',
                'StringToVector','StringToRotation','VectorToBool',
                'VectorToRotation','RotationToBool','ByteToString',
                'IntToString','BoolToString','FloatToString',
                'ObjectToString','NameToString','VectorToString',
                'RotationToString',
            ];
            $conversionIndex=$token-($may?0x31:0x39);
            $fields['conversion_token']=$token;
            $fields['conversion_name']=$names[$conversionIndex]??null;
            $fields['conversion_is_source_defined']=$fields['conversion_name']!==null;
            $children[]=$next();
        }elseif($token>=0x70){
            $fields['intrinsic_id']=$token;$children=$untilEnd();
        }elseif($token>=0x60){
            $fields['extended_intrinsic_low_byte']=self::byte($c);
            $children=$untilEnd();
        }else switch($token){
            case 0x00:case 0x01:case 0x02:case 0x03:case 0x29:
                $fields['property_index_raw_word']=self::word($c);break;
            case 0x2d:
                $fields['bool_variable_selector']=self::byte($c);
                // Epic UnScript.cpp:4931-4932 validates selector >> 5.
                // 0=local, 1=object, 2=static; 3+ is not a valid source bin.
                $bin=$fields['bool_variable_selector']>>5;
                if(!in_array($bin,[0,1,2],true))
                    throw new RuntimeException('Invalid source EX_BoolVariable property bin');
                $fields['property_index_raw_word']=self::word($c);break;
            case 0x0b:case 0x16:case 0x25:case 0x26:case 0x27:
            case 0x28:case 0x2a:case 0x17:case 0x30:case 0x15:
            case 0x08:case 0x04:case 0x31:break;
            case 0x19:
                $children[]=$next();$fields['skip_offset']=self::word($c);
                $fields['skip_size']=self::byte($c);$children[]=$next();break;
            case 0x1a:
                $children[]=$next();$children[]=$next();
                $fields['array_flags']=[self::byte($c),self::byte($c)];break;
            case 0x1b:
                $fields['function_name_raw']=self::nameRef($c);
                $children=$untilEnd();break;
            case 0x1c:
                $fields['class_reference_raw']=self::objectRef($c);
                // May FStackNodeLink / July FStackNodePtr share the same
                // archive contract (Class then optional iNode).
                $fields['stack_node_index']=$fields['class_reference_raw']===0
                    ?null:self::int($c);
                $children=$untilEnd();break;
            case 0x1d:$fields['int_constant']=self::int($c);break;
            case 0x1e:$fields['float_constant']=self::float($c);break;
            case 0x1f:
                $bytes='';
                for($i=0;$i<65536;$i++){
                    $b=self::byte($c);
                    if($b===0)break;
                    $bytes.=chr($b);
                }
                if($i===65536)throw new RuntimeException('UScript string literal has no NUL within max bound');
                $fields['ansi_hex']=strtoupper(bin2hex($bytes));break;
            case 0x20:$fields['object_reference_raw']=self::objectRef($c);break;
            case 0x21:$fields['name_reference_raw']=self::nameRef($c);break;
            case 0x22:
                // May UScript::SerializeExpr archives three WORDs (6 bytes);
                // July archives three INTs (12 bytes), same file version.
                $fields['rotation_signed']=$may
                    ?[self::word($c),self::word($c),self::word($c)]
                    :[self::int($c),self::int($c),self::int($c)];
                break;
            case 0x23:$fields['vector_float']=[
                self::float($c),self::float($c),self::float($c)];break;
            case 0x24:case 0x2c:
                $fields['byte_constant']=self::byte($c);break;
            case 0x2b:
                $fields['resize_byte']=self::byte($c);
                $children[]=$next();break;
            case 0x2e:
                $fields['class_reference_raw']=self::objectRef($c);
                $children[]=$next();break;
            case 0x07:case 0x2f:
                if($token===0x2f&&$may)
                    throw new RuntimeException('May 1997 does not define EX_Iterator 0x2F');
                if($token===0x07){
                    $fields['jump_offset']=self::word($c);$children[]=$next();
                }else{
                    $children[]=$next();$fields['jump_offset']=self::word($c);
                }break;
            case 0x05:
                $fields['switch_size']=self::byte($c);
                $children[]=$next();break;
            case 0x06:$fields['jump_offset']=self::word($c);break;
            case 0x09:
                $fields['assert_line']=self::word($c);$children[]=$next();break;
            case 0x0a:
                $fields['case_offset']=self::word($c);
                if($fields['case_offset']!==0xffff)$children[]=$next();break;
            case 0x0c:
                if(($start+1)%4!==0)
                    throw new RuntimeException('Original UScript label table is not aligned to a 4-byte code offset');
                $labels=[];
                for($i=0;$i<100000;$i++){
                    $label=self::nameRef($c);$code=self::word($c);
                    $labels[]=['name_ref'=>$label,'code_offset'=>$code];
                    if($label===0)break;
                }
                if($i>=100000)throw new RuntimeException('Unterminated original script label table');
                $fields['labels']=$labels;break;
            case 0x0d:$children[]=$next();break;
            case 0x0e:
                $children[]=$next();$children[]=$next();
                $fields['skip_offset']=self::word($c);
                $children[]=$next();break;
            case 0x0f:
                $fields['assign_size']=self::byte($c);
                $children[]=$next();$children[]=$next();break;
            case 0x10:case 0x11:case 0x12:case 0x13:
                $children[]=$next();$children[]=$next();break;
            case 0x18:
                $fields['skip_size']=self::word($c);$children[]=$next();break;
            case 0x14:
                $params=[];for($i=0;$i<100000;$i++){
                    $size=self::byte($c);
                    if($size===0)break;
                    $params[]=['size'=>$size,'out_param_flag'=>self::byte($c)];
                }
                if($i>=100000)throw new RuntimeException('Original BeginFunction parameter list unterminated');
                $fields['parameters']=$params;break;
            default:
                throw new RuntimeException(sprintf('Original script serializer invalid/unsupported expression token 0x%02X',$token));
        }
        return ['token'=>$token,'offset'=>$start,'serialized_length'=>$c['pos']-$start,
            'fields'=>$fields,'children'=>$children];
    }
    /** @param array<string,mixed> $c */
    private static function exact(array &$c,int $len):string
    {
        if($len<0||$len>strlen($c['bytes'])-$c['pos'])
            throw new RuntimeException('Truncated original UScript expression stream');
        $raw=substr($c['bytes'],$c['pos'],$len);$c['pos']+=$len;return $raw;
    }
    private static function byte(array &$c):int{return ord(self::exact($c,1));}
    private static function word(array &$c):int{return (int)unpack('v',self::exact($c,2))[1];}
    private static function int(array &$c):int
    {
        $v=(int)unpack('V',self::exact($c,4))[1];
        return $v>=0x80000000?$v-0x100000000:$v;
    }
    private static function float(array &$c):float{return (float)unpack('g',self::exact($c,4))[1];}
    private static function objectRef(array &$c):int
    {
        $ref=(int)unpack('V',self::exact($c,4))[1];
        if($ref>$c['objectCount'])
            throw new RuntimeException('UScript binary object reference exceeds native ResMap');
        return $ref;
    }
    private static function nameRef(array &$c):int
    {
        $ref=self::word($c);
        if($ref>$c['nameCount'])
            throw new RuntimeException('UScript binary name reference exceeds native NameMap');
        return $ref;
    }
    private static function slice($fp,int $offset,int $len):string
    {
        if($len<0||$len>16000000||fseek($fp,$offset,SEEK_SET)!==0)
            throw new RuntimeException('Cannot seek UScript data archive');
        $buf='';
        while(strlen($buf)<$len){
            $x=fread($fp,min(65536,$len-strlen($buf)));
            if(!is_string($x)||$x==='')
                throw new RuntimeException('Truncated source UScript archive data');
            $buf.=$x;
        }
        return $buf;
    }
}
