<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides `new` parser/viewer support for tunreal package, including `UE_LZX`.
 * Why: It exists for `new` package-format inspection, experiments, or parser development separate from the main
 *      catalog UI.
 * Role: Legacy/reference parser tooling unless another file explicitly requires it.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
interface IPackageReader {
    public function load():         void;
    public function getHeader():    array;
    public function getNames():     array;
    public function getImports():   array;
    public function getExports():   array;
    public function getDepends():   array;
    public function getVersion():   int;
    public function isCompressed(): bool;
    public function nameText(int $nameIndex): string;          // FName index → string
    public function importName(int $import1BasedNeg): string;  // -N import
    public function exportName(int $export1BasedPos): string;  // +N export
}
//===========================================================================================
final class TReader {
    private string $buf;
    private int    $len;
    private int    $pos = 0;
    private int    $lo  = 0;
    private int    $hi;

    public function __construct(string $bytes) {
        $this->buf = $bytes;
        $this->len = strlen($bytes);
        $this->lo  = 0;
        $this->hi  = $this->len;
        $this->pos = 0; // BOF
    }
	public function fnamePair(): array {
		// UE3: FName in tables = int32 NameIndex, int32 Number
		$idx = $this->i32();
		$num = $this->i32();
		return [$idx, $num];
	}
	/** @var null|callable(int $off, string $what, int $size, mixed $val):void */
	private $tracer = null;
	/** enable structured tracing to a callback */
	public function setTracer(?callable $fn): void { $this->tracer = $fn; }
	private function trace(string $what, int $size, $val, int $offBefore): void {
		if ($this->tracer) { ($this->tracer)($offBefore, $what, $size, $val); }
	}
	public function bytes(int $count): string {
		if ($count < 0) 
			throw new \InvalidArgumentException("bytes($count)");
		
		if ($this->pos + $count > $this->hi) {
			$have = $this->hi - $this->pos;
			throw new \OutOfBoundsException("bytes overrun: need $count, have $have (bounds [{$this->lo},{$this->hi}), pos {$this->pos})");
		}
		
		$s = substr($this->buf, $this->pos, $count);
		$this->pos += $count;
		
		return $s;
	}
	/*
	
	public function bytes(int $n): string {
		if ($n < 0) {
			throw new \InvalidArgumentException("UEReader::bytes negative length: {$n}");
		}
		
		if ($n === 0) 
			return '';

		$rem = $this->remaining();
		
		if ($n > $rem) {
			throw new \OutOfBoundsException(sprintf(
				"UEReader::bytes overrun: want %d, remaining %d (bounds [%d,%d), pos %d)",
				$n, $rem, $this->lo, $this->hi, $this->pos
			));
		}

		// Assuming your buf is stored in $this->buf (string). Adjust if you use a stream.
		$chunk      = substr($this->buf, $this->pos, $n);
		$this->pos += $n;
		
		return $chunk;
	}
	*/
	
	public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
	public function i32(): int { $u = $this->u32();	return ($u & 0x80000000) ? $u - 0x100000000 : $u; }
	// FString = signed i32 length;
	//   > 0 : ANSI bytes including trailing NUL
	//   < 0 : UTF-16LE code units including trailing NUL
	//   ==0 : empty
	// In both cases: read the full payload, then trim the trailing NUL.
	public function fstring(): string
	{
		$len = $this->i32();

		if ($len === 0) {
			return '';
		}

		if ($len > 0) {
			// ANSI: exactly $len bytes, includes trailing NUL
			$raw = $this->bytes($len);
			// Trim ONE trailing NUL if present
			if ($len > 0 && $raw !== '' && $raw[strlen($raw) - 1] === "\x00") {
				$raw = substr($raw, 0, -1);
			}
			// Decode as UTF-8 best-effort (ANSI bytes are typically ASCII)
			// No re-encoding if you want raw bytes — but most UE tools present UTF-8.
			return @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
		}

		// UTF-16LE: -$len code units, includes trailing NUL (2 bytes)
		$cu   = -$len; // number of 16-bit code units
		$need = $cu * 2;
		$raw  = $this->bytes($need);
		// Trim ONE trailing UTF-16LE NUL (0x00 0x00) if present
		if ($need >= 2 && substr($raw, -2) === "\x00\x00") {
			$raw = substr($raw, 0, -2);
		}
		// Decode UTF-16LE → UTF-8
		$out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
		
		return ($out === false) ? '' : $out;
	}
	// TReader:peekFStringOk (helper: does this look like a valid FString here?)
	public function peekFStringOk(): bool {
		if ($this->remaining() < 4) 
			return false;
		
		$orig = $this->tell();
		$len  = $this->i32();
		// Basic sanity: UE names rarely exceed a few hundred chars
		$ok = false;
		
		if ($len > 0 && $len <= 512) {
			$ok = ($this->remaining() >= $len);
		} elseif ($len < 0 && -$len <= 512) {
			// Unicode path (2 bytes per char)
			$need = (-$len) * 2;
			$ok = ($this->remaining() >= $need);
		}
		
		$this->seek($orig);
		
		return $ok;
	}
	public function seek(int $pos): void { $this->pos = max($this->lo, min($pos, $this->hi)); }
	public function tell(): int { return $this->pos; }
	public function length(): int { return $this->hi - $this->lo; }

	public function peekBytes(int $n): string {
		if ($this->pos + $n > $this->hi) {
			$remain = $this->hi - $this->pos;
			throw new \OutOfBoundsException("peek overrun: need $n, have $remain");
		}
		return substr($this->buf, $this->pos, $n);
	}
	public function peekU32(): int {
		if ($this->pos + 4 > $this->hi) throw new \OutOfBoundsException("peekU32 past end");
		$v = unpack('V', substr($this->buf, $this->pos, 4))[1];
		return (int)$v;
	}
	public function remaining(): int { return $this->hi - $this->pos; }
	public function setBounds(int $start, int $end): void {
		if ($start < 0 || $end > $this->len || $start > $end)
			throw new \OutOfBoundsException("setBounds($start,$end) invalid (len {$this->len})");
		$this->lo = $start; $this->hi = $end; $this->pos = $start;
	}
	public function u8(): int  { return ord($this->bytes(1)); }
	public function i8(): int  { $v=$this->u8(); return $v>=0x80? $v-0x100 : $v; }
	public function u16(): int { $v = unpack('v', $this->bytes(2))[1]; return (int)$v; }
	public function i16(): int { $u=$this->u16(); return ($u&0x8000)? $u-0x10000 : $u; }
	public function i64(): int { $lo=$this->u32(); $hi=$this->u32(); $u=($hi<<32)|$lo; if ($hi & 0x80000000) $u -= 0x10000000000000000; return $u; }
	public function u64(): int { $lo=$this->u32(); $hi = $this->u32(); return ($hi << 32) | $lo; }
    public function fork(): TReader {
        $r = new TReader($this->buf);
        $r->lo = $this->lo; $r->hi = $this->hi; $r->pos = $this->pos;
		
        return $r;
    }
    public function physSlice(int $start, int $size): string {
        if ($start < 0 || $size < 0 || ($start + $size) > $this->len)
            throw new \OutOfBoundsException("physSlice($start,$size) out of file len {$this->len}");
		
        return substr($this->buf, $start, $size);
    }
}
//===========================================================================================
final class TPackageReader {
    public static function open(string $path): AbstractUE {
        if (!is_file($path)) 
			throw new \InvalidArgumentException("File not found: $path");
		
        $bytes = file_get_contents($path);
		
        if ($bytes === false) 
			throw new \RuntimeException("Failed to read: $path");

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
		
        if (in_array($ext, ['ut3','upk','xxx'], true)) {
            $pkg = new TUE3($path, $bytes);
            $pkg->load();
			
            return $pkg;
        }
		
        if (in_array($ext, ['uasset','umap','pak'], true)) {
            $pkg = new TUE4($path, $bytes);
            $pkg->load();
			
            return $pkg;
        }

        // throwaway probe (NEVER reused)
        $probe = new TReader($bytes);
        $tag   = $probe->u32();
        $ver   = $probe->i32();
        $lic   = $probe->i32();

        if ($ver >= 800)   { $pkg = new TUE4($path, $bytes); }
        elseif ($ver >= 334){ $pkg = new TUE3($path, $bytes); }
        elseif ($ver >= 120){ $pkg = new TUE2($path, $bytes); }
        else                { $pkg = new TUE1($path, $bytes); }

        $pkg->load();
		
        return $pkg;
    }
}
//===========================================================================================
abstract class AbstractUE {
    protected TReader $R;
    protected string  $path             = '';
    protected string  $bytes            = '';
    protected array   $header           = [];
    protected array   $names            = [];
    protected array   $imports          = [];
    protected array   $exports          = [];
    protected array   $depends          = [];
    protected bool    $compressed       = false;
    protected int     $compressionFlags = 0;
    protected array   $chunks           = [];

