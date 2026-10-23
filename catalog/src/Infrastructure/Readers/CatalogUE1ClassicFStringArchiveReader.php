<?php
/**
 * Epic UT99 v400 Core/Src/UnMisc.cpp::operator<<(FArchive&,FString&).
 * A signed AR_INDEX SaveNum is followed by abs(SaveNum) ANSI bytes, or
 * little-endian 16-bit Unicode units when negative. The count includes NUL.
 *
 * The decoder consumes one FString from an explicitly bounded byte segment.
 * It preserves raw archive bytes; it does NOT emulate runtime character
 * conversion or TCHAR memory allocation.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicFStringArchiveReader
{
    /** @return array{value_kind:string,value:array<string,mixed>}|null */
    public static function decodeAt(string $data,int &$position):?array
    {
        $start=$position;$total=strlen($data);
        if($position<0||$position>=$total){return null;}
        $first=ord($data[$position++]);
        $negative=($first&128)!==0;
        $count=$first&63;
        $more=($first&64)!==0;
        $shift=6;$bytes=1;
        while($more){
            if(++$bytes>5||$position>=$total){return null;}
            $ch=ord($data[$position++]);
            $count|=($ch&127)<<$shift;
            $more=($ch&128)!==0;
            $shift+=7;
        }
        if($count>0x7fffffff){return null;}
        $unitWidth=$negative?2:1;
        if($count>intdiv($total-$position,$unitWidth)){
            return null;
        }
        $length=$count*$unitWidth;
        $payload=substr($data,$position,$length);
        $position+=$length;
        if($count!==0){
            $terminator=$negative?"\0\0":"\0";
            if(substr($payload,-$unitWidth)!==$terminator){return null;}
        }
        $content=$count===0?'':substr($payload,0,-$unitWidth);
        $ascii=null;
        if($count===0){$ascii='';}
        elseif(!$negative){
            if(preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/D',$content)===1){
                $ascii=$content;
            }
        }else{
            $ascii='';
            for($i=0;$i<strlen($content);$i+=2){
                if(ord($content[$i+1])!==0||
                    preg_match('/^[\x09\x0A\x0D\x20-\x7E]$/D',$content[$i])!==1){
                    $ascii=null;break;
                }
                $ascii.=$content[$i];
            }
        }
        return [
            'value_kind'=>$negative?'fstring_utf16_archive':'fstring_ansi_archive',
            'value'=>[
                'signed_character_count'=>$negative?-$count:$count,
                'archive_encoding'=>$negative?'unicode_16bit_le':'ansi_8bit',
                'serialized_character_bytes_hex'=>bin2hex($payload),
                'ascii_text'=>$ascii,
                'nul_terminated'=>$count>0,
                'empty_on_native_load'=>$count<=1,
                'runtime_locale_conversion_verified'=>false,
            ],
        ];
    }
}
