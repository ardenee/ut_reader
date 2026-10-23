<?php
/** UE1 compact signed index, one value at a bounded archive cursor. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

final class CatalogUE1ClassicCompactIndexReader
{
    public static function readAt(string $data,int &$position):?int
    {
        if($position<0||$position>=strlen($data)){return null;}
        $first=ord($data[$position++]);
        $negative=($first&128)!==0;
        $value=$first&63;$more=($first&64)!==0;
        $shift=6;$bytes=1;
        while($more){
            if(++$bytes>5||$position>=strlen($data)){return null;}
            $byte=ord($data[$position++]);
            $value|=($byte&127)<<$shift;
            $more=($byte&128)!==0;
            $shift+=7;
        }
        if($value>0x7fffffff){return null;}
        return $negative?-$value:$value;
    }
}
