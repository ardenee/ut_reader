<?php
/**
 * Original Unreal v9/v10 UScript native resource and stack-tree structures.
 * v0.84a Src/Inc/UnScript.h 76-byte UResource + 36-byte Script header:
 * Class DWORD, Length INT, NumStackTree INT, CodeOffset INT, Pad[5].
 * Src/Inc/UnScrEng.h FStackNode contains three FStackNodeLink (8 bytes
 * each), five INTs, ENestType (4), FName WORD, four flag bytes, padding to
 * 56-byte Win32 natural alignment.
 * QuerySize/MinSize = Length; script tokens not evaluated as VM code.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalResourceScriptReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $headers=CatalogUE1OriginalResourceHeaderReader::read($path)['resource_headers'];
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Unable to open original UE1 script resource');
        try{
            foreach($headers as $h){
                if((int)$h['resource_type']!==9)continue;
                $row=['export_index'=>(int)$h['export_index'],'source_class'=>'UScript',
                    'status'=>'incomplete_source_script_header',
                    'script_opcodes_decoded'=>false,'runtime_class_reference_resolved'=>false];
                if((int)$h['serialized_length']<112){
                    if($strict)throw new RuntimeException('Original UScript header shorter than source 112-byte layout');
                    $out[]=$row;continue;
                }
                if(fseek($fp,(int)$h['serialized_offset']+76,SEEK_SET)!==0)
                    throw new RuntimeException('UScript header seek failed');
                $bytes=fread($fp,36);
                if(!is_string($bytes)||strlen($bytes)!==36)
                    throw new RuntimeException('UScript native header truncated');
                $words=unpack('V9',$bytes);
                $class=(int)$words[1];$length=(int)$words[2];
                $nodeCount=(int)$words[3];$codeOffset=(int)$words[4];
                if($length!==(int)$h['file_data_size']||$nodeCount>intdiv($length,56)
                    ||$codeOffset>$length||$nodeCount*56>$codeOffset)
                    throw new RuntimeException('UScript Length/NumStackTree/CodeOffset source bounds mismatch');
                if(fseek($fp,(int)$h['file_data_offset'],SEEK_SET)!==0)
                    throw new RuntimeException('Cannot seek serialized script stack nodes');
                $nodes=[];$signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
                for($j=0;$j<$nodeCount;$j++){
                    $block=fread($fp,56);
                    if(!is_string($block)||strlen($block)!==56)
                        throw new RuntimeException('Truncated FStackNode source record');
                    $link=static function(int $offset)use($block,$signed):array{
                        $v=unpack('Vresource/Vnode',substr($block,$offset,8));
                        return ['resource_raw_word'=>(int)$v['resource'],
                            'node_index'=>$signed((int)$v['node'])];
                    };
                    $fields=unpack('VpropOff/VnumProp/VdataOff/VdataLen/Vcode/Vnest',
                        substr($block,24,24));
                    $name=(int)unpack('v',substr($block,48,2))[1];
                    $flags=array_values(unpack('C4',substr($block,50,4)));
                    $nodes[]=[
                        'index'=>$j,
                        'child_functions'=>$link(0),'child_states'=>$link(8),
                        'next'=>$link(16),
                        'properties_offset'=>(int)$fields['propOff'],
                        'property_count'=>(int)$fields['numProp'],
                        'data_offset'=>(int)$fields['dataOff'],
                        'data_length'=>(int)$fields['dataLen'],
                        'code_start'=>(int)$fields['code'],
                        'nest_type'=>(int)$fields['nest'],
                        'name_index_raw_word'=>$name,
                        'stack_flags'=>$flags[0],'operator_params'=>$flags[1],
                        'operator_type'=>$flags[2],'operator_precedence'=>$flags[3],
                        'padding_bytes_le_hex'=>bin2hex(substr($block,54,2)),
                        'runtime_links_resolved'=>false,
                    ];
                }
                $out[]=[
                    'export_index'=>(int)$h['export_index'],'source_class'=>'UScript',
                    'status'=>'native_script_header_and_stack_decoded',
                    'class_reference_raw_word'=>$class,
                    'script_length'=>$length,'stack_node_count'=>$nodeCount,
                    'stack_node_stride'=>56,'code_offset'=>$codeOffset,
                    'opaque_noncode_bytes'=>$codeOffset-$nodeCount*56,
                    'code_region_bytes'=>$length-$codeOffset,
                    'stack_nodes'=>$nodes,
                    'reserved_words'=>array_slice(array_values($words),4,5),
                    'additional_header_bytes'=>(int)$h['serialized_length']-112,
                    'script_opcodes_decoded'=>false,'runtime_class_reference_resolved'=>false,
                ];
            }
        }finally{fclose($fp);}
        return ['source_family'=>'early_unreal_resource','script_records'=>$out,
            'script_opcodes_decoded'=>false,'runtime_references_resolved'=>false];
    }
}
