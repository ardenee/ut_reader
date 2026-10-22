<?php
/**
 * Source-typed UE1 SerializeExpr disk traversal, bounded supported subset.
 *
 * Unreal v1.200 Core/Src/UnClass.cpp:1095-1350, UnScript.h EExprToken;
 * UT99 v400 Core/Src/UnClass.cpp:1076+, UnStack.h EExprToken.
 * Epic XFER advances iCode by sizeof(T), but FArchive writes compact
 * names/object indices, so on-disk span can differ from ScriptSize.
 * Unknown token branches stay explicitly incomplete, never guessed.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;
use RuntimeException;

final class CatalogUE1ClassicScriptArchiveReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg,int $version,int $scriptSize,
        callable $read,callable $compact,int &$position
    ):array{
        if($scriptSize<0||$scriptSize>16000000){
            throw new RuntimeException('UE1 ScriptSize invalid');
        }
        $start=$position;$decoded=0;$nodes=[];$unknown=null;
        $mem=static function(int $bytes)use(&$decoded,$scriptSize):void{
            $decoded+=$bytes;
            if($decoded>$scriptSize){
                throw new RuntimeException('UE1 SerializeExpr decoded ScriptSize overrun');
            }
        };
        $u8=static fn():int=>ord($read(1));
        $obj=static function()use($compact,$pkg):int{
            $id=$compact();
            if(($id<0&&-$id>count($pkg->getImports()))
                ||($id>0&&$id>count($pkg->getExports()))){
                throw new RuntimeException('UE1 SerializeExpr object ref out of range');
            }
            return $id;
        };
        $name=static function()use($compact,$pkg):array{
            $id=$compact();$names=$pkg->getNames();
            if($id<0||!isset($names[$id])){
                throw new RuntimeException('UE1 SerializeExpr FName out of range');
            }
            return ['index'=>$id,'name'=>(string)$names[$id]['name']];
        };
        while($decoded<$scriptSize&&$unknown===null){
            if(count($nodes)>1000000){throw new RuntimeException('UE1 ScriptSize expression count bound');}
            $before=$position;$offset=$decoded;$token=$u8();$mem(1);
            $entry=['token'=>$token,'memory_offset'=>$offset,
                'archive_offset'=>$before,'value'=>null];
            if(in_array($token,[0x08,0x0b,0x16,0x17,0x25,0x26,0x27,
                                0x28,0x2a,0x2d,0x30,0x31],true)
                ||($version<=61&&in_array($token,[0x04,0x0e,0x15],true))){
                // A source-defined zero-operand token.
            }elseif(in_array($token,[0x00,0x01,0x02,0x20,0x29],true)){
                $entry['value']=$obj();$mem(4); // sizeof(UObject*/UProperty*)
            }elseif($token===0x21){
                $entry['value']=$name();$mem(4); // sizeof(FName)
            }elseif($token===0x1d){
                $v=(int)unpack('V',$read(4))[1];
                $entry['value']=$v>=0x80000000?$v-0x100000000:$v;$mem(4);
            }elseif($token===0x1e){
                $entry['value']=unpack('g',$read(4))[1];$mem(4);
            }elseif(in_array($token,[0x24,0x2c],true)){
                $entry['value']=$u8();$mem(1);
            }elseif($token===0x06){
                $entry['value']=unpack('v',$read(2))[1];$mem(2);
            }elseif($token===0x22){
                $entry['value']=array_values(unpack('V3',$read(12)));$mem(12);
            }elseif($token===0x23){
                $entry['value']=array_values(unpack('g3',$read(12)));$mem(12);
            }elseif($token===0x1f){
                $s='';
                for($i=0;$i<1048576;$i++){
                    $ch=$read(1);$mem(1);
                    if($ch==="\0"){break;}
                    $s.=$ch;
                }
                if($i>=1048576){
                    throw new RuntimeException('UE1 SerializeExpr ANSI string over limit');
                }
                $entry['value']=$s;
            }else{
                $unknown=sprintf('Source token 0x%02X not decoded',$token);
            }
            $entry['memory_end']=$decoded;$entry['archive_end']=$position;
            if(count($nodes)<64){$nodes[]=$entry;}
        }
        return ['status'=>$unknown===null?'script_archive_expressions_decoded':
                    'script_archive_token_unimplemented',
                'script_memory_expected'=>$scriptSize,
                'script_memory_decoded'=>$decoded,
                'archive_bytes_consumed'=>$position-$start,
                'expression_preview'=>$nodes,
                'expression_preview_truncated'=>count($nodes)>=64,
                'unsupported_branch'=>$unknown,
                'bytecode_executed'=>false,
                'runtime_refs_resolved'=>false];
    }
}
