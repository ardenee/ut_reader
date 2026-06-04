<?php
/**
 * readfile6_fixed.php — Hardened UE1/UE2 package reader (UT99/UT2003/UT2004)
 * - Export.PackageIndex => INDEX
 * - Export.SerialOffset => INDEX
 * - Robust name/ref resolution (no undefined index; no null to nameOf)
 * - Debug trace remains (&debug=1)
 */
declare(strict_types=1);
ini_set('memory_limit','512M');
error_reporting(E_ALL);

final class UE2PackageReader {
    private string $path;
    private $fp;
    private int $filesize = 0;
    private bool $debug = true;
    private array $trace = [];
    private string $phase = 'init';

    // Header
    public int $tag = 0;
    public int $fileVersionEpic = 0;
    public int $fileVersionLicensee = 0;
    public int $packageFlags = 0;
    public int $nameCount = 0;
    public int $nameOffset = 0;
    public int $exportCount = 0;
    public int $exportOffset = 0;
    public int $importCount = 0;
    public int $importOffset = 0;
    public ?string $guid = null;
    public array $generations = [];
    public array $heritageGuids = [];

    // Tables
    public array $names = [];
    public array $imports = [];
    public array $exports = [];

    // Flag maps (expanded UE1/UE2 superset)
    private array $PKG_FLAGS = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000002 => 'PKG_ClientOptional',
        0x00000004 => 'PKG_ServerSideOnly',
        0x00000008 => 'PKG_Cooked',
        0x00000010 => 'PKG_Unused10',
        0x00000020 => 'PKG_Encrypted',
        0x00000040 => 'PKG_SeekFree',
        0x00000080 => 'PKG_NoExportAllowed',
        0x00000100 => 'PKG_Stripped',
        0x00000200 => 'PKG_ContainsMap',
        0x00000400 => 'PKG_Localized',
        0x00000800 => 'PKG_SavedWithNewerVersion',
        0x00001000 => 'PKG_Trash',
        0x00002000 => 'PKG_ContainsScript',
        0x00004000 => 'PKG_DisallowLazyLoading',
        0x00008000 => 'PKG_BrokenLinks',
    ];
    private array $OBJ_FLAGS = [
        0x00000001=>'RF_Public',
        0x00000002=>'RF_Standalone',
        0x00000004=>'RF_Transactional',
        0x00000008=>'RF_ClassDefaultObject',
        0x00000010=>'RF_ArchetypeObject',
        0x00000020=>'RF_TagGarbage',
        0x00000040=>'RF_Transient',
        0x00000080=>'RF_RootSet',
        0x00000100=>'RF_TagGarbageTemp',
        0x00000200=>'RF_NeedInitialization',
        0x00000400=>'RF_NeedLoad',
        0x00000800=>'RF_KeepForCooker',
        0x00001000=>'RF_NeedPostLoad',
        0x00002000=>'RF_NeedPostLoadSubobjects',
        0x00004000=>'RF_NewerVersionExists',
        0x00008000=>'RF_BeginDestroyed',
        0x00010000=>'RF_FinishDestroyed',
        0x00020000=>'RF_LoadForClient',
        0x00040000=>'RF_LoadForServer',
        0x00080000=>'RF_LoadForEdit',
        0x00100000=>'RF_NotForClient',
        0x00200000=>'RF_NotForServer',
        0x00400000=>'RF_NotForEdit',
        0x00800000=>'RF_Native',
        0x01000000=>'RF_Marked',
        0x02000000=>'RF_ErrorShutdown',
        0x04000000=>'RF_TagExp',
        0x08000000=>'RF_Cooked',
    ];

    public function __construct(string $path, bool $debug=true) {
        if (!is_file($path)) throw new RuntimeException("File not found: $path");
        $this->path = $path;
        $this->debug = $debug;
        $this->fp = fopen($path,'rb');
        if (!$this->fp) throw new RuntimeException("Cannot open $path");
        $this->filesize = filesize($path) ?: 0;
        $this->log("open filesize=".$this->filesize);
    }
    public function __destruct() { if (is_resource($this->fp)) fclose($this->fp); }

    /* ---------------- helpers: tracing ---------------- */
    private function log(string $msg): void {
        if ($this->debug) $this->trace[] = sprintf("[%s @0x%X] %s", $this->phase, $this->tell(), $msg);
    }
    private function dumpTrace(): string { return $this->debug ? ("\n\n--- TRACE ---\n".implode("\n",$this->trace)."\n") : ""; }

    /* ---------------- low-level readers ---------------- */
    private function seek(int $pos): void {
        if ($pos < 0 || $pos > $this->filesize) throw new RuntimeException("seek($pos) out of range");
        fseek($this->fp, $pos, SEEK_SET);
    }
    private function tell(): int { return ftell($this->fp); }
    private function readBytes(int $n): string {
        if ($n < 0) throw new RuntimeException("readBytes($n): negative");
        $buf = ($n===0)?'':fread($this->fp,$n);
        if ($buf===false || strlen($buf)!==$n) {
            $off = $this->tell();
            throw new RuntimeException("Unexpected EOF at $off reading $n bytes");
        }
        return $buf;
    }
    private function readU8(): int { $v=ord($this->readBytes(1)); $this->log("U8=$v"); return $v; }
    private function readU32(): int { $v=unpack('V', $this->readBytes(4))[1]; $this->log("U32=0x".strtoupper(str_pad(dechex($v),8,'0',STR_PAD_LEFT))); return $v; }
    private function readI32(): int { $u=$this->readU32(); $i = ($u&0x80000000)? -((~$u&0xFFFFFFFF)+1) : $u; $this->log("I32=$i"); return $i; }
    private function readGUID(): string {
        $b=$this->readBytes(16); $u=unpack('Vd1/vd2/vd3/C8r',$b);
        $s=sprintf('%08X-%04X-%04X-%02X%02X-%02X%02X%02X%02X%02X%02X',$u['d1'],$u['d2'],$u['d3'],$u['r1'],$u['r2'],$u['r3'],$u['r4'],$u['r5'],$u['r6'],$u['r7'],$u['r8']);
        $this->log("GUID=$s"); return $s;
    }
    private function readINDEX(): int {
        $b0=$this->readU8(); $neg=($b0&0x80)!==0; $val=($b0&0x3F);
        if ($b0&0x40){$b1=$this->readU8();$val=($val<<7)|($b1&0x7F);
            if($b1&0x80){$b2=$this->readU8();$val=($val<<7)|($b2&0x7F);
                if($b2&0x80){$b3=$this->readU8();$val=($val<<7)|($b3&0x7F);
                    if($b3&0x80){$b4=$this->readU8();$val=($val<<8)|$b4;}}}}
        $v = $neg? -$val : $val;
        $this->log("INDEX=$v");
        return $v;
    }
    // UE1/UE2 name: BYTE length + chars + DWORD flags (fallback ASCIIZ)
    private function readNAME(int $fileVersion): array {
        $start=$this->tell();
        $len=$this->readU8();
        if ($len>=1 && $len<=255 && ($start+1+$len+4)<= $this->filesize) {
            $raw=$this->readBytes($len); $name=rtrim($raw,"\x00"); $flags=$this->readU32();
            $this->log("NAME(len=$len) \"$name\" flags=0x".strtoupper(str_pad(dechex($flags),8,'0',STR_PAD_LEFT)));
            return ['name'=>$name,'flags'=>$flags];
        }
        // fallback ASCIIZ
        $s=''; while(true){$c=$this->readU8(); if($c===0)break; $s.=chr($c); if(strlen($s)>1<<20)throw new RuntimeException("NAME too long at $start");}
        $flags=$this->readU32();
        $this->log("NAME(asciiz) \"$s\" flags=0x".strtoupper(str_pad(dechex($flags),8,'0',STR_PAD_LEFT)));
        return ['name'=>$s,'flags'=>$flags];
    }

    /* ---------------------- parse ---------------------- */
    public function read(): void {
        try {
            $this->phase = 'header';
            $this->seek(0);
            $this->tag = $this->readU32();
            if ($this->tag !== 0x9E2A83C1) throw new RuntimeException("Bad tag");
            $ver = $this->readU32();
            $this->fileVersionEpic = $ver & 0xFFFF;
            $this->fileVersionLicensee = ($ver>>16)&0xFFFF;

            $this->packageFlags = $this->readU32();
            $this->nameCount    = $this->readU32();
            $this->nameOffset   = $this->readU32();
            $this->exportCount  = $this->readU32();
            $this->exportOffset = $this->readU32();
            $this->importCount  = $this->readU32();
            $this->importOffset = $this->readU32();

            if ($this->fileVersionEpic >= 68) {
                $this->guid = $this->readGUID();
                $gc = $this->readU32();
                for($i=0;$i<$gc;$i++){
                    $e=$this->readU32(); $n=$this->readU32();
                    $this->generations[]=['ExportCount'=>$e,'NameCount'=>$n];
                }
            } else {
                $hc = $this->readU32(); $hoff = $this->readU32();
                $this->log("heritage count=$hc off=$hoff");
            }

            $this->phase = 'names';
            if ($this->nameCount>0 && $this->nameOffset>0){
                $this->seek($this->nameOffset);
                for($i=0;$i<$this->nameCount;$i++){ $this->log("name[$i] @0x".dechex($this->tell())); $this->names[]=$this->readNAME($this->fileVersionEpic); }
            }

            $this->phase = 'imports';
            if ($this->importCount>0 && $this->importOffset>0){
                $this->seek($this->importOffset);
                for($i=0;$i<$this->importCount;$i++){
                    $this->log("import[$i] @0x".dechex($this->tell()));
                    $this->imports[] = [
                        'ClassPackage'=>$this->readINDEX(),
                        'ClassName'=>$this->readINDEX(),
                        'PackageIndex'=>$this->readI32(),     // Import uses INT32 for PackageIndex
                        'ObjectName'=>$this->readINDEX(),
                    ];
                }
            }

            $this->phase = 'exports';
            if ($this->exportCount>0 && $this->exportOffset>0){
                $this->seek($this->exportOffset);
                for($i=0;$i<$this->exportCount;$i++){
                    $this->log("export[$i] @0x".dechex($this->tell()));
                    $classIdx=$this->readINDEX();
                    $superIdx=$this->readINDEX();
                    $outerIdx=$this->readINDEX();      // UE1/UE2: INDEX (CompactIndex)
                    $objName =$this->readINDEX();
                    $objFlags=$this->readU32();
                    $serialSize=$this->readINDEX();
                    $serialOff  = ($serialSize!==0) ? $this->readINDEX() : 0; // ⬅ INDEX
                    if($serialSize!==0){
                        $serialOff=$this->readINDEX(); // UE1/UE2: INDEX (CompactIndex)
                    }
                    $this->exports[]=[
                        'ClassIndex'=>$classIdx,
                        'SuperIndex'=>$superIdx,
                        'PackageIndex'=>$outerIdx,
                        'ObjectName'=>$objName,
                        'ObjectFlags'=>$objFlags,
                        'SerialSize'=>$serialSize,
                        'SerialOffset'=>$serialOff,
                    ];
                }
            }
        } catch (\Throwable $t) {
            throw new RuntimeException($t->getMessage() . $this->dumpTrace());
        }
    }

    /* ---------------------- property reader (bounded) ---------------------- */
    private function readFloat(): float { $u=$this->readU32(); $bin=pack('V',$u); $a=unpack('g',$bin); return $a[1]; }
    private function boundedRead(int $need, int $end): void {
        $remain = $end - $this->tell();
        if ($need > $remain) throw new RuntimeException("Property read needs $need bytes but only $remain remain (end=0x".dechex($end).")");
    }
    private function readPropertiesAt(int $offset, int $size): array {
        $saved = $this->tell();
        $this->phase = 'props';
        $props=[];
        $end = $offset + $size;
        try {
            $this->seek($offset);
            while ($this->tell() < $end) {
                $nameIdx = $this->readINDEX();
                $nm = $this->safeNameOf($nameIdx);
                if ($nm === 'None') break;

                $info = $this->readU8();
                $typeId   = $info & 0x0F;
                $sizeCode = ($info >> 4) & 0x07;
                $hasArray = ($info & 0x80) !== 0;

                $arrayIndex = null;
                if ($hasArray) { $arrayIndex = $this->readINDEX(); }

                $dataSize = 0;
                if ($sizeCode == 0) $dataSize = 1;
                elseif ($sizeCode == 1) $dataSize = 2;
                elseif ($sizeCode == 2) $dataSize = 4;
                elseif ($sizeCode == 3) $dataSize = 12;
                elseif ($sizeCode == 4) $dataSize = 16;
                elseif ($sizeCode == 5) $dataSize = 0;
                elseif ($sizeCode == 6) $dataSize = 8;
                else { $dataSize = $this->readINDEX(); }

                $typeName=''; $value=null;
                switch ($typeId) {
                    case 0: $typeName='Byte'; $this->boundedRead(1,$end); $value=ord($this->readBytes(1)); break;
                    case 1: $typeName='Int'; $this->boundedRead(4,$end); $value=$this->readI32(); break;
                    case 2: $typeName='Bool'; $typeName='Bool'; $value=(($info & 0x08)!==0); break;
                    case 3: $typeName='Float'; $this->boundedRead(4,$end); $value=$this->readFloat(); break;
                    case 4: $typeName='Object'; $this->boundedRead(4,$end); $value=$this->safeRefToString($this->readI32()); break;
                    case 5: $typeName='Name'; $value=$this->safeNameOf($this->readINDEX()); break;
                    case 6: $typeName='String'; if ($dataSize>0){ $this->boundedRead($dataSize,$end); $value=rtrim($this->readBytes($dataSize),"\x00"); } else $value=''; break;
                    case 7: $typeName='Class'; $this->boundedRead(4,$end); $value=$this->safeRefToString($this->readI32()); break;
                    case 8: $typeName='Array'; if ($dataSize>0){ $this->boundedRead($dataSize,$end); $this->seek($this->tell()+$dataSize); $value=$dataSize.' bytes'; } else $value='[]'; break;
                    case 9: $typeName='Struct';
                        $structName = $this->safeNameOf($this->readINDEX());
                        if ($structName==='Color' && $dataSize>=4){ $this->boundedRead(4,$end);
                            $b=$this->readBytes(4); $r=ord($b[0]);$g=ord($b[1]);$bl=ord($b[2]);$a=ord($b[3]);
                            if ($dataSize>4){ $this->boundedRead($dataSize-4,$end); $this->seek($this->tell()+$dataSize-4); }
                            $value="Color(R=$r,G=$g,B=$bl,A=$a)";
                        } else {
                            if ($dataSize>0){ $this->boundedRead($dataSize,$end); $this->seek($this->tell()+$dataSize); }
                            $value=$structName.' ('.$dataSize.' bytes)';
                        }
                        break;
                    case 10: $typeName='Vector'; $this->boundedRead(12,$end); $x=$this->readFloat(); $y=$this->readFloat(); $z=$this->readFloat(); $value="($x,$y,$z)"; break;
                    case 11: $typeName='Rotator'; $this->boundedRead(12,$end); $p=$this->readI32(); $y=$this->readI32(); $r=$this->readI32(); $value="($p,$y,$r)"; break;
                    default:
                        $typeName='Unknown('.$typeId.')';
                        if ($dataSize>0){ $this->boundedRead($dataSize,$end); $this->seek($this->tell()+$dataSize); $value=$dataSize.' bytes'; }
                }
                $props[]=['name'=>$nm,'type'=>$typeName,'arrayIndex'=>$arrayIndex,'value'=>$value];
            }
        } catch (\Throwable $e) {
            $props[]=['name'=>'<PARSE_ERROR>','type'=>'','arrayIndex'=>null,'value'=>$e->getMessage()];
        } finally {
            $this->seek($saved);
            $this->phase = 'exports';
        }
        return $props;
    }

    /* ---------------------- robust utilities ---------------------- */
    // Safe name access (never throws/never notices)
    private function safeNameOf($idx): string {
        if (!is_int($idx)) return "#(bad-ref)";
        return ($idx >= 0 && $idx < count($this->names)) ? $this->names[$idx]['name'] : "#$idx";
    }
    // Safe object reference pretty string
    private function safeRefToString($ref): string {
        if (!is_int($ref)) return "?(bad-ref)";
        if ($ref === 0) return "None";
        if ($ref < 0)  return "Import[".(-$ref-1)."]";
        return "Export[".($ref-1)."]";
    }
    // Export class as text (safe resolution)
    private function exportClass(int $i): string {
        $e = $this->exports[$i] ?? null;
        if (!$e) return 'Class';
        $ci = $e['ClassIndex'] ?? 0;
        if ($ci < 0) { $imp = $this->imports[-$ci-1] ?? null; return $imp? $this->safeNameOf($imp['ClassName']??-1) : 'Class'; }
        if ($ci > 0) { $ex2 = $this->exports[$ci-1] ?? null; return $ex2? $this->safeNameOf($ex2['ObjectName']??-1) : 'Class'; }
        return 'Class';
    }
    // Top-most outer (safe)
    private function groupOf(int $i): string {
        $e = $this->exports[$i] ?? null;
        if (!$e) return 'None';
        $ref = $e['PackageIndex'] ?? 0;
        $last='None'; $seen=0;
        while ($ref !== 0 && $seen < 64) {
            if ($ref > 0) { $idx=$ref-1; $ex=$this->exports[$idx] ?? null; if(!$ex) break; $last=$this->safeNameOf($ex['ObjectName']??-1); $ref=$ex['PackageIndex']??0; }
            else { $imp = $this->imports[-$ref-1] ?? null; $last = $imp? $this->safeNameOf($imp['ObjectName']??-1) : $last; break; }
            $seen++;
        }
        return $last;
    }
    private function hexDump(int $offset, int $length, int $width=16, int $max=64): string {
        $length = max(0, min($length, $max, $this->filesize - $offset));
        $saved = $this->tell(); $this->seek($offset);
        $buf = ($length>0)? $this->readBytes($length):'';
        $this->seek($saved);
        $out=[];
        for ($i=0; $i<$length; $i+=$width){
            $chunk = substr($buf,$i,$width);
            $hex = strtoupper(implode(' ', str_split(bin2hex($chunk),2)));
            $asc = preg_replace('/[^ -~]/','.', $chunk);
            $out[] = sprintf("%08X  %-*s  %s", $offset+$i, $width*3-1, $hex, $asc);
        }
        return implode("\n", $out);
    }
    private function fmtFlags(int $flags, array $map): string {
        $bits=[]; foreach($map as $bit=>$name){ if(($flags & $bit)!==0) $bits[]=$name; }
        return "0x".strtoupper(str_pad(dechex($flags),8,'0',STR_PAD_LEFT))." [".implode(',', $bits)."]";
    }

    /* ---------------------- output ---------------------- */
    public function dumpAll(): string {
        $lines = [];
        $lines[] = "Unreal File found. (0x".strtoupper(dechex($this->tag)).")";
        $lines[] = "";
        $lines[] = "Version: ".$this->fileVersionEpic;
        $lines[] = "License mode: ".$this->fileVersionLicensee;
        $lines[] = "Package flags: ".$this->fmtFlags($this->packageFlags,$this->PKG_FLAGS);
        $lines[] = "Name count: ".$this->nameCount;
        $lines[] = "Name offset: ".$this->nameOffset;
        $lines[] = "Export count: ".$this->exportCount;
        $lines[] = "Export offset: ".$this->exportOffset;
        $lines[] = "Import count: ".$this->importCount;
        $lines[] = "Import offset: ".$this->importOffset;
        $lines[] = "";
        if ($this->guid){ $lines[]="GUID: ".$this->guid; $lines[]=""; }
        if ($this->generations){ $lines[]="Generation count: ".count($this->generations); foreach($this->generations as $i=>$g){$lines[]="Generation[$i] Exports={$g['ExportCount']} Names={$g['NameCount']}";} $lines[]=""; }

        // Names
        $lines[] = "*******Name Table ({$this->nameCount}:{$this->nameOffset})";
        foreach ($this->names as $i=>$n){
            $lines[] = sprintf("%d Name Text: %s (%d) - Flags=%s", $i+1, $n['name'], strlen($n['name']), $this->fmtFlags($n['flags'],$this->OBJ_FLAGS));
        }
        $lines[] = "";

        // Exports
        $lines[] = "*******Export Table ({$this->exportCount}:{$this->exportOffset})";
        foreach ($this->exports as $i=>$ex){
            $group=$this->groupOf($i);
            $name =$this->safeNameOf($ex['ObjectName']??-1);
            $class=$this->exportClass($i);
            $super=$ex['SuperIndex']??0;
            $size =$ex['SerialSize']??0;
            $off  =isset($ex['SerialOffset'])? sprintf("0x%08X",(int)$ex['SerialOffset']) : "0x00000000";
            $flagsTxt=$this->fmtFlags((int)($ex['ObjectFlags']??0),$this->OBJ_FLAGS);
            $lines[] = sprintf("%-14s %-20s %-10s %3d (0x%02X)  %3d (0x%02X)  %5d %s  %s",
                $group, $name, $class, (int)($ex['ClassIndex']??0), ((int)($ex['ClassIndex']??0)&0xFF),
                (int)$super, ((int)$super & 0xFF), (int)$size, $off, $flagsTxt);
        }
        $lines[] = "";

        // Properties + Hex
        $lines[] = "*******Object Data (Properties + Payloads)*******";
        foreach ($this->exports as $i=>$ex){
            $size = (int)($ex['SerialSize']??0);
            $offs = (int)($ex['SerialOffset']??0);
            if ($size>0){
                $lines[] = "";
                $lines[] = $this->safeNameOf($ex['ObjectName']??-1)." @ ".$this->groupOf($i);
                try {
                    $props = $this->readPropertiesAt($offs, $size);
                    if ($props){
                        foreach ($props as $p){
                            $ai = isset($p['arrayIndex']) && $p['arrayIndex']!==null ? ("[".$p['arrayIndex']."]") : "";
                            $val = $p['value'];
                            if (is_bool($val)) $val = $val ? 'true' : 'false';
                            if ($val===null) $val='';
                            $lines[] = "  - {$p['name']}{$ai} = {$val} (".$p['type'].")";
                        }
                    } else {
                        $lines[] = "  (no tagged properties)";
                    }
                } catch (\Throwable $e) {
                    $lines[] = "  <PROPERTY_ERROR> ".$e->getMessage();
                }
                $lines[] = $this->hexDump($offs, $size);
            }
        }

        $text = implode("\n", $lines) . $this->dumpTrace();
        return nl2br(htmlentities($text));
    }
}

/* ------------------------------- Runner ------------------------------- */
$path = "test.utx";
try {
    $r = new UE2PackageReader($path);
    $r->read();
    echo $r->dumpAll();
} catch (Throwable $t) {
    echo "ERROR: ".htmlentities($t->getMessage());
}
