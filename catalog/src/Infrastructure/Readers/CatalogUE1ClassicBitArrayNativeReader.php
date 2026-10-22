<?php
/**
 * Source-defined classic UE1 UBitArray and UBitMatrix archive RLE.
 *
 * Unreal v1.200 Engine/Src/UnModel.cpp::UBitArray::Serialize,
 * Engine/Inc/UnObj.h::UBitMatrix::Serialize; UT99 v400
 * Editor/Src/UnVisi.cpp uses the same loading grammar.
 *
 * UObject's tagged properties are read by the caller. This decoder handles
 * AR_INDEX(NumBits), each 1/2-byte run, and AR_INDEX(Side) for UBitMatrix.
 * It preserves exact serialized bits as run summaries without allocating
 * untrusted NumBits bytes or claiming engine runtime state.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicBitArrayNativeReader
{
    /**
     * @param callable(int):string $read Already export-bounded byte reader
     * @param callable():int $compact Signed FArchive compact index
     * @return array<string,mixed>
     */
    public static function inspect(string $kind, callable $read, callable $compact, int &$pos, int $limit): array
    {
        if(!in_array($kind,['bitarray','bitmatrix'],true)){
            throw new RuntimeException('Not a classic UE1 bit-array native serializer');
        }
        $numBits=$compact();
        if($numBits<0){
            throw new RuntimeException('UE1 BitArray NumBits cannot be negative');
        }
        $count=0;$value=0;$oneBits=0;$commands=0;$clipped=false;
        $preview=[];$runPreview=[];
        $ctx=hash_init('sha256');
        while($count<$numBits){
            // Epic's loader consumes A and optional B even for an empty run.
            // The export-bounded $read rejects unterminated/short encodings.
            $a=$read(1);
            hash_update($ctx,$a);
            $code=ord($a);$len=$code&0x3f;
            if(($code&0x40)!==0){
                $b=$read(1);hash_update($ctx,$b);
                $len=($len<<8)|ord($b);
            }
            $left=$numBits-$count;
            $actual=min($len,$left);
            if($actual!==$len){$clipped=true;}
            if($value===1){$oneBits+=$actual;}
            $previewTake=min($actual,max(0,64-count($preview)));
            for($i=0;$i<$previewTake;$i++){$preview[]=$value;}
            $count+=$actual;
            $opposite=(($code&0x80)!==0 && $count<$numBits);
            if($opposite){
                if($value===0){$oneBits++;}
                if(count($preview)<64){$preview[]=1-$value;}
                $count++;
            }else{$value=1-$value;}
            if(count($runPreview)<32){
                $runPreview[]=['length'=>$len,'value_before'=>$opposite?$value:1-$value,
                    'single_opposite_bit'=>$opposite];
            }
            $commands++;
        }
        $side=null;
        if($kind==='bitmatrix'){
            $side=$compact();
            if($side<0||$side>65535||intdiv($side*($side+1),2)!==$numBits){
                throw new RuntimeException('UE1 BitMatrix Side does not match NumBits triangle');
            }
        }
        return [
            'status'=>$clipped?'native_'.$kind.'_rle_run_clipped'
                :($pos===$limit?'native_'.$kind.'_record_complete':'native_'.$kind.'_has_extension'),
            'bit_count'=>$numBits,'set_bits'=>$oneBits,'clear_bits'=>$numBits-$oneBits,
            'bit_preview'=>$preview,'rle_command_count'=>$commands,
            'rle_runs_preview'=>$runPreview,'rle_sha256'=>hash_final($ctx),
            'rle_run_clipped'=>$clipped,'side'=>$side,'extension_bytes'=>$limit-$pos,
        ];
    }
}