    // ctor stores bytes only. DO NOT call load() here.
    public function __construct(string $path, string $bytes) {
        $this->path  = $path;
        $this->bytes = $bytes;
    }	
	protected function readerForRegion(int $start, int $end): TReader {
		if ($end <= $start) $end = $start;
		$r = $this->R->fork();          // PHYSICAL file slice (tables use physical offsets)
		$r->setBounds($start, $end);
		
		return $r;
	}
	protected function nextTableStart(int $start): int {
		$cand = [];
		
		foreach (['importOffset','exportOffset','dependsOffset'] as $k) {
			if (isset($this->header[$k]) && is_int($this->header[$k]) && $this->header[$k] > $start) {
				$cand[] = (int)$this->header[$k];
			}
		}
		
		if ($cand) 
			return min($cand);

		if ($this->compressed && !empty($this->chunks)) {
			$maxEnd = 0;
			
			foreach ($this->chunks as $ch) {
				$maxEnd = max($maxEnd, (int)$ch['uOff'] + (int)$ch['uSize']); // logical fallback
			}
			
			if ($maxEnd > $start) 
				return $maxEnd;
		}
		
		return $this->R->length(); // physical fallback
	}
    // Called by factory after constructing the concrete class.
    public function load(): void {
        // Brand-new reader at BOF, full bounds.
        $this->R = new TReader($this->bytes);
        $this->R->seek(0);

        if ($this->R->remaining() < 16) {
            throw new \OutOfBoundsException("Package too small for header");
        }

        $this->readHeader();
        $this->readNameTable();
        $this->readImportTable();
        $this->readExportTable();
    }

    abstract protected function readHeader(): void;
    abstract protected function readNameTable(): void;
    abstract protected function readImportTable(): void;
    abstract protected function readExportTable(): void;	
	public function getHeader(): array { return $this->header; }
	public function getNames(): array { return $this->names; }
	public function getImports(): array { return $this->imports; }
	public function getExports(): array { return $this->exports; }
	public function getDepends(): array { return $this->depends; }
	public function getVersion(): int { return (int)($this->header['version'] ?? 0); }
	public function isCompressed(): bool { return (bool)$this->compressed; }
	public function nameText(int $nameIndex): string {
		// FName index; UE tables are 0-based in the file, and your code stores arrays 0..N-1
		if ($nameIndex < 0 || $nameIndex >= count($this->names)) 
			return '';
		
		$n = $this->names[$nameIndex] ?? null;
		if (!$n) 
			return '';
		// UE2/UE3 entries are ['name'=>..., 'flags'=>...]
		return is_array($n) && array_key_exists('name',$n) ? (string)$n['name'] : (string)$n;
	}
	public function importName(int $import1BasedNeg): string {
		// C++: negative indices refer to imports; -1 => imports[0]
		if ($import1BasedNeg >= 0) 
			return '';
		
		$idx = (-$import1BasedNeg) - 1;
		
		if ($idx < 0 || $idx >= count($this->imports)) 
			return '';
		
		return $this->resolveObjectPath(false, $idx);
	}
	public function exportName(int $export1BasedPos): string {
		// C++: positive indices refer to exports; +1 => exports[0]
		if ($export1BasedPos <= 0) 
			return '';
		
		$idx = $export1BasedPos - 1;
		
		if ($idx < 0 || $idx >= count($this->exports)) 
			return '';
		
		return $this->resolveObjectPath(true, $idx);
	}
	// ---- private helper to build "Outer.Outer.Object" using import/export chains ----
	private function resolveObjectPath(bool $isExport, int $index): string {
		$parts = [];
		$seen  = 0;
		
		while ($seen < 256) { // safety loop guard
			$seen++;
			
			if ($isExport) {
				$e = $this->exports[$index] ?? null;
				
				if (!$e) 
					break;
				
				$parts[] = $this->nameText((int)$e['objectName']);
				$outer   = (int)$e['outerIndex'];
				
				if ($outer == 0) 
					break;		
				
				if ($outer > 0) {                // export outer (+N)
					$index    = $outer - 1;
					$isExport = true;
					continue;
				} else {                          // import outer (-N)
					$index    = (-$outer) - 1;
					$isExport = false;
					continue;
				}
			} else {
				$im = $this->imports[$index] ?? null;
				
				if (!$im) 
					break;
				
				$parts[] = $this->nameText((int)$im['objectName']);
				$outer   = (int)$im['outerIndex'];
				
				if ($outer == 0) 
					break;
				
				if ($outer > 0) {                // export outer (+N)
					$index    = $outer - 1;
					$isExport = true;
					continue;
				} else {                          // import outer (-N)
					$index    = (-$outer) - 1;
					$isExport = false;
					continue;
				}
			}
		}
		
		$parts = array_reverse($parts);
		
		return implode('.', array_filter($parts, fn($s)=>$s !== ''));
	}
}
//===========================================================================================
final class TUE1 extends AbstractUE {
    protected function readHeader(): void {
        $R = $this->R;
        $this->header['tag']             = $R->u32();
        $this->header['version']         = $R->i32();
        $this->header['licenseeVersion'] = $R->i32();
        $this->header['packageFlags']    = $R->u32();
        $this->header['nameCount']       = $R->i32();
        $this->header['nameOffset']      = $R->i32();
        $this->header['exportCount']     = $R->i32();
        $this->header['exportOffset']    = $R->i32();
        $this->header['importCount']     = $R->i32();
        $this->header['importOffset']    = $R->i32();
        $this->header['dependsOffset']   = $R->i32();
        $this->header['guid']            = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
        $genCount                        = $R->i32();
        $gens                            = [];
		$this->compressed                = false;
		$this->header['guid']            = $this->compressed;
		
        for ($i=0;$i<$genCount;$i++){
            $gens[]=['exportCount'=>$R->i32(),'nameCount'=>$R->i32()];
        }
		
        $this->header['generations']=$gens;
    }
	
// UE3 Name table: if stored-compressed, NameOffset is LOGICAL.
// Build a logical (decompressed) view over [NameOffset, nextTable) and read from it.
protected function readNameTable(): void
{
    $nameCount  = (int)($this->header['nameCount']  ?? 0);
    $nameOffset = (int)($this->header['nameOffset'] ?? 0);

    if ($nameCount <= 0 || $nameOffset < 0) {
        $this->names = [];
        return;
    }

    // Use the shared helper — it already falls back to the logical end
    // computed from the chunk table if there isn't a later table.
    $end = $this->nextTableStart($nameOffset);

    // Choose reader: logical (decompressed) if compressed; physical if not
    $R = ($this->compressed && !empty($this->chunks))
        ? $this->readerForLogical($nameOffset, $end)   // pos 0 == logical NameOffset
        : $this->readerForRegion($nameOffset, $end);   // physical slice

    $this->names = [];
    for ($i = 0; $i < $nameCount; $i++) {
        $name  = $R->fstring();   // FString: signed i32; >0 ANSI incl NUL; <0 UTF-16LE incl NUL; trim NUL
        $flags = $R->u32();       // trailing int32 flags
        $this->names[] = ['name' => $name, 'flags' => $flags];
    }
}

	
    protected function readImportTable(): void {
        $this->imports = [];
		$R             = $this->R;
        $R->seek($this->header['importOffset']);
        
		
        for ($i=0;$i<$this->header['importCount'];$i++) {
            $classPackage    = $R->i32();
            $className       = $R->i32();
            $outerIndex      = $R->i32();  // signed
            $objectName      = $R->i32();
            $this->imports[] = compact('classPackage','className','outerIndex','objectName');
        }
    }
	
    protected function readExportTable(): void {
        $this->exports = [];
		$R             = $this->R;
        $R->seek($this->header['exportOffset']);
        //$ver           = (int)$this->header['version'];
		
        for ($i=0;$i<$this->header['exportCount'];$i++) {
            $classIndex      = $R->i32();
            $superIndex      = $R->i32();
            $outerIndex      = $R->i32();
            $objectName      = $R->i32();
            $objectFlags     = $R->u32();
            $serialSize      = $R->i32();
            $serialOffset    = $serialSize>0 ? $R->i32() : null;
            $this->exports[] = [
                'classIndex'=>$classIndex,'superIndex'=>$superIndex,'outerIndex'=>$outerIndex,
                'objectName'=>$objectName,'objectFlags'=>$objectFlags,
                'serialSize'=>$serialSize,'serialOffset'=>$serialOffset
            ];
        }
    }
}
//===========================================================================================
final class TUE2 extends AbstractUE {
    protected function readHeader(): void {
        $R                               = $this->R;
        $this->header['tag']             = $R->u32();
        $this->header['version']         = $R->i32();
        $this->header['licenseeVersion'] = $R->i32();
        $this->header['packageFlags']    = $R->u32();
        $this->header['nameCount']       = $R->i32();
        $this->header['nameOffset']      = $R->i32();
        $this->header['exportCount']     = $R->i32();
        $this->header['exportOffset']    = $R->i32();
        $this->header['importCount']     = $R->i32();
        $this->header['importOffset']    = $R->i32();
        $this->header['dependsOffset']   = $R->i32();
        $this->header['guid']            = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
        $this->header['genCount']        = $R->i32();
        $gens                            = [];
		
        for ($i=0;$i<$this->header['genCount'] ;$i++){
            $gens[]=['exportCount'=>$R->i32(),'nameCount'=>$R->i32()];
        }
		
        $this->header['generations'] = $gens;
        $this->compressed = false; // UE2 package-level compression is rare; handle per title if needed
		$this->header['compressed'] = $this->compressed;
    }
    protected function readNameTable(): void {
        $this->names = [];
		$R           = $this->R;
        $R->seek($this->header['nameOffset']);        
		
        for ($i=0;$i<$this->header['nameCount'];$i++) {
            $name          = $R->fstring();
            $flags         = $R->u32();
            $this->names[] = ['name'=>$name,'flags'=>$flags];
        }
    }
    protected function readImportTable(): void {
        $this->imports = [];
		$R             = $this->R;
        $R->seek($this->header['importOffset']);
        
		
        for ($i=0;$i<$this->header['importCount'];$i++) {
            $classPackage    = $R->i32();
            $className       = $R->i32();
            $outerIndex      = $R->i32();
            $objectName      = $R->i32();
            $this->imports[] = compact('classPackage','className','outerIndex','objectName');
        }
    }
    protected function readExportTable(): void {
        $this->exports = [];
		$R             = $this->R;
        $R->seek($this->header['exportOffset']);        
        //$ver=(int)$this->header['version'];
		
        for ($i=0;$i<$this->header['exportCount'];$i++) {
            $classIndex      = $R->i32();
            $superIndex      = $R->i32();
            $outerIndex      = $R->i32();
            $objectName      = $R->i32();
            // UE2 often carries 64-bit flags split into lo/hi; combine
            $flagsLo         = $R->u32();
            $flagsHi         = $R->u32();
            $objectFlags     = ($flagsHi << 32) | $flagsLo;
            $serialSize      = $R->i32();
            $serialOffset    = $serialSize>0 ? $R->i32() : null;
            $this->exports[] = [
                'classIndex'=>$classIndex,'superIndex'=>$superIndex,'outerIndex'=>$outerIndex,
                'objectName'=>$objectName,'objectFlags'=>$objectFlags,
                'serialSize'=>$serialSize,'serialOffset'=>$serialOffset
            ];
        }
    }
}
//===========================================================================================
class TUE3 extends AbstractUE {
    protected bool  $compressed       = false;
    protected int   $compressionFlags = 0;
    protected array $chunks           = [];
	public array $chunkMeta           = [];

