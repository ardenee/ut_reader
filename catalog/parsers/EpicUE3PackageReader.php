<?php
/**
 * Strict Epic UE3 package metadata reader for the catalog.
 * Serialized layouts are taken from Epic UE3 FPackageFileSummary/FNameEntry/
 * FObjectImport/FObjectExport archive operators; no alternate layout guessing.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/LzoDecoder.php';
require_once __DIR__ . '/../lib/LzxDecoder.php';

final class CatalogEpicUE3BinaryReader
{
    public function __construct(private readonly string $data, private int $pos = 0, private bool $swap = false) {}
    public function tell(): int { return $this->pos; }
    public function remaining(): int { return strlen($this->data) - $this->pos; }
    public function setByteSwap(bool $swap): void { $this->swap = $swap; }
    public function seek(int $pos): void
    {
        if ($pos < 0 || $pos > strlen($this->data)) throw new OutOfBoundsException("UE3 seek $pos outside " . strlen($this->data));
        $this->pos = $pos;
    }
    public function bytes(int $len, string $field): string
    {
        if ($len < 0 || $len > $this->remaining()) throw new OutOfBoundsException("UE3 $field length=$len pos={$this->pos} remaining={$this->remaining()}");
        $out = substr($this->data, $this->pos, $len); $this->pos += $len; return $out;
    }
    public function u32(string $field): int
    {
        $v = unpack($this->swap ? 'Nn' : 'Vn', $this->bytes(4, $field));
        if (!is_array($v) || !isset($v['n'])) throw new RuntimeException("Could not decode UE3 $field");
        return (int)$v['n'];
    }
    public function i32(string $field): int
    {
        $v = $this->u32($field); return ($v & 0x80000000) ? $v - 0x100000000 : $v;
    }
    /** @return array{low:int,high:int} */
    public function qword(string $field): array
    {
        if ($this->swap) { $hi=$this->u32("$field.high"); $lo=$this->u32("$field.low"); }
        else { $lo=$this->u32("$field.low"); $hi=$this->u32("$field.high"); }
        return ['low'=>$lo,'high'=>$hi];
    }
    /** @return array{parts:array<int,int>,text:string} */
    public function guid(string $field): array
    {
        $p=[$this->u32("$field.A"),$this->u32("$field.B"),$this->u32("$field.C"),$this->u32("$field.D")];
        return ['parts'=>$p,'text'=>sprintf('%08X-%08X-%08X-%08X',...$p)];
    }
    public function fstring(string $field): string
    {
        $len=$this->i32("$field.length"); if ($len===0) return '';
        if ($len>0) {
            $raw=$this->bytes($len,$field);
            if (str_ends_with($raw,"\0")) $raw=substr($raw,0,-1);
            return $raw==='' ? '' : $this->ansiToUtf8($raw);
        }
        $chars=-$len; if ($chars > intdiv(PHP_INT_MAX,2)) throw new OutOfBoundsException("UE3 $field Unicode length overflow");
        $raw=$this->bytes($chars*2,$field); if (str_ends_with($raw,"\0\0")) $raw=substr($raw,0,-2); if ($raw==='') return '';
        $enc=$this->swap?'UTF-16BE':'UTF-16LE';
        if (function_exists('mb_convert_encoding')) return (string)mb_convert_encoding($raw,'UTF-8',$enc);
        $out=function_exists('iconv')?@iconv($enc,'UTF-8//IGNORE',$raw):false;
        if ($out===false) throw new RuntimeException('UE3 Unicode FString requires mbstring or iconv');
        return $out;
    }
    private function ansiToUtf8(string $raw): string
    {
        $out='';
        for ($i=0,$length=strlen($raw);$i<$length;$i++) {
            $code=ord($raw[$i]);
            if ($code<0x80) $out.=chr($code);
            else $out.=chr(0xC0|($code>>6)).chr(0x80|($code&0x3F));
        }
        return $out;
    }
}

final class CatalogUE3PackageReader
{
    private const TAG=0x9E2A83C1, TAG_SWAPPED=0xC1832A9E;
    private const VER_ADDITIONAL_COOK_PACKAGE_SUMMARY=516, VER_REMOVED_COMPONENT_MAP=543,
        VER_ASSET_THUMBNAILS_IN_PACKAGES=584, VER_ADDED_CROSSLEVEL_REFERENCES=623, MAX_EPIC_UE3_VERSION=867;
    private const COMPRESS_ZLIB=1, COMPRESS_LZO=2, COMPRESS_LZX=4, COMPRESS_TYPE_MASK=0x0F;

