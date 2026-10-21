<?php
/**
 * Original Unrealfile v22/v23 UBitArray and UBitMatrix custom RLE.
 *
 * Epic v0.867 Src/Engine/UnObj.cpp UBitArray::SerializeData:
 * initial BOOL Value=0, BYTE A low six run bits, bit 0x40 extends run
 * length with a second byte; bit 0x80 is a one-bit opposite flip.
 * UBitMatrix adds DWORD Side to UBitArray header.
 * UDatabase::SerializeHeader serializes Num/Max before NumBits/Side.
 * Preload/class runtime effects not reproduced here.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1OriginalUnrealfileBitArrayReader
{
    /** @return array<string,mixed> */
    public static function read(string $path,bool $strict=false):array
    {
        $meta=CatalogUE1OriginalUnrealfileObjectBaseReader::read($path);
        $stacks=CatalogUE1OriginalUnrealfileStackReader::read($path,false)['stack_prefixes'];
        $byIndex=[];
        foreach($stacks as $s)$byIndex[(int)$s['object_index']]=$s;
        $out=[];$fp=@fopen($path,'rb');
        if(!$fp)throw new RuntimeException('Cannot open 1997 original bit archive');
        try{
            foreach($meta['object_bases'] as $obj){
                if($obj['kind']!=='export')continue;
                $cls=(string)$obj['class_name'];
                if(!in_array(strtolower($cls),['bitarray','bitmatrix'],true))continue;
                $isMatrix=strcasecmp($cls,'BitMatrix')===0;
                $index=(int)$obj['index'];$stack=$byIndex[$index]??[];
                $required=$isMatrix?16:12;
                if(($stack['status']??null)!=='native_stack_prefix_decoded'
                    ||(int)($stack['class_member_bytes_remaining']??0)<$required){
                    if($strict)throw new RuntimeException('Bit archive header lacks native UDatabase/NumBits/Side');
                    $out[]=['object_index'=>$index,'status'=>'incomplete_original_bit_header',
                        'bit_values_decoded'=>false];continue;
                }
                $offset=(int)$obj['file_header_offset']+(int)$stack['serialized_prefix_bytes'];
                $head=self::readSpan($fp,$offset,$required);
                $words=array_values(unpack('V'.($isMatrix?4:3),$head));
                [$num,$max,$bitCount]=$words;
                if($num>$max||$bitCount>10000000||$num>2000000
                    ||($num*32<$bitCount))
                    throw new RuntimeException('Original 1997 BitArray Num/Max/NumBits invalid');
                $side=$isMatrix?$words[3]:null;
                if($isMatrix && ($side>100000||$side*($side+1)/2!==$bitCount))
                    throw new RuntimeException('BitMatrix Side does not match triangular NumBits');
                $dataSize=(int)$obj['file_data_size'];
                if($dataSize<0||$dataSize>16000000)
                    throw new RuntimeException('Original BitArray RLE archive too long');
                $stream=self::readSpan($fp,(int)$obj['file_data_offset'],$dataSize);
                $pos=0;$bits='';$value='0';$steps=0;
                while(strlen($bits)<$bitCount){
                    if($pos>=strlen($stream))
                        throw new RuntimeException('Original UBitArray RLE stream truncated');
                    $a=ord($stream[$pos++]);
                    $run=$a&0x3f;
                    if(($a&0x40)!==0){
                        if($pos>=strlen($stream))
                            throw new RuntimeException('Original BitArray extended RLE stream truncated');
                        $run=($run<<8)|ord($stream[$pos++]);
                    }
                    $remaining=$bitCount-strlen($bits);
                    $used=min($run,$remaining);$bits.=str_repeat($value,$used);
                    if(($a&0x80)!==0&&strlen($bits)<$bitCount){
                        $bits.=($value==='0'?'1':'0');
                    }else{
                        $value=($value==='0'?'1':'0');
                    }
                    $steps++;
                    if($steps>$dataSize+1)throw new RuntimeException('Original bit archive made no bounded progress');
                }
                $extra=$dataSize-$pos;
                if($strict&&$extra>0)
                    throw new RuntimeException('Original bit archive has unconsumed RLE stream bytes');
                $out[]=[
                    'object_index'=>$index,'source_class'=>$cls,
                    'status'=>$extra===0?'native_bit_rle_decoded':'native_bit_rle_trailing_bytes',
                    'database_num_words'=>$num,'database_max_words'=>$max,
                    'num_bits'=>$bitCount,'matrix_side'=>$side,
                    'bits_binary'=>$bits,'bits_set_count'=>substr_count($bits,'1'),
                    'rle_serialized_bytes'=>$pos,'rle_total_bytes'=>$dataSize,
                    'unconsumed_stream_bytes'=>$extra,'rle_steps'=>$steps,
                    'runtime_class_references_resolved'=>false,
                    'bit_values_decoded'=>true,
                ];
            }
        }finally{fclose($fp);}
        return ['source_format'=>'early_unrealfile','bit_archive_objects'=>$out,
            'runtime_objects_created'=>false,'custom_serialize_data_decoded'=>true];
    }
    private static function readSpan($fp,int $offset,int $length):string
    {
        if($length<0||$length>16000000||fseek($fp,$offset,SEEK_SET)!==0)
            throw new RuntimeException('Cannot seek native bit archive span');
        $buf='';
        while(strlen($buf)<$length){
            $part=fread($fp,min(65536,$length-strlen($buf)));
            if(!is_string($part)||$part==='')
                throw new RuntimeException('Truncated native bit archive segment');
            $buf.=$part;
        }
        return $buf;
    }
}