	protected function readHeader(): void {
		$R                      = $this->R;
		$R->seek(0);
		$this->header           = [];
		$this->chunks           = [];
		$this->compressed       = false;
		$this->compressionFlags = 0;
		$tag                    = $R->u32();// u32 Tag = 0x9E2A83C1
		$this->header['tag']    = $tag;
		$gens                   = [];
		
		if ($tag !== 0x9E2A83C1) {
			throw new \RuntimeException(sprintf("UE3 header: bad tag 0x%08X", $tag));
		}    

		$this->header['version']         = $R->u16();
		$this->header['licenseeVersion'] = $R->u16();
		$this->header['headerSize']      = $R->u32();
		$this->header['folderName']      = $R->fstring();
		$this->header['packageFlags']    = $R->u32();
		$this->header['nameCount']       = $R->u32();
		$this->header['nameOffset']      = $R->u32();
		$this->header['exportCount']     = $R->u32();
		$this->header['exportOffset']    = $R->u32();
		$this->header['importCount']     = $R->u32();
		$this->header['importOffset']    = $R->u32();

		if ($this->header['version']  >= 415) {
			$this->header['dependsOffset'] = $R->u32();
		} else {
			$this->header['dependsOffset'] = 0; // if not set, no default
		}

		$this->header['guid']     = [ $R->u32(), $R->u32(), $R->u32(), $R->u32() ];
		$this->header['genCount'] = $R->u32();
		
		if ($this->header['genCount'] > 4096) {
			throw new \RuntimeException("UE3 header: invalid generation count $genCount");
		}		
		
		for ($i = 0; $i < $this->header['genCount']; $i++) {
			$exp_i  = $R->u32();
			$nam_i  = $R->u32();
			$net_i  = ($this->header['version']  >= 334) ? $R->u32() : 0;
			$gens[] = ['exportCount'=>$exp_i, 'nameCount'=>$nam_i, 'netObjectCount'=>$net_i];
		}
		
		$this->header['generations']      = $gens;	
		$this->header['engineVersion']    = $R->u32();
		$this->header['cookerVersion']    = $R->u32();		
		$this->compressionFlags           = (int)$R->u32();
		$this->compressed                 = ($this->compressionFlags !== 0);		
		$this->header['compressionFlags'] = $this->compressionFlags;
		$this->header['compressed']       = $this->compressed;
			
		if ($this->header['compressed'] !== 0) {
			// ChunkCount
			$cc                           = $R->u32();
			$this->header['chunkCount']   = $cc;
			
			if ($cc < 0 || $cc > 1_000_000) {
				throw new \RuntimeException("UE3 header: unreasonable ChunkCount ".$cc);
			}

			for ($i = 0; $i < $cc; $i++) {
				// UE3: [uOff][uSize][cOff][cSize] – all u32 LE
				$uOff  = $R->u32();
				$uSize = $R->u32();
				$cOff  = $R->u32();
				$cSize = $R->u32();

				$this->chunks[] = [
					'uOff'  => (int)$uOff,
					'uSize' => (int)$uSize,
					'cOff'  => (int)$cOff,
					'cSize' => (int)$cSize,
				];
			}
		}
		
		$this->header['chunks'] = $this->chunks;		
	}