    private string $physical='', $logical=''; private bool $swap=false;
    /** @var array<string,mixed> */ private array $header=[];
    /** @var array<int,array<string,mixed>> */ private array $names=[], $imports=[], $exports=[];
    /** @var array<int,string> */ private array $issues=[];

    public function __construct(private readonly string $path)
    {
        try {
            $bytes=file_get_contents($path); if ($bytes===false) throw new RuntimeException("Failed to read UE3 package: $path");
            // PHP strings are copy-on-write, so this is one physical allocation
            // until compressed reconstruction starts.
            $this->physical=$this->logical=$bytes; unset($bytes); $this->parse();
        } catch (Throwable $e) { $this->issues[]=$this->formatError($e); if (!$this->header) $this->header=$this->blankHeader(); }
    }
    /** @return array<string,mixed> */
    private function blankHeader(): array
    {
        return ['signature'=>0,'tag'=>0,'packedVersion'=>0,'version'=>0,'licensee'=>0,'licenseeVersion'=>0,'headerSize'=>0,
            'totalHeaderSize'=>0,'folderName'=>'','packageFlags'=>0,'pkgFlags'=>0,'nameCount'=>0,'nameOffset'=>0,'exportCount'=>0,
            'exportOffset'=>0,'importCount'=>0,'importOffset'=>0,'dependsOffset'=>0,'guid'=>'','guidArray'=>[],'generations'=>[],
            'compressionFlags'=>0,'cFlags'=>0,'chunks'=>[],'compressedChunks'=>[],'compressed'=>false,'logicalDecompressed'=>false,
            'logicalSize'=>0,'nameTableLayout'=>'epic-fstring-objectflags64','exportTableLayout'=>'epic-ue3'];
    }
    private function parse(): void
    {
        $r=new CatalogEpicUE3BinaryReader($this->physical); $rawTag=$r->u32('PackageFileTag');
        if ($rawTag===self::TAG_SWAPPED) { $r->setByteSwap(true); $this->swap=true; }
        elseif ($rawTag!==self::TAG) throw new RuntimeException(sprintf('Not an Epic UE3 package: tag=0x%08X',$rawTag));

        $packed=$r->u32('FileVersion'); $ver=$packed&0xffff; $lic=($packed>>16)&0xffff;
        if ($ver<=0 || $ver>self::MAX_EPIC_UE3_VERSION) throw new RuntimeException(
            "Unsupported/corrupt Epic UE3 package version=$ver licensee=$lic packed=".sprintf('0x%08X',$packed)
            .'; Epic UE3 source defines engine package versions through '.self::MAX_EPIC_UE3_VERSION);

        $h=$this->blankHeader(); $h['signature']=$h['tag']=self::TAG; $h['sourceTag']=$rawTag; $h['byteSwapped']=$this->swap;
        $h['packedVersion']=$packed; $h['version']=$ver; $h['licensee']=$h['licenseeVersion']=$lic;
        $h['totalHeaderSize']=$h['headerSize']=$r->i32('TotalHeaderSize'); $h['folderName']=$r->fstring('FolderName');
        $h['packageFlags']=$h['pkgFlags']=$r->u32('PackageFlags');
        $h['nameCount']=$r->i32('NameCount'); $h['nameOffset']=$r->i32('NameOffset');
        $h['exportCount']=$r->i32('ExportCount'); $h['exportOffset']=$r->i32('ExportOffset');
        $h['importCount']=$r->i32('ImportCount'); $h['importOffset']=$r->i32('ImportOffset'); $h['dependsOffset']=$r->i32('DependsOffset');
        if ($ver>=self::VER_ADDED_CROSSLEVEL_REFERENCES) {
            $h['importExportGuidsOffset']=$r->i32('ImportExportGuidsOffset'); $h['importGuidsCount']=$r->i32('ImportGuidsCount');
            $h['exportGuidsCount']=$r->i32('ExportGuidsCount');
        }
        if ($ver>=self::VER_ASSET_THUMBNAILS_IN_PACKAGES) $h['thumbnailTableOffset']=$r->i32('ThumbnailTableOffset');
        $guid=$r->guid('Guid'); $h['guidArray']=$guid['parts']; $h['guid']=$guid['text'];
        $gc=$r->i32('GenerationCount'); $this->arrayFits($r,$gc,12,'GenerationCount'); $h['genCount']=$gc;
        for ($i=0;$i<$gc;$i++) {
            $e=$r->i32("Generations[$i].ExportCount"); $n=$r->i32("Generations[$i].NameCount"); $net=$r->i32("Generations[$i].NetObjectCount");
            $h['generations'][]=['e'=>$e,'n'=>$n,'exportCount'=>$e,'nameCount'=>$n,'netObjectCount'=>$net];
        }
        $h['engineVersion']=$r->i32('EngineVersion'); $h['cookedContentVersion']=$h['cookerVersion']=$r->i32('CookedContentVersion');
        $h['compressionFlags']=$h['cFlags']=$r->u32('CompressionFlags');
        $cc=$r->i32('CompressedChunks.Count'); $this->arrayFits($r,$cc,16,'CompressedChunks.Count');
        for ($i=0;$i<$cc;$i++) {
            $c=['uOff'=>$r->i32("CompressedChunks[$i].UncompressedOffset"),'uLen'=>$r->i32("CompressedChunks[$i].UncompressedSize"),
                'cOff'=>$r->i32("CompressedChunks[$i].CompressedOffset"),'cLen'=>$r->i32("CompressedChunks[$i].CompressedSize")];
            if (min($c)<0) throw new RuntimeException("Negative Epic UE3 compressed chunk field at index $i"); $h['chunks'][]=$c;
        }
        $h['compressedChunks']=$h['chunks']; $h['compressed']=$cc>0; $h['packageSource']=$h['u3unk60']=$r->u32('PackageSource');
        if ($ver>=self::VER_ADDITIONAL_COOK_PACKAGE_SUMMARY) {
            $ac=$r->i32('AdditionalPackagesToCook.Count'); $this->arrayFits($r,$ac,4,'AdditionalPackagesToCook.Count');
            $h['additionalPackagesToCook']=[]; for ($i=0;$i<$ac;$i++) $h['additionalPackagesToCook'][]=$r->fstring("AdditionalPackagesToCook[$i]");
        }
        $this->header=$h;
        if ($cc) {
            // Release every reference to the full compressed file before the
            // logical package is assembled. Compressed chunks are read from disk
            // one at a time, bounding peak memory to logical package + one chunk.
            unset($r);
            $this->logical='';
            $this->physical='';
            $this->inflatePackage();
        }
        $this->header['logicalSize']=strlen($this->logical);
        $this->validateSummary(); $this->readNames(); $this->readImports(); $this->readExports();
        $this->physical='';
    }
    private function arrayFits(CatalogEpicUE3BinaryReader $r,int $count,int $minBytes,string $field): void
    {
        if ($count<0 || ($minBytes && $count>intdiv($r->remaining(),$minBytes))) throw new OutOfBoundsException("Epic UE3 $field=$count cannot fit remaining={$r->remaining()}");
    }
    private function validateSummary(): void
    {
        $size=strlen($this->logical); $hs=(int)$this->header['totalHeaderSize'];
        if ($hs<=0 || $hs>$size) throw new RuntimeException("Invalid Epic UE3 TotalHeaderSize=$hs logicalSize=$size");
        foreach ([['Name',12],['Import',28],['Export',48]] as [$label,$min]) {
            $count=(int)$this->header[strtolower($label).'Count']; $off=(int)$this->header[strtolower($label).'Offset'];
            if ($count<0 || $off<0 || $off>$size || ($count>0 && ($off===0 || $count>intdiv($size-$off,$min))))
                throw new RuntimeException("Invalid Epic UE3 $label table count=$count offset=$off logicalSize=$size");
        }
    }
    private function table(int $offset): CatalogEpicUE3BinaryReader { $r=new CatalogEpicUE3BinaryReader($this->logical,0,$this->swap); $r->seek($offset); return $r; }
    private function readNames(): void
    {
        $r=$this->table((int)$this->header['nameOffset']);
        for ($i=0,$n=(int)$this->header['nameCount'];$i<$n;$i++) {
            $name=$r->fstring("NameMap[$i].Name"); $flags=$r->qword("NameMap[$i].Flags");
            $this->names[]=['index'=>$i,'name'=>$name,'text'=>$name,'flags'=>$flags['low'],'objectFlags'=>$flags['low'],'objectFlagsHigh'=>$flags['high']];
        }
    }
    /** @return array{index:int,number:int} */
    private function fname(CatalogEpicUE3BinaryReader $r,string $field): array
    {
        $index=$r->i32("$field.Index"); $number=$r->i32("$field.Number");
        if ($index<0 || !isset($this->names[$index])) throw new RuntimeException("Invalid Epic UE3 FName index=$index for $field nameCount=".count($this->names));
        return ['index'=>$index,'number'=>$number];
    }
    private function readImports(): void
    {
        $r=$this->table((int)$this->header['importOffset']);
        for ($i=0,$n=(int)$this->header['importCount'];$i<$n;$i++) {
            $cp=$this->fname($r,"ImportMap[$i].ClassPackage"); $cn=$this->fname($r,"ImportMap[$i].ClassName");
            $outer=$r->i32("ImportMap[$i].OuterIndex"); $on=$this->fname($r,"ImportMap[$i].ObjectName");
            $cpt=$this->name($cp); $cnt=$this->name($cn); $ont=$this->name($on);
            $this->imports[]=['index'=>$i,'classPackage'=>$cp['index'],'className'=>$cn['index'],'outerIndex'=>$outer,'outer'=>$outer,
                'objectName'=>$on['index'],'classPackageText'=>$cpt,'classNameText'=>$cnt,'objectNameText'=>$ont,
                'ClassPackage'=>['index'=>$cp['index'],'number'=>$cp['number'],'text'=>$cpt],
                'ClassName'=>['index'=>$cn['index'],'number'=>$cn['number'],'text'=>$cnt],'OuterIndex'=>$outer,
                'ObjectName'=>['index'=>$on['index'],'number'=>$on['number'],'text'=>$ont]];
        }
    }
    private function readExports(): void
    {
        $r=$this->table((int)$this->header['exportOffset']); $ver=(int)$this->header['version'];
        for ($i=0,$n=(int)$this->header['exportCount'];$i<$n;$i++) {
            $class=$r->i32("ExportMap[$i].ClassIndex"); $super=$r->i32("ExportMap[$i].SuperIndex"); $outer=$r->i32("ExportMap[$i].OuterIndex");
            $on=$this->fname($r,"ExportMap[$i].ObjectName"); $arch=$r->i32("ExportMap[$i].ArchetypeIndex"); $flags=$r->qword("ExportMap[$i].ObjectFlags");
            $size=$r->i32("ExportMap[$i].SerialSize"); $off=$r->i32("ExportMap[$i].SerialOffset");
            if ($size<0 || $off<0) throw new RuntimeException("Invalid Epic UE3 export serial range export=$i size=$size offset=$off");
            $components=$ver<self::VER_REMOVED_COMPONENT_MAP?$this->componentMap($r,$i):[]; $exportFlags=$r->u32("ExportMap[$i].ExportFlags");
            $nc=$r->i32("ExportMap[$i].GenerationNetObjectCount.Count"); $this->arrayFits($r,$nc,4,"ExportMap[$i].GenerationNetObjectCount.Count");
            $net=[]; for ($j=0;$j<$nc;$j++) $net[]=$r->i32("ExportMap[$i].GenerationNetObjectCount[$j]");
            $guid=$r->guid("ExportMap[$i].PackageGuid"); $packageFlags=$r->u32("ExportMap[$i].PackageFlags"); $text=$this->name($on);
            $this->exports[]=['index'=>$i,'classIndex'=>$class,'class'=>$class,'classIndexRef'=>$class,'superIndex'=>$super,'super'=>$super,
                'superIndexRef'=>$super,'packageIndex'=>$outer,'outerIndex'=>$outer,'outer'=>$outer,'outerIndexRef'=>$outer,'objectName'=>$on['index'],
                'nameIndex'=>$on['index'],'nameNumber'=>$on['number'],'objectNameText'=>$text,'archetype'=>$arch,'archetypeIndexRef'=>$arch,
                'objectFlags'=>$flags['low'],'objectFlagsHigh'=>$flags['high'],'serialSize'=>$size,'serialOffset'=>$off,'components'=>$components,
                'componentMap'=>$components,'exportFlags'=>$exportFlags,'netObjectCount'=>$net,'guid'=>$guid['text'],'packageFlags'=>$packageFlags,'u3unk6C'=>$packageFlags];
        }
    }
    /** @return array<int,array<string,mixed>> */
    private function componentMap(CatalogEpicUE3BinaryReader $r,int $export): array
    {
        $count=$r->i32("ExportMap[$export].ComponentMap.Count"); $this->arrayFits($r,$count,12,"ExportMap[$export].ComponentMap.Count"); $out=[];
        for ($i=0;$i<$count;$i++) { $name=$this->fname($r,"ExportMap[$export].ComponentMap[$i].Name"); $ref=$r->i32("ExportMap[$export].ComponentMap[$i].Reference");
            $out[]=['name'=>$name['index'],'nameNumber'=>$name['number'],'nameText'=>$this->name($name),'ref'=>$ref]; }
        return $out;
    }
    /** @param array{index:int,number:int} $fname */
    private function name(array $fname): string
    {
        $base=(string)($this->names[$fname['index']]['name']??''); return $fname['number']!==0 && $base!==''?$base.'_'.$fname['number']:$base;
    }
    private function inflatePackage(): void
    {
        $chunks=(array)$this->header['chunks'];
        if ($chunks===[]) return;
        $physicalSize=filesize($this->path);
        if ($physicalSize===false || $physicalSize<1) throw new RuntimeException('Could not determine Epic UE3 physical package size');
        $logicalSize=(int)$physicalSize;
        foreach ($chunks as $index => $c) {
            $uOff=(int)$c['uOff'];
            $uLen=(int)$c['uLen'];
            $cOff=(int)$c['cOff'];
            $cLen=(int)$c['cLen'];
            $logicalSize=max($logicalSize,$uOff+$uLen);
            if ($cOff+$cLen>$physicalSize) {
                throw new RuntimeException(
                    'Epic UE3 compressed chunk exceeds physical package size: '
                    . 'chunk=' . $index
                    . ' compressed_offset=' . $cOff
                    . ' compressed_size=' . $cLen
                    . ' compressed_end=' . ($cOff+$cLen)
                    . ' physical_size=' . $physicalSize
                    . ' uncompressed_offset=' . $uOff
                    . ' uncompressed_size=' . $uLen
                    . ' compression_flags=' . sprintf('0x%08X',(int)$this->header['compressionFlags'])
                    . ' chunk_count=' . count($chunks)
                    . ' package_version=' . (int)$this->header['version']
                    . ' licensee_version=' . (int)$this->header['licenseeVersion']
                );
            }
        }
        usort($chunks,static fn(array $a,array $b):int=>(int)$a['uOff']<=>(int)$b['uOff']);
        $first=(int)$chunks[0]['uOff'];
        if ($first<0 || $first>$physicalSize) throw new RuntimeException('Invalid first Epic UE3 compressed chunk offset');

        $handle=fopen($this->path,'rb');
        if (!is_resource($handle)) throw new RuntimeException('Could not reopen Epic UE3 package for chunk decompression');
        try {
            $logical=$this->readFileRange($handle,0,$first,'uncompressed prefix');
            $cursor=strlen($logical);
            foreach ($chunks as $i=>$c) {
                $uOff=(int)$c['uOff']; $uLen=(int)$c['uLen'];
                if ($uOff<$cursor) throw new RuntimeException('Overlapping Epic UE3 compressed chunk ranges are invalid');
                if ($uOff>$cursor) $logical.=str_repeat("\0",$uOff-$cursor);
                $payload=$this->readFileRange($handle,(int)$c['cOff'],(int)$c['cLen'],"compressed chunk $i");
                $decoded=$this->inflateChunk($payload,$uLen,(int)$i);
                $logical.=$decoded;
                $cursor=$uOff+$uLen;
                unset($payload,$decoded);
            }
            if ($cursor<$logicalSize) $logical.=str_repeat("\0",$logicalSize-$cursor);
            $this->logical=$logical;
        } finally {
            fclose($handle);
        }
        $this->header['logicalDecompressed']=true;
    }
    /** @param resource $handle */
    private function readFileRange($handle,int $offset,int $length,string $field): string
    {
        if ($offset<0 || $length<0 || fseek($handle,$offset,SEEK_SET)!==0) throw new RuntimeException("Could not seek Epic UE3 $field");
        $out='';
        while (strlen($out)<$length) {
            $chunk=fread($handle,$length-strlen($out));
            if ($chunk===false || $chunk==='') break;
            $out.=$chunk;
        }
        if (strlen($out)!==$length) throw new RuntimeException("Could not read complete Epic UE3 $field expected=$length got=".strlen($out));
        return $out;
    }
    private function inflateChunk(string $payload,int $expected,int $index): string
    {
        $r=new CatalogEpicUE3BinaryReader($payload,0,$this->swap); if ($r->u32("CompressedChunk[$index].Tag")!==self::TAG) throw new RuntimeException('Invalid Epic UE3 compressed chunk tag');
        $blockSize=$r->i32('CompressedChunk.BlockSize'); $compressedTotal=$r->i32('CompressedChunk.CompressedSize'); $total=$r->i32('CompressedChunk.UncompressedSize');
        if ($blockSize<=0 || $compressedTotal<0 || $total<0 || ($expected>0 && $total!==$expected)) throw new RuntimeException("Invalid Epic UE3 compressed chunk header expected=$expected actual=$total");
        $count=$total===0?0:(int)ceil($total/$blockSize); $this->arrayFits($r,$count,8,'CompressedChunk.Blocks'); $blocks=[];
        for ($i=0;$i<$count;$i++) { $c=$r->i32("CompressedChunk.Block[$i].CompressedSize"); $u=$r->i32("CompressedChunk.Block[$i].UncompressedSize"); if ($c<0 || $u<0) throw new RuntimeException('Negative Epic UE3 compressed block size'); $blocks[]=[$c,$u]; }
        $out=''; foreach ($blocks as $i=>[$c,$u]) $out.=$this->inflateBlock($r->bytes($c,"CompressedChunk.Block[$i].Data"),$u);
        if (strlen($out)!==$total) throw new RuntimeException("Epic UE3 compressed chunk size mismatch expected=$total got=".strlen($out)); return $out;
    }
    private function inflateBlock(string $src,int $expected): string
    {
        $flags=(int)$this->header['compressionFlags']; $algo=$flags&self::COMPRESS_TYPE_MASK;
        if ($algo===self::COMPRESS_ZLIB) { $out=@gzuncompress($src); if ($out===false) $out=@gzdecode($src); if ($out===false) throw new RuntimeException('Epic UE3 zlib decompression failed'); }
        elseif ($algo===self::COMPRESS_LZO) $out=CatalogLzoDecoder::decompressLzo1x($src,$expected);
        elseif ($algo===self::COMPRESS_LZX) $out=CatalogLzxDecoder::decompress($src,$expected,17);
        else throw new RuntimeException('Unsupported Epic UE3 compression flags='.sprintf('0x%08X',$flags));
        if (strlen($out)!==$expected) throw new RuntimeException("Epic UE3 compressed block size mismatch expected=$expected got=".strlen($out)); return $out;
    }

    /** @return array<string,mixed> */ public function getHeader(): array { return $this->header; }
    /** @return array<int,array<string,mixed>> */ public function getNames(): array { return $this->names; }
    /** @return array<int,array<string,mixed>> */ public function getImports(): array { return $this->imports; }
    /** @return array<int,array<string,mixed>> */ public function getExports(): array { return $this->exports; }
    /** @return array<int,string> */ public function getIssues(): array { return $this->issues; }
    /** @return array<int,string> */ public function getDebugErrors(): array { return $this->issues; }
    /** @return array<int,string> */ public function validatePackage(): array { return $this->issues; }
    private function formatError(Throwable $e): string
    {
        return get_class($e).': '.$e->getMessage().' File: '.$e->getFile().':'.$e->getLine().' PHP: '.PHP_VERSION.' Package: '.$this->path.' Trace: '.$e->getTraceAsString();
    }
}