	protected function readNameTable(): void
	{
		$nameCount  = (int)($this->header['nameCount']  ?? 0);
		$nameOffset = (int)($this->header['nameOffset'] ?? 0);

		if ($nameCount <= 0 || $nameOffset < 0) { $this->names = []; return; }

		// Use a full logical stream for UE3 compressed packages, just like the old reader.
		$R = ($this->compressed && !empty($this->chunks)) ? $this->makeFullLogicalReader() : $this->R;
		// Seek to the logical nameOffset and read exactly nameCount entries.
		$R->seek($nameOffset);
		$this->names = [];
		$ver   = (int)($this->header['version'] ?? 0);
		
		for ($i = 0; $i < $nameCount; $i++) {
			$name          = $R->fstring();   // signed i32 length: >0 ANSI+NUL, <0 UTF16LE+NUL, trim NUL
			//$flags         = $R->u32();
			$flags         = $R->u64();
			$this->names[] = ['name' => $name, 'flags' => $flags];
		}
	}

// UE3 Import table: offsets are LOGICAL when compressed.
// FObjectImport on disk: ClassPackage(FName), ClassName(FName), OuterIndex(i32), ObjectName(FName)
protected function readImportTable(): void
{
    $importCount  = (int)($this->header['importCount']  ?? 0);
    $importOffset = (int)($this->header['importOffset'] ?? 0);
    if ($importCount <= 0 || $importOffset < 0) { $this->imports = []; return; }

    // Use full logical stream for compressed packages
    $R = ($this->compressed && !empty($this->chunks)) ? $this->makeFullLogicalReader() : $this->R;
    $R->seek($importOffset);

    $exportCount = (int)($this->header['exportCount'] ?? 0);

    // Helper to validate an FPackageIndex range
    $isPkgIndex = function (int $idx) use ($importCount, $exportCount): bool {
        if ($idx === 0) return true;                      // null is allowed
        if ($idx < 0)  return (-$idx) >= 1 && (-$idx) <= $importCount;
        return $idx >= 1 && $idx <= $exportCount;
    };

    $this->imports = [];
    for ($i = 0; $i < $importCount; $i++) {
        // ClassPackage (FName)
        $classPkgIdx = $R->i32();
        $classPkgNum = $R->i32();

        // ClassName (FName)
        $classNameIdx = $R->i32();
        $classNameNum = $R->i32();

        // OuterIndex (FPackageIndex)
        $outerIdx = $R->i32();
        if (!$isPkgIndex($outerIdx)) {
            // Don’t crash; keep it but you may want to log/warn
            // (Some packages have -1 or temporary placeholders.)
        }

        // ObjectName (FName)
        $objNameIdx = $R->i32();
        $objNameNum = $R->i32();

        $this->imports[] = [
            'classPackageIndex' => $classPkgIdx,
            'classPackageNumber'=> $classPkgNum,
            'classNameIndex'    => $classNameIdx,
            'classNameNumber'   => $classNameNum,
            'outer'             => $outerIdx,
            'objectNameIndex'   => $objNameIdx,
            'objectNameNumber'  => $objNameNum,
        ];
    }
}

protected function readExportTable(): void
{
    $count        = (int)($this->header['exportCount']  ?? 0);
    $exportOffset = (int)($this->header['exportOffset'] ?? 0);
    if ($count <= 0 || $exportOffset < 0) { $this->exports = []; return; }

    // Use decompressed logical reader for compressed pkgs
    $R = ($this->compressed && !empty($this->chunks)) ? $this->makeFullLogicalReader() : $this->R;
    $R->seek($exportOffset);

    // Package version gates (don’t rename keys)
    $arVer     = (int)($this->header['pkgVersion'] ?? $this->header['packageVersion'] ?? $this->header['version'] ?? 0);
    $nameCount = (int)($this->header['nameCount'] ?? 0);

    $have = fn (int $n): bool => $R->remaining() >= $n;

    // Minimal header check at absolute position: just ensure we can read the core and nameIndex is plausible.
    $looksHeader = function (int $absPos) use ($R, $nameCount): bool {
        $save = $R->tell();
        $R->seek($absPos);
        if ($R->remaining() < 20) { $R->seek($save); return false; } // class, super, outer, nameIndex, nameNumber
        $R->i32(); $R->i32(); $R->i32();                    // skip class/super/outer
        $nameIdx  = $R->i32();
        $nameNum  = $R->i32();
        $R->seek($save);
        if ($nameIdx < 0) return false;
        if ($nameCount > 0 && $nameIdx >= $nameCount) return false;
        if ($nameNum < 0) return false; // UE3 name number is non-negative
        return true;
    };

    // Decide flags orientation on the very first row and cache it
    $flagsHiFirst = $this->ue3FlagsHiFirst ?? null;

    $this->exports = [];

    for ($i = 0; $i < $count; $i++) {
        if (!$have(5 * 4)) break;

        // Core (Serialize3): indices + FName pair
        $classIndex = $R->i32();
        $superIndex = $R->i32();
        $outerIndex = $R->i32();
        $nameIndex  = $R->i32();
        $nameNumber = $R->i32();

        // Archetype (>=220)
        $archetype = 0;
        if ($arVer >= 220) { if (!$have(4)) break; $archetype = $R->i32(); }

        // ObjectFlags: detect orientation on first row if needed, then stick with it
        $objectFlagsLo = 0; $objectFlagsHi = 0;
        if ($arVer >= 195) {
            if (!$have(8)) break;
            $a = $R->u32(); // read as A then B from stream
            $b = $R->u32();

            if ($flagsHiFirst === null) {
                // Heuristic: the "real" low half usually looks like a small bitmask (e.g., 0x00070004)
                // If exactly one half is small (< 0x0100_0000) and the other is 0, prefer that as Lo.
                $isSmallA = ($a <= 0x00FFFFFF);
                $isSmallB = ($b <= 0x00FFFFFF);
                if ($isSmallA && !$isSmallB) {
                    $flagsHiFirst = false; // A=Lo, B=Hi
                } elseif ($isSmallB && !$isSmallA) {
                    $flagsHiFirst = true;  // A=Hi, B=Lo
                } else {
                    // Default to UE3 canonical Lo->Hi
                    $flagsHiFirst = false;
                }
                $this->ue3FlagsHiFirst = $flagsHiFirst;
            }

            if ($flagsHiFirst) { $objectFlagsHi = $a; $objectFlagsLo = $b; }
            else               { $objectFlagsLo = $a; $objectFlagsHi = $b; }
        } else {
            if (!$have(4)) break;
            $objectFlagsLo = $R->u32();
            $objectFlagsHi = 0;
        }
        $objectFlags = (($objectFlagsHi & 0xFFFFFFFF) << 32) | ($objectFlagsLo & 0xFFFFFFFF);

        // Serial size (+ offset if size != 0 OR >=249)
        if (!$have(4)) break;
        $serialSize = $R->i32();

        $serialOffset = 0;
        if ($serialSize != 0 || $arVer >= 249) {
            if (!$have(4)) break;
            $serialOffset = $R->i32();
        }

        $rowBase = [
            'class'         => $classIndex,
            'super'         => $superIndex,
            'outer'         => $outerIndex,
            'nameIndex'     => $nameIndex,
            'nameNumber'    => $nameNumber,
            'archetype'     => $archetype,
            'objectFlagsLo' => $objectFlagsLo,
            'objectFlagsHi' => $objectFlagsHi,
            'objectFlags'   => $objectFlags,
            'serialSize'    => $serialSize,
            'serialOffset'  => $serialOffset,
        ];

        $afterCore = $R->tell();

        // Candidate readers
        $tryWithMapWithTail = function(int $pos, array $row) use ($R, $have, $looksHeader, $arVer) {
            $save = $R->tell();
            $R->seek($pos);

            if (!$have(4)) { $R->seek($save); return null; }
            $componentCount = $R->i32();
            if ($componentCount < 0 || $componentCount > 4096) { $R->seek($save); return null; }

            $componentMap = [];
            if (!$have($componentCount * 12)) { $R->seek($save); return null; }
            for ($c = 0; $c < $componentCount; $c++) {
                $ni = $R->i32(); $nn = $R->i32(); $val = $R->i32();
                $componentMap[] = ['nameIndex'=>$ni,'nameNumber'=>$nn,'value'=>$val];
            }

            $exportFlags = ($arVer >= 247) ? ($have(4) ? $R->u32() : 0) : 0;

            $netObjectCount = null; $guid = null; $u3unk6c = null;
            if ($arVer >= 322) {
                if (!$have(16*4 + 4*4 + (($arVer>=475)?4:0))) { $R->seek($save); return null; }
                $netObjectCount = [];
                for ($k = 0; $k < 16; $k++) $netObjectCount[] = $R->i32();
                $guid = [ $R->u32(), $R->u32(), $R->u32(), $R->u32() ];
                if ($arVer >= 475) $u3unk6c = $R->i32();
            }

            $next = $R->tell();
            if (!$looksHeader($next)) { $R->seek($save); return null; }

            $row = $row + [
                'componentCount' => $componentCount,
                'componentMap'   => $componentMap,
                'exportFlags'    => $exportFlags,
                'netObjectCount' => $netObjectCount,
                'guid'           => $guid,
                'u3unk6c'        => $u3unk6c,
            ];
            return [$row, $next];
        };

        $tryWithMapNoTail = function(int $pos, array $row) use ($R, $have, $looksHeader, $arVer) {
            $save = $R->tell();
            $R->seek($pos);

            if (!$have(4)) { $R->seek($save); return null; }
            $componentCount = $R->i32();
            if ($componentCount < 0 || $componentCount > 4096) { $R->seek($save); return null; }

            $componentMap = [];
            if (!$have($componentCount * 12)) { $R->seek($save); return null; }
            for ($c = 0; $c < $componentCount; $c++) {
                $ni = $R->i32(); $nn = $R->i32(); $val = $R->i32();
                $componentMap[] = ['nameIndex'=>$ni,'nameNumber'=>$nn,'value'=>$val];
            }

            $exportFlags = ($arVer >= 247) ? ($have(4) ? $R->u32() : 0) : 0;
            $next = $R->tell();
            if (!$looksHeader($next)) { $R->seek($save); return null; }

            $row = $row + [
                'componentCount' => $componentCount,
                'componentMap'   => $componentMap,
                'exportFlags'    => $exportFlags,
                'netObjectCount' => null,
                'guid'           => null,
                'u3unk6c'        => null,
            ];
            return [$row, $next];
        };

        $tryNoMapWithTail = function(int $pos, array $row) use ($R, $have, $looksHeader, $arVer) {
            $save = $R->tell();
            $R->seek($pos);

            $exportFlags = ($arVer >= 247) ? ($have(4) ? $R->u32() : 0) : 0;

            $netObjectCount = null; $guid = null; $u3unk6c = null;
            if ($arVer >= 322) {
                if (!$have(16*4 + 4*4 + (($arVer>=475)?4:0))) { $R->seek($save); return null; }
                $netObjectCount = [];
                for ($k = 0; $k < 16; $k++) $netObjectCount[] = $R->i32();
                $guid = [ $R->u32(), $R->u32(), $R->u32(), $R->u32() ];
                if ($arVer >= 475) $u3unk6c = $R->i32();
            }

            $next = $R->tell();
            if (!$looksHeader($next)) { $R->seek($save); return null; }

            $row = $row + [
                'componentCount' => 0,
                'componentMap'   => [],
                'exportFlags'    => $exportFlags,
                'netObjectCount' => $netObjectCount,
                'guid'           => $guid,
                'u3unk6c'        => $u3unk6c,
            ];
            return [$row, $next];
        };

        $tryNoMapNoTail = function(int $pos, array $row) use ($R, $have, $looksHeader, $arVer) {
            $save = $R->tell();
            $R->seek($pos);

            $exportFlags = ($arVer >= 247) ? ($have(4) ? $R->u32() : 0) : 0;

            $next = $R->tell();
            if (!$looksHeader($next)) { $R->seek($save); return null; }

            $row = $row + [
                'componentCount' => 0,
                'componentMap'   => [],
                'exportFlags'    => $exportFlags,
                'netObjectCount' => null,
                'guid'           => null,
                'u3unk6c'        => null,
            ];
            return [$row, $next];
        };

        $candidates = [];
        if ($arVer < 543) {
            // Try all four permutations and pick the one that lands closest
            foreach ([$tryWithMapWithTail, $tryWithMapNoTail, $tryNoMapWithTail, $tryNoMapNoTail] as $fn) {
                $res = $fn($afterCore, $rowBase);
                if ($res) $candidates[] = $res;
            }
        } else {
            foreach ([$tryNoMapWithTail, $tryNoMapNoTail] as $fn) {
                $res = $fn($afterCore, $rowBase);
                if ($res) $candidates[] = $res;
            }
        }

        if (!$candidates) {
            // As a last resort, assume Flags-only and move on to avoid infinite loop
            $R->seek($afterCore);
            $fallbackFlags = ($arVer >= 247 && $have(4)) ? $R->u32() : 0;
            $next = $R->tell();
            $this->exports[] = $rowBase + [
                'componentCount' => 0,
                'componentMap'   => [],
                'exportFlags'    => $fallbackFlags,
                'netObjectCount' => null,
                'guid'           => null,
                'u3unk6c'        => null,
            ];
            if ($next == $afterCore) break; // stuck
            continue;
        }

        // Choose candidate with the smallest jump (most conservative)
        usort($candidates, fn($A,$B) => $A[1] <=> $B[1]);
        [$finalRow, $nextPos] = $candidates[0];

        $this->exports[] = $finalRow;
        $R->seek($nextPos);
    }
}












// Deep UE3 header check at absolute $pos within logical window [$base,$end).
// Verifies 5-dword header shape AND that serialSize/serialOffset are sane.
protected function looksLikeExportHeaderDeepAt(TReader $R, int $pos, int $base, int $end): bool
{
    $nameCount   = (int)($this->header['nameCount']   ?? 0);
    $importCount = (int)($this->header['importCount'] ?? 0);
    $exportCount = (int)($this->header['exportCount'] ?? 0);
    $version     = (int)($this->header['version']     ?? 0);

    if ($pos < $base || $pos + 32 > $end) return false; // need at least header+flags+size

    $save = $R->tell();
    $R->seek($pos);

    $classIdx = $R->i32();
    $superIdx = $R->i32();
    $outerIdx = $R->i32();
    $nameIdx  = $R->i32();
    $nameNum  = $R->i32();

    $isPkgIndex = function (int $idx) use ($importCount, $exportCount): bool {
        if ($idx == 0) return true;
        if ($idx > 0)  { $e = $idx - 1; return ($e >= 0 && $e < $exportCount); } // export
        $i = (-$idx) - 1;            return ($i >= 0 && $i < $importCount);     // import
    };

    if (!$isPkgIndex($classIdx) || !$isPkgIndex($superIdx) || !$isPkgIndex($outerIdx)) { $R->seek($save); return false; }
    if (!($nameIdx >= 0 && $nameIdx < max(1, $nameCount)))                              { $R->seek($save); return false; }
    if ($nameNum < 0)                                                                   { $R->seek($save); return false; }

    if ($version >= 220) {
        if ($R->remaining() < 4) { $R->seek($save); return false; }
        $R->i32(); // archetype
    }

    if ($R->remaining() < 8) { $R->seek($save); return false; }
    $R->u32(); // flagsHi (order doesn't matter for probe)
    $R->u32(); // flagsLo

    if ($R->remaining() < 4) { $R->seek($save); return false; }
    $serialSize = $R->i32();
    if ($serialSize < 0)      { $R->seek($save); return false; }

    if ($serialSize != 0 || $version >= 249) {
        if ($R->remaining() < 4) { $R->seek($save); return false; }
        $serialOffset = $R->i32();
        if ($serialSize > 0) {
            if ($serialOffset <= 0)                 { $R->seek($save); return false; }
            if (($serialOffset & 3) != 0)           { $R->seek($save); return false; }
            if ($serialOffset + $serialSize > $end) { $R->seek($save); return false; }
        }
    }

    $R->seek($save);
    return true;
}





// Find the next deep-valid header at or after $fromPos, scanning up to $maxScan bytes.
protected function findNextHeaderDeep(TReader $R, int $fromPos, int $base, int $end, int $maxScan = 64): ?int
{
    if ($this->looksLikeExportHeaderDeepAt($R, $fromPos, $base, $end)) return $fromPos;
    $limit = min($fromPos + $maxScan, $end - 32);
    for ($p = $fromPos + 4; $p <= $limit; $p += 4) {
        if ($this->looksLikeExportHeaderDeepAt($R, $p, $base, $end)) return $p;
    }
    return null;
}








protected function fmtHex32(int $v): string { return sprintf('0x%08X', $v & 0xFFFFFFFF); }

public function annotateExportHex(int $max = 10): void
{
    foreach ($this->exports as $i => &$e) {
        if ($i >= $max) break;
        $e['text'] = $e['text'] ?? [];
        $e['text']['hex'] = [
            'objectFlags' => $this->fmtHex32((int)$e['objectFlags']),
            'exportFlags' => $this->fmtHex32((int)$e['exportFlags']),
            'serialSize'  => $this->fmtHex32((int)$e['serialSize']),
            'serialOffset'=> $this->fmtHex32((int)$e['serialOffset']),
        ];
    }
    unset($e);
}





/** Resolve an FName pair to text using the Names table. */
protected function fnameToString(int $index, int $number): string
{
    if ($index < 0) return 'None';
    $base = $this->names[$index]['name'] ?? ('<?>'.$index);
    return $number !== 0 ? $base.'_'.$number : $base;
}
/** Decode FPackageIndex without resolving. */
protected function decodePkgIndex(int $v): array
{
    if ($v === 0) return ['kind'=>'null','idx'=>-1];
    if ($v > 0)   return ['kind'=>'export','idx'=>$v - 1];
    return ['kind'=>'import','idx'=>(-$v) - 1];
}
/**
 * Make a label for a package index using name lookups we precomputed.
 * $importsText[$i] is the display name of import i, $exportsText[$i] for export i.
 */
protected function labelPkgIndex(int $v, array $importsText, array $exportsText): string
{
    $pi = $this->decodePkgIndex($v);
    switch ($pi['kind']) {
        case 'null':   return 'null';
        case 'import': return 'Import#'.$pi['idx'].' ('.($importsText[$pi['idx']] ?? '<?>').')';
        case 'export': return 'Export#'.$pi['idx'].' ('.($exportsText[$pi['idx']] ?? '<?>').')';
        default:       return '<?>'.$v;
    }
}
/**
 * Annotate imports and exports with resolved text under a ['text'] subarray.
 * - Imports:  ['text'] = [classPackage, className, objectName, outer]
 * - Exports:  ['text'] = [name, class, super, outer, archetype]
 */
public function annotateTablesWithText(): void
{
    // Precompute display names for quick cross-refs
    $importsText = [];
    foreach ($this->imports as $i => $im) {
        $importsText[$i] = $this->fnameToString(
            (int)($im['objectNameIndex']  ?? -1),
            (int)($im['objectNameNumber'] ?? 0)
        );
    }

    $exportsText = [];
    foreach ($this->exports as $i => $ex) {
        $exportsText[$i] = $this->fnameToString(
            (int)($ex['nameIndex']  ?? -1),
            (int)($ex['nameNumber'] ?? 0)
        );
    }

    // Hydrate imports
    foreach ($this->imports as $i => &$im) {
        $im['text'] = [
            'classPackage' => $this->fnameToString(
                (int)($im['classPackageIndex']  ?? -1),
                (int)($im['classPackageNumber'] ?? 0)
            ),
            'className'    => $this->fnameToString(
                (int)($im['classNameIndex']  ?? -1),
                (int)($im['classNameNumber'] ?? 0)
            ),
            'objectName'   => $importsText[$i] ?? '<?>',
            'outer'        => $this->labelPkgIndex((int)($im['outer'] ?? 0), $importsText, $exportsText),
        ];
    }
    unset($im);

    // Hydrate exports
    foreach ($this->exports as $i => &$ex) {
        $arch = (int)($ex['archetype'] ?? 0);
        $ex['text'] = [
            'name'      => $exportsText[$i] ?? '<?>',
            'class'     => $this->labelPkgIndex((int)($ex['class'] ?? 0), $importsText, $exportsText),
            'super'     => $this->labelPkgIndex((int)($ex['super'] ?? 0), $importsText, $exportsText),
            'outer'     => $this->labelPkgIndex((int)($ex['outer'] ?? 0), $importsText, $exportsText),
            'archetype' => $this->labelPkgIndex($arch, $importsText, $exportsText),
        ];
    }
    unset($ex);
}






	
	// Map UE3 CompressionFlags directly to the codec.
	// 1 = zlib, 2 = lzo, 4 = lzx. No auto-detect/sniffing.
	private function codecFromFlags(int $flags): string
	{
		if ($flags & 2) return 'lzo';   // UT3 commonly uses LZO
		if ($flags & 1) return 'zlib';
		if ($flags & 4) return 'lzx';   // rare
		return 'zlib'; // conservative fallback
	}

	// TUE3::detectGameFromNames (FULL FUNCTION)
	private function detectGameFromNames(array $names): string {
		// Trim + lowercase once for faster contains checks
		$joined = strtolower(' ' . implode(' ', $names) . ' ');

		// Very conservative, distinctive tags (avoid guessing):
		if (strpos($joined, ' tagame ')         !== false || strpos($joined, ' rocketleague ')  !== false) return 'RocketLeague';
		if (strpos($joined, ' mkscript ')       !== false || strpos($joined, ' mortal ')        !== false || strpos($joined, ' injustice ') !== false) return 'MK';
		if (strpos($joined, ' wheelman ')       !== false) return 'Wheelman';
		if (strpos($joined, ' alpha protocol ') !== false || strpos($joined, ' alphaprotocol ') !== false) return 'AlphaProtocol';
		if (strpos($joined, ' transformers ')   !== false) return 'Transformers';
		if (strpos($joined, ' batman ')         !== false || strpos($joined, ' arkham ')        !== false) return 'Batman';
		if (strpos($joined, ' huxley ')         !== false) return 'Huxley';
		if (strpos($joined, ' bioshock ')       !== false && strpos($joined, ' bioshock3 ')     !== false) return 'Bioshock3';
		return 'Generic';
	}
	// Add &$meta (optional) to collect header info
protected function decompressChunkFramed(int $cOff, int $cSize, int $uSize, array &$meta = null): string
{
    $raw = $this->R->physSlice($cOff, $cSize);
    $rr  = new TReader($raw); $rr->setBounds(0, strlen($raw));

    $out = '';
    $usedHeader = false;
    $meta = $meta ?? [];

    // Try headered layout
    if ($rr->remaining() >= 16) {
        $save     = $rr->tell();
        $tag      = $rr->u32();
        $blkSize  = $rr->u32();
        $compTot  = $rr->i32();
        $uncTot   = $rr->i32();

        if ($blkSize > 0 && $uncTot > 0) {
            $num = (int) ceil($uncTot / $blkSize);
            if ($rr->remaining() >= $num * 8) {
                $pairs = [];
                for ($i = 0; $i < $num; $i++) { $pairs[] = [$rr->i32(), $rr->i32()]; }
                // decompress blocks…
                $pos = $rr->tell();
                foreach ($pairs as [$cs,$us]) {
                    $cPayload = $rr->bytes($cs);
                    $part = UE_Decompress::inflate((int)$this->compressionFlags, $cPayload, $us, ['ue'=>'UE3','blockSize'=>$blkSize]);
                    $out .= $part;
                }
                $usedHeader = true;
                $meta = [
                    'layout'            => 'headered',
                    'tag'               => $tag,
                    'blockSize'         => $blkSize,
                    'compressedTotal'   => $compTot,
                    'uncompressedTotal' => $uncTot,
                    'blockCount'        => $num,
                ];
            } else {
                $rr->seek($save);
            }
        } else {
            $rr->seek($save);
        }
    }

    // Fallback: raw layout of consecutive pairs (no global header)
    if (!$usedHeader) {
        $r2 = new TReader($raw); $r2->setBounds(0, strlen($raw));
        $blocks = 0;
        while ($r2->remaining() >= 8 && strlen($out) < $uSize) {
            $cs = $r2->i32(); $us = $r2->i32();
            if ($cs <= 0 || $us <= 0 || $r2->remaining() < $cs) break;
            $part = UE_Decompress::inflate((int)$this->compressionFlags, $r2->bytes($cs), $us, ['ue'=>'UE3','blockSize'=>0]);
            $out .= $part; $blocks++;
        }
        $meta = [
            'layout'     => 'raw',
            'blockCount' => $blocks,
        ];
    }

    // Fit to expected size
    if (strlen($out) > $uSize) $out = substr($out, 0, $uSize);
    if (strlen($out) < $uSize) $out = str_pad($out, $uSize, "\x00");

    return $out;
}


public function dumpExportHeader(int $i): string
{
    $e = $this->exports[$i];

    $n  = $this->fnameToString($e['nameIndex'], $e['nameNumber']);

    $d = fn($v) => $this->decodePkgIndex((int)$v);
    $c = $d($e['class']); $s = $d($e['super']); $o = $d($e['outer']);

    $fmt = function(array $pi): string {
        if ($pi['kind']==='null') return 'null';
        return $pi['kind'].'#'.$pi['idx'];
    };

    return sprintf(
        "#%d name=%s class=%s super=%s outer=%s flags=%s serial=%d@%d xflags=%u",
        $i, $n, $fmt($c), $fmt($s), $fmt($o),
        (string)$e['objectFlags'],
        (int)$e['serialSize'], (int)($e['serialOffset'] ?? 0),
        (int)($e['exportFlags'] ?? 0)
    );
}






	// TUE3:exportBodyReader  (example usage)
	protected function exportBodyReader(int $uStart, int $uSize): TReader {
		// uStart/uSize are UNCOMPRESSED logical positions/sizes
		return $this->readerForLogical($uStart, $uStart + $uSize);
	}
	
	/**
	 * Build a reader over the entire logical (uncompressed) UE3 stream.
	 * - If the package is not compressed (or has no chunk table), we just expose the raw bytes.
	 * - If compressed, we:
	 *     1) sort chunks by logical offset (uOff),
	 *     2) allocate a zero-filled buffer of total logical size (max(uOff + uSize)),
	 *     3) decompress each chunk and place it at its exact uOff in the buffer,
	 *     4) return a TReader over that buffer.
	 *
	 * This mirrors the behavior of UnrealPackageReader5.php so logical offsets
	 * like NameOffset/ImportOffset/ExportOffset can be seek()'d directly.
	 */
	protected function makeFullLogicalReader(): TReader
{
    if (!$this->compressed || empty($this->chunks)) {
        $r = new TReader($this->bytes);
        $r->setBounds(0, strlen($this->bytes));
        // clear any stale meta
        $this->chunkMeta = [];
        return $r;
    }

    $chunks = $this->chunks;
    usort($chunks, static fn(array $a, array $b): int => ((int)$a['uOff']) <=> ((int)$b['uOff']));

    $total = 0;
    foreach ($chunks as $ch) {
        $end = (int)$ch['uOff'] + (int)$ch['uSize'];
        if ($end > $total) $total = $end;
    }

    $buf = ($total > 0) ? str_repeat("\x00", $total) : '';
    $this->chunkMeta = []; // reset each build

    foreach ($chunks as $idx => $ch) {
        $uOff  = (int)$ch['uOff'];
        $uSize = (int)$ch['uSize'];
        $cOff  = (int)$ch['cOff'];
        $cSize = (int)$ch['cSize'];

        if ($uSize === 0) {
            $this->chunkMeta[$idx] = [
                'uOff'=>$uOff,'uSize'=>$uSize,'cOff'=>$cOff,'cSize'=>$cSize,
                'codec'=>(int)$this->compressionFlags,
                'header'=>['layout'=>'empty','blockCount'=>0],
            ];
            continue;
        }

        $need = $uOff + $uSize;
        if ($need > strlen($buf)) {
            $buf .= str_repeat("\x00", $need - strlen($buf));
        }

        $meta = [];
        $part = $this->decompressChunkFramed($cOff, $cSize, $uSize, $meta);

        // normalize length
        if (strlen($part) > $uSize) $part = substr($part, 0, $uSize);
        if (strlen($part) < $uSize) $part = str_pad($part, $uSize, "\x00");

        $buf = substr_replace($buf, $part, $uOff, $uSize);

        $this->chunkMeta[$idx] = [
            'uOff'  => $uOff,
            'uSize' => $uSize,
            'cOff'  => $cOff,
            'cSize' => $cSize,
            'codec' => (int)$this->compressionFlags,
            'header'=> $meta, // from decompressor
        ];
    }

    $r = new TReader($buf);
    $r->setBounds(0, strlen($buf));
    return $r;
}




	
	


	private function readCompressedChunkHeader(TReader $R): array {
		// [Tag=0x9E2A83C1][BlockSize][SumCompressed][SumUncompressed], then BlockCount pairs
		$tag        = $R->u32();
		$blockSize  = $R->u32();
		$sumC       = $R->i32();
		$sumU       = $R->i32();
		if ($tag !== 0x9E2A83C1) {
			throw new \RuntimeException(sprintf("UE3 chunk header: bad tag 0x%08X", $tag));
		}
		if ($blockSize <= 0 || $sumU <= 0) {
			throw new \RuntimeException("UE3 chunk header: invalid blockSize/sumU");
		}
		$blockCount = (int)ceil($sumU / $blockSize);
		$blocks     = [];
		for ($i = 0; $i < $blockCount; $i++) {
			$c = $R->i32();
			$u = $R->i32();
			if ($c <= 0 || $u <= 0) {
				throw new \RuntimeException("UE3 chunk header: invalid block[$i] sizes");
			}
			$blocks[] = ['c'=>$c, 'u'=>$u];
		}
		return ['tag'=>$tag, 'blockSize'=>$blockSize, 'sumC'=>$sumC, 'sumU'=>$sumU, 'blockCount'=>$blockCount, 'blocks'=>$blocks];
	}

	// TUE3:readTablesAt
	private function readTablesAt(int $pos): array {
		$R                  = $this->R; 
		$save               = $R->tell(); 
		$R->seek($pos);
		$h                  = [];
		$h['nameCount']     = $R->i32();
		$h['nameOffset']    = $R->i32();
		$h['exportCount']   = $R->i32();
		$h['exportOffset']  = $R->i32();
		$h['importCount']   = $R->i32();
		$h['importOffset']  = $R->i32();
		$h['dependsOffset'] = $R->i32();
		$h['guid']          = [ $R->u32(), $R->u32(), $R->u32(), $R->u32() ];
		$genCount           = $R->i32();
		$gens               = [];
		
		if ($genCount < 0 || $genCount > 4096) { $R->seek($save); throw new \RuntimeException("UE3 header: bad genCount $genCount at $pos"); }
		
		for ($i=0; $i<$genCount; $i++) { $gens[] = [ 'exportCount'=>$R->i32(), 'nameCount'=>$R->i32() ]; }
		
		$h['generations'] = $gens; $h['__genCount'] = $genCount;
		
		return $h;
	}

	// Build a TReader over the LOGICAL (uncompressed) range [uStart, uEnd).
	// Chunks (uSize,cSize,uOff,cOff) cover *compressed* islands in logical space.
	// Any logical span NOT covered by a chunk is stored *raw* at the same physical offsets.
	private function readerForLogical(int $uStart, int $uEnd): TReader
	{
		$uStart = max(0, $uStart);
		if ($uEnd < $uStart) $uEnd = $uStart;
		$need    = $uStart;
		$needLen = $uEnd - $uStart;

		// Uncompressed package: trivial
		if (!$this->compressed || empty($this->chunks)) {
			$r = new TReader($this->bytes);
			$r->setBounds(0, strlen($this->bytes));
			$r->seek($uStart);
			$r->setBounds($uStart, $uEnd);
			return $r;
		}

		// Sort chunks by logical start
		$chunks = $this->chunks;
		usort($chunks, fn($a,$b) => $a['uOff'] <=> $b['uOff']);

		$out = '';

		// Iterate across [uStart, uEnd), mixing raw gaps + decompressed islands
		$i = 0;
		$n = count($chunks);

		while ($need < $uEnd) {
			// Advance to the first chunk that could overlap $need
			while ($i < $n && ($chunks[$i]['uOff'] + $chunks[$i]['uSize']) <= $need) {
				$i++;
			}

			// If no chunk overlaps $need, copy the raw gap up to next chunk (or end)
			if ($i >= $n || $chunks[$i]['uOff'] > $need) {
				$gapEnd = ($i < $n) ? min($uEnd, (int)$chunks[$i]['uOff']) : $uEnd;
				$gapLen = $gapEnd - $need;
				if ($gapLen > 0) {
					// raw bytes are stored at the same physical offset as their logical position
					$out .= $this->R->physSlice($need, $gapLen);
					$need = $gapEnd;
					continue;
				}
			}

			// Now $chunks[$i] overlaps or starts at $need
			$ch  = $chunks[$i];
			$u0  = (int)$ch['uOff'];
			$u1  = $u0 + (int)$ch['uSize'];

			// Decompress this chunk to its full uncompressed bytes
			$dec = $this->decompressChunkFramed((int)$ch['cOff'], (int)$ch['cSize'], (int)$ch['uSize']);

			// Copy the overlapped logical part into output
			$takeFrom = max($need, $u0);
			$takeTo   = min($uEnd,  $u1);
			if ($takeTo > $takeFrom) {
				$out  .= substr($dec, $takeFrom - $u0, $takeTo - $takeFrom);
				$need  = $takeTo;
			} else {
				// No overlap (shouldn’t happen here), move to next chunk to avoid infinite loop
				$i++;
			}
		}

		if (strlen($out) !== $needLen) {
			throw new \RuntimeException("UE3 logical reader: stitched ".strlen($out)." of $needLen for [$uStart,$uEnd).");
		}

		// Return a reader over the stitched logical buffer (pos 0 == logical uStart)
		$r = new TReader($out);
		$r->setBounds(0, strlen($out));
		return $r;
	}
	
// Validate the parsed export table; returns an array of human-readable issues.
// Does NOT throw; you can print or log these.
protected function verifyExportTableReadable(): array
{
    $issues = [];

    $nameCount   = (int)($this->header['nameCount']   ?? 0);
    $importCount = (int)($this->header['importCount'] ?? 0);
    $exportCount = (int)($this->header['exportCount'] ?? 0);

    // Use logical stream size for serial range checks (raw or decompressed as per your design)
    $R = ($this->compressed && !empty($this->chunks)) ? $this->makeFullLogicalReader() : $this->R;
    $logicalSize = $R->size();

    $isPkgIndex = function (int $idx) use ($importCount, $exportCount): bool {
        if ($idx == 0) return true;
        if ($idx > 0)  { $e = $idx - 1; return $e >= 0 && $e < $exportCount; }
        $i = (-$idx) - 1; return $i >= 0 && $i < $importCount;
    };

    foreach (($this->exports ?? []) as $i => $e) {
        $class = (int)($e['class'] ?? 0);
        $super = (int)($e['super'] ?? 0);
        $outer = (int)($e['outer'] ?? 0);
        $nameI = (int)($e['nameIndex'] ?? -1);
        $nameN = (int)($e['nameNumber'] ?? -1);

        if (!$isPkgIndex($class)) $issues[] = "#$i: class out of range ($class)";
        if (!$isPkgIndex($super)) $issues[] = "#$i: super out of range ($super)";
        if (!$isPkgIndex($outer)) $issues[] = "#$i: outer out of range ($outer)";

        if (!($nameI >= 0 && $nameI < max(1, $nameCount))) $issues[] = "#$i: nameIndex out of range ($nameI / $nameCount)";
        if ($nameN < 0) $issues[] = "#$i: nameNumber negative ($nameN)";

        // Cross-table quick checks
        if ($class < 0) {
            $idx = (-$class) - 1;
            if ($idx < 0 || $idx >= $importCount) $issues[] = "#$i: class import index invalid ($class)";
        }
        if ($outer > 0) {
            $idx = $outer - 1;
            if ($idx < 0 || $idx >= $exportCount) $issues[] = "#$i: outer export index invalid ($outer)";
        }

        // Serial region checks
        $sz  = (int)($e['serialSize']   ?? 0);
        $off = (int)($e['serialOffset'] ?? 0);
        if ($sz == 0) {
            // nothing to read; offset is advisory in some cooks
        } else {
            if ($off <= 0)                       $issues[] = "#$i: serialOffset <= 0 with serialSize $sz";
            if (($off & 3) != 0)                 $issues[] = "#$i: serialOffset not 4-byte aligned ($off)";
            if ($off + $sz > $logicalSize)       $issues[] = "#$i: serial range [$off, ".($off+$sz).") exceeds logical size $logicalSize";
        }

        // Flags sanity (optional): exportFlags and objectFlags are just stored; no hard limits here.
    }

    return $issues;
}



}
//===========================================================================================
final class TUE4 extends AbstractUE {
    protected function readHeader(): void {
        $R = $this->R;
        $this->header['tag']             = $R->u32();
        $this->header['version']         = $R->i32();   // >= 522 typically
        $this->header['licenseeVersion'] = $R->i32();
        $this->header['packageFlags']    = $R->u32();
        $this->header['nameCount']       = $R->i32();
        $this->header['nameOffset']      = $R->i32();
        $this->header['exportCount']     = $R->i32();
        $this->header['exportOffset']    = $R->i32();
        $this->header['importCount']     = $R->i32();
        $this->header['importOffset']    = $R->i32();
        $this->header['dependsOffset']   = $R->i32();
        $this->header['guid']            = [$R->u32(), $R->u32(), $R->u32(), $R->u32()];
        $genCount                        = $R->i32();
        $gens                            = [];
		
        for ($i=0;$i<$genCount;$i++){
            $gens[]=['exportCount'=>$R->i32(),'nameCount'=>$R->i32()];
        }
		
        $this->header['generations']=$gens;
        $this->compressed = false; // UE4 generally uses chunked bulk formats; not header-table compression
        // TODO: add UE4-specific header tails from your Package4.cpp as needed
    }
	
    protected function readNameTable(): void {
        $this->names = [];
        $R           = $this->R;
        $R->seek($this->header['nameOffset']);
		
        for ($i=0;$i<$this->header['nameCount'];$i++){
            // UE4 name entries can differ (include non-localized hash, number, etc.)
            // Start with FString + flags to keep parity; extend per Package4.cpp.
            $name          = $R->fstring();
            $flags         = $R->u32();
            $this->names[] = ['name'=>$name,'flags'=>$flags];
        }
    }
	
    protected function readImportTable(): void {
        $this->imports = [];
        $R             = $this->R;
        $R->seek($this->header['importOffset']);
		
        for ($i=0;$i<$this->header['importCount'];$i++){
            $classPackage = $R->i32();
            $className    = $R->i32();
            $outerIndex   = $R->i32();
            $objectName   = $R->i32();
            $this->imports[] = compact('classPackage','className','outerIndex','objectName');
        }
    }
	
    protected function readExportTable(): void {
        $this->exports = [];
		$R             = $this->R;
        $R->seek($this->header['exportOffset']);        
		
        for ($i=0;$i<$this->header['exportCount'];$i++){
            $classIndex   = $R->i32();
            $superIndex   = $R->i32();
            $outerIndex   = $R->i32();
            $objectName   = $R->i32();
            $objectFlags  = $R->i64(); // UE4 uses 64-bit RF flags
            $serialSize   = $R->i32();
            $serialOffset = $serialSize>0 ? $R->i32() : null;
            // TODO: add UE4 export tails (TemplateIndex, SaveGame flags, etc.) per Package4.cpp
            $this->exports[] = [
                'classIndex'=>$classIndex,'superIndex'=>$superIndex,'outerIndex'=>$outerIndex,
                'objectName'=>$objectName,'objectFlags'=>$objectFlags,
                'serialSize'=>$serialSize,'serialOffset'=>$serialOffset
            ];
        }
    }

	// TUE4:decompressChunk (add or replace)
	private function decompressChunk(int $cOff, int $cSize, int $uSize): string {
		$raw = $this->R->physSlice($cOff, $cSize);
		
		if ($uSize <= 0) 
			throw new \RuntimeException("UE4 decompress: invalid uSize $uSize");

		$sig4   = substr($raw, 0, 4);
		$method = $this->header['compressionMethod'] ?? null; // if your UE4 parser exposes it
		$codec  = UE_Decompress::pickUE4(is_string($method)?$method:null, $sig4);

		return UE_Decompress::inflate($codec, $raw, $uSize, ['ue'=>'UE4', 'method'=>$method]);
	}
}
//===========================================================================================
// TUnrealPackage.php — class:function
// UE_Decompress (shared router for UE3/UE4; chunk-in -> bytes-out)
final class UE_Decompress
{
    /** @var array<int, callable> */
    private static array $codecs = [];

    public static function register(int $id, callable $fn): void
    {
        self::$codecs[$id] = $fn;
    }

    /**
     * $flags is a bitmask. Prefer more specific codecs first (LZO over zlib, etc.).
     */
    public static function inflate(int $flags, string $payload, int $expectedLen, array $ctx = []): string
    {
        // Prefer LZO if present
        if (($flags & 0x02) && isset(self::$codecs[2])) {
            return (self::$codecs[2])($payload, $expectedLen, $ctx);
        }
        // zlib fallback
        if (($flags & 0x01) && isset(self::$codecs[1])) {
            return (self::$codecs[1])($payload, $expectedLen, $ctx);
        }

        throw new \RuntimeException("No decoder registered for codec '".($flags & ~0)."'");
    }
}

//===========================================================================================

// zlib / deflate (codec 1)
UE_Decompress::register(1, function (string $data, int $expected, array $ctx): string {
    $out = @gzuncompress($data);
    if ($out === false) {
        // Some games store raw DEFLATE without zlib header – try that too
        $out = @gzinflate($data);
    }
    if ($out === false) {
        throw new \RuntimeException("zlib: decompression failed");
    }
    return $out;
});

// LZO1X (codec 2)
UE_Decompress::register(2, function (string $data, int $expected, array $ctx): string {
    // Option A: native extension (if available)
    if (function_exists('lzo1x_decompress')) {
        $out = lzo1x_decompress($data, $expected); // some extensions accept expected length
        if (!is_string($out)) {
            throw new \RuntimeException("LZO: native decompress failed");
        }
        return $out;
    }
    if (class_exists('\\LZO') && method_exists('\\LZO', 'decompress')) {
        $out = \LZO::decompress($data, $expected);
        if (!is_string($out)) {
            throw new \RuntimeException("LZO: \\LZO::decompress failed");
        }
        return $out;
    }

    // Option B: reuse your old pure-PHP decoder from UnrealPackageReader5.php
    // Find the LZO routine there (search for 'LZO', 'lzo1x', or 'decompressLZO').
    // Suppose its signature is: OldLZO::decode(string $data, int $expected): string
    if (class_exists('OldLZO') && method_exists('OldLZO', 'decode')) {
        return OldLZO::decode($data, $expected);
    }

    // If neither native nor old decoder available:
    throw new \RuntimeException("LZO codec required (2), but no LZO decoder is available. Bring over the LZO routine from UnrealPackageReader5.php or install a PHP LZO extension.");
});



// TUnrealPackage.php — class:function
// UE_LZO1X (minimal decoder for UE3-style data)
final class UE_LZO1X
{
    public static function decompress(string $c, int $expected): string {
        $i = 0; 
		$n = strlen($c); 
		$o = '';
		
        while ($i < $n && strlen($o) < $expected) {
            $ctrl = ord($c[$i++]);
			
            if ($ctrl >= 0xE0) { // long literal run
                $lit = (($ctrl & 0x1F) << 2);
				
                if ($i < $n) 
					$lit |= (ord($c[$i++]) >> 6);
				
                for ($k=0; $k<$lit && $i<$n; $k++) 
					$o .= $c[$i++];
				
                continue;
            }
			
            if ($ctrl >= 0xC0) { // short match
                $len  = ($ctrl & 0x1F) + 3;
                $dist = ord($c[$i++]) | ((($ctrl & 0x20)?1:0) << 8);
                self::copy($o, $dist+1, $len);
                continue;
            }
            if ($ctrl >= 0x80) { // literal then match
                $lit = ($ctrl & 0x1F);
                for ($k=0; $k<$lit && $i<$n; $k++) 
					$o .= $c[$i++];
				
                if ($i+1 >= $n) 
					break;
				
                $len  = 3 + (ord($c[$i]) >> 5);
                $dist = ((ord($c[$i]) & 0x1F) << 8) | ord($c[$i+1]); 
				$i += 2;
                self::copy($o, $dist+1, $len);
                continue;
            }
            // small literal
            $lit = $ctrl & 0x7F;
			
            for ($k=0; $k<$lit && $i<$n; $k++) 
				$o .= $c[$i++];
        }
		
        return $o;
    }

    private static function copy(string &$o, int $dist, int $len): void {
        $L   = strlen($o); 
		$src = $L - $dist;
		
        for ($k=0; $k<$len; $k++) {
            $o .= ($src + $k) >= 0 && ($src + $k) < $L ? $o[$src + $k] : "\0";
        }
    }
}

//===========================================================================================
// TUnrealPackage.php — class:function
// UE_LZX (compact LZX/XMem inflator for UE3; chunk-by-chunk)
final class UE_LZX
{
    public static function decompress(string $c, int $expected): string {
        $d = new self($c, $expected);
		
        return $d->run();
    }

    private string $c; private int $clen; private int $want;
    private int $pos = 0; private int $bitbuf = 0; private int $bits = 0;
    private string $out = '';

    function __construct(string $c, int $expected) {
        $this->c = $c; $this->clen = strlen($c); $this->want = $expected;
    }

    private function needBits(int $n): void {
        while ($this->bits < $n) {
            $b = ($this->pos < $this->clen) ? ord($this->c[$this->pos++]) : 0;
            $this->bitbuf = ($this->bitbuf << 8) | $b;
            $this->bits += 8;
        }
    }
    private function readBits(int $n): int {
        $this->needBits($n);
        $val = ($this->bitbuf >> ($this->bits - $n)) & ((1 << $n) - 1);
        $this->bits -= $n;
		
        return $val;
    }
    private function alignByte(): void {
        $k = $this->bits % 8;
		
        if ($k) 
			$this->readBits($k);
    }

    private function readTree(array &$lens, int $n): void {
        // Simple RLE’d length reader (adequate for common UT3 streams)
        $lens = array_fill(0, $n, 0);
		
        for ($i=0; $i<$n; ) {
            $v = $this->readBits(3);
			
            if ($v === 7) { // run
                $run = $this->readBits(5);
                $sym = $this->readBits(3);
				
                for ($k=0; $k<$run && $i<$n; $k++, $i++) 
					$lens[$i] = $sym;
            } else {
                $lens[$i++] = $v;
            }
        }
    }
    private static function buildHuff(array $lens, int $maxBits=12): array {
        $n = count($lens); $count = array_fill(0,$maxBits+1,0);
		
        foreach ($lens as $ln) if ($ln) $count[$ln]++;
		
        $code=0; $next=array_fill(0,$maxBits+1,0);
		
        for ($i=1;$i<=$maxBits;$i++){ $code = ($code + $count[$i-1])<<1; $next[$i]=$code; }
		
        $tab = array_fill(0, 1<<$maxBits, -1);
		
        for ($i=0;$i<$n;$i++) { $ln=$lens[$i]??0; if(!$ln) continue;
            $c = $next[$ln]++; $fill = 1<<($maxBits-$ln);
            for ($j=0;$j<$fill;$j++) $tab[($c<<($maxBits-$ln)) | $j] = $i;
        }
		
        return $tab;
    }
    private function sym(array $tab, array $lens, int $maxBits=12): int {
        $idx = $this->readBits($maxBits);
        $s = $tab[$idx];
		
        if ($s >= 0) 
			return $s;
		
        // slow path (rare; acceptable)
        $this->bits += $maxBits; // undo
        $this->bitbuf >>= $maxBits;
        $code=0; $bl=0;
		
        while (true) {
            $code = ($code<<1) | $this->readBits(1); $bl++;
            $acc=0;
			
            for ($i=0;$i<count($lens);$i++){
                if ($lens[$i]===$bl) { if ($acc===$code) return $i; $acc++; }
            }
        }
    }
    private function copy(int $dist, int $len): void {
        $L = strlen($this->out); $src = $L - $dist;
		
        for ($i=0; $i<$len; $i++) {
            $this->out .= ($src+$i)>=0 && ($src+$i)<$L ? $this->out[$src+$i] : "\0";
        }
    }

    public function run(): string {
        $this->alignByte();
		
        while (strlen($this->out) < $this->want) {
            $isCompressed = $this->readBits(1);
            $blkOut       = $this->readBits(24);
			
            if ($blkOut <= 0) 
				break;

            if ($isCompressed) {
                // Trees (very compact form)
                $mainLen=[]; $lenLen=[]; $distLen=[];
                $this->readTree($mainLen, 256 + (8*8));
                $this->readTree($lenLen,  249);
                $this->readTree($distLen, 16*8);
                $mainTab = self::buildHuff($mainLen);
                $lenTab  = self::buildHuff($lenLen);
                $distTab = self::buildHuff($distLen);
                $remain  = $blkOut;
				
                while ($remain > 0) {
                    $s = $this->sym($mainTab, $mainLen);
					
                    if ($s < 256) {
                        $this->out .= chr($s);
                        $remain--;
                    } else {
                        $lenSlot = $s - 256;
                        $length  = ($lenSlot & 7) + 3; // minimal mapping good for metadata
                        $distSlot= $this->sym($distTab, $distLen);
                        $dist    = ($distSlot << 3) | ($this->sym($lenTab, $lenLen, 3) & 7);
                        $this->copy($dist+1, $length);
                        $remain -= $length;
                    }
                }
            } else {
                // Stored bytes
                $this->alignByte();
				
                for ($i=0; $i<$blkOut; $i++) {
                    $this->needBits(8);
                    $this->out .= chr($this->readBits(8));
                }
            }
			
            $this->alignByte();
        }
        return $this->out;
    }
}
//===========================================================================================

// TUnrealPackage.php — class:function
// Register built-in handlers (call once; e.g., right after UE_Decompress definition)
/*
// ZLIB (ext/zlib)
UE_Decompress::register('zlib', function (string $c, int $uLen, array $opts): string {
    $raw = @gzinflate($c);
    if ($raw === false) $raw = @gzuncompress($c);
    if ($raw === false && function_exists('zlib_decode')) $raw = @zlib_decode($c);
    if ($raw === false) throw new \RuntimeException("ZLIB inflate failed");
    return $raw;
});

// LZO1X (compact, UE3-sufficient)
UE_Decompress::register('lzo', function (string $c, int $uLen, array $opts): string {
    return UE_LZO1X::decompress($c, $uLen);
});

// LZX/XMem (compact, tuned for UE3/UT3 metadata)
UE_Decompress::register('lzx', function (string $c, int $uLen, array $opts): string {
    return UE_LZX::decompress($c, $uLen);
});

// LZ4 (UE4 sometimes) — placeholder
UE_Decompress::register('lz4', function (string $c, int $uLen, array $opts): string {
    throw new \RuntimeException("LZ4 not implemented yet (UE4 path).");
});

// Oodle (UE4/UE5 often) — placeholder only
UE_Decompress::register('oodle', function (string $c, int $uLen, array $opts): string {
    throw new \RuntimeException("Oodle not available in pure PHP. Provide external support when ready.");
});


*/