<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the standalone `new` Unreal package reader and its supporting binary/package structures.
 * Why: It decodes `new` package formats for parser development and for catalog reader bridges that explicitly load
 *      it.
 * Role: Engine-specific parser/reference implementation; not itself a catalog UI page.
 * Audit: Legacy/reference area; verify active parser callers before deleting or folding it into shared reader code.
 */
declare(strict_types=1);

final class UnrealPackageReader
{
    private string $path;
    private array  $header                = [];
	private array  $names                 = [];
    private array  $imports               = [];
    private array  $exports               = [];
	private array  $generations           = [];
	private array  $exportProps           = [];  // [exportIndex => list of props]
	private array  $importProps           = [];  // imports don’t serialize props; keep empty lists
	private array  $chunkCache            = []; // ensure this property exists
	private array  $exportPropertiesCache = [];	
	private bool   $isCompressed          = false;
    private UEReader $R;

	private const RF_FLAGS = [
		0x00000001 => 'RF_Transactional',      // Object is transactional.
		0x00000002 => 'RF_Unreachable',        // Not reachable on the object graph.
		0x00000004 => 'RF_Public',             // Visible outside its package.
		0x00000008 => 'RF_TagImp',             // Temp import tag in load/save.
		0x00000010 => 'RF_TagExp',             // Temp export tag in load/save.
		0x00000020 => 'RF_SourceModified',     // Modified relative to source files.
		0x00000040 => 'RF_TagGarbage',         // Check during garbage collection.
		0x00000200 => 'RF_NeedLoad',           // During load, indicates object needs loading.
		0x00000400 => 'RF_HighlightedName',    // A hardcoded name which should be syntax-highlighted.
		0x00000800 => 'RF_InSingularFunc',     // In a singular function.  (aka RF_RemappedName in some docs)
		0x00001000 => 'RF_Suppress',           // Suppressed log name.     (aka RF_StateChanged in some docs)
		0x00002000 => 'RF_InEndState',         // Within an EndState call.
		0x00004000 => 'RF_Transient',          // Don't save object.
		0x00008000 => 'RF_PreLoading',         // Data is being preloaded from file.
		0x00010000 => 'RF_LoadForClient',      // In-file load for client.
		0x00020000 => 'RF_LoadForServer',      // In-file load for server.
		0x00040000 => 'RF_LoadForEdit',        // In-file load for editor.
		0x00080000 => 'RF_Standalone',         // Keep object around for editing even if unreferenced.
		0x00100000 => 'RF_NotForClient',       // Don’t load for client.
		0x00200000 => 'RF_NotForServer',       // Don’t load for server.
		0x00400000 => 'RF_NotForEdit',         // Don’t load for editor.
		0x00800000 => 'RF_Destroyed',          // Destroy has already been called.
		0x01000000 => 'RF_NeedPostLoad',       // Needs to be postloaded.
		0x02000000 => 'RF_HasStack',           // Has execution stack.
		0x04000000 => 'RF_Native',             // Native (UClass only).
		0x08000000 => 'RF_Marked',             // Marked (debugging).
		0x10000000 => 'RF_ErrorShutdown',      // ShutdownAfterError called.
		0x20000000 => 'RF_DebugPostLoad',      // For debugging Serialize calls.
		0x40000000 => 'RF_DebugSerialize',     // For debugging Serialize calls.
		0x80000000 => 'RF_DebugDestroy',       // For debugging Destroy calls.
	];
	
	private static array $UE3_COMPRESSION_FLAGS = [
		0x00000001 => 'CF_ZLIB',
		0x00000002 => 'CF_GZIP',
		0x00000010 => 'CF_BiasMemory',
		0x00000020 => 'CF_BiasSpeed',
		0x00000040 => 'CF_LZO',
		0x00000100 => 'CF_LZ4', // seen in later engines, not typical for UT3
		// vendor/platform-specific (rare in public UT3 PC builds):
		0x00000080 => 'CF_LZX', // some tools treat Xbox LZX as a distinct path
	];

    private const PKG_FLAGS = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000002 => 'PKG_ClientOptional',
        0x00000004 => 'PKG_ServerSideOnly',
        0x00000008 => 'PKG_NoExportAllowed',
        0x00000010 => 'PKG_Cooked',
        0x00000020 => 'PKG_Encrypted',
    ];
	/* from 2004 src
	PKG_AllowDownload	  = 0x0001,	// Allow downloading package.
	PKG_ClientOptional    = 0x0002,	// Purely optional for clients.
	PKG_ServerSideOnly    = 0x0004, // Only needed on the server side.
	PKG_BrokenLinks       = 0x0008, // Loaded from linker with broken import links.
	PKG_Unsecure          = 0x0010, // Not trusted.
	PKG_Official		  = 0x0020,	// "Official" package.  No downloads or download replacements allowed.
	PKG_ExtraDownloadFile = 0x4000,	// Fake package - for autodownloading of .ogg's etc.
	PKG_Need			  = 0x8000,	// Client needs to download this package.
	*/

    // Property types
    private const PROPERTY_TYPES = [
        0x00 => 'None',
        0x01 => 'ByteProperty',
        0x02 => 'IntProperty',
        0x03 => 'BoolProperty',
        0x04 => 'FloatProperty',
        0x05 => 'ObjectProperty',
        0x06 => 'NameProperty',
        0x07 => 'StringProperty',
        0x08 => 'ClassProperty',
        0x09 => 'ArrayProperty',
        0x0A => 'StructProperty',
        0x0B => 'VectorProperty',
        0x0C => 'RotatorProperty',
        0x0D => 'StrProperty',
		0x0E => 'MapProperty',
        0x0F => 'FixedArrayProperty',
    ];
	
	private const PROPERTY_FLAGS = [
		0x00000001 => 'CPF_Edit',
		0x00000002 => 'CPF_Const',
		0x00000004 => 'CPF_Input',
		0x00000008 => 'CPF_ExportObject',
		0x00000010 => 'CPF_OptionalParm',
		0x00000020 => 'CPF_Net',           // relevant to replication
		0x00000040 => 'CPF_ConstRef',
		0x00000080 => 'CPF_Parm',
		0x00000100 => 'CPF_OutParm',
		0x00000200 => 'CPF_SkipParm',
		0x00000400 => 'CPF_ReturnParm',
		0x00000800 => 'CPF_CoerceParm',
		0x00001000 => 'CPF_Native',
		0x00002000 => 'CPF_Transient',
		0x00004000 => 'CPF_Config',
		0x00008000 => 'CPF_Localized',
		0x00010000 => 'CPF_Travel',
		0x00020000 => 'CPF_EditConst',
		0x00040000 => 'CPF_GlobalConfig',
		0x00100000 => 'CPF_OnDemand',
		0x00200000 => 'CPF_New',
		0x00400000 => 'CPF_NeedCtorLink',
	];

	private const FUNCTION_FLAGS = [
		0x00000001 => 'FUNC_Final',
		0x00000002 => 'FUNC_Defined',
		0x00000004 => 'FUNC_Iterator',
		0x00000008 => 'FUNC_Latent',
		0x00000010 => 'FUNC_PreOperator',
		0x00000020 => 'FUNC_Singular',
		0x00000040 => 'FUNC_Net',
		0x00000080 => 'FUNC_NetReliable',
		0x00000100 => 'FUNC_Simulated',
		0x00000200 => 'FUNC_Exec',
		0x00000400 => 'FUNC_Native',
		0x00000800 => 'FUNC_Event',
		0x00001000 => 'FUNC_Operator',
		0x00002000 => 'FUNC_Static',
		0x00004000 => 'FUNC_NoExport',
		0x00008000 => 'FUNC_Const',
		0x00010000 => 'FUNC_Invariant',
	];

	private const STATE_FLAGS = [
		0x00000001 => 'STATE_Editable',
		0x00000002 => 'STATE_Auto',
		0x00000004 => 'STATE_Simulated',
	];

	private const CLASS_FLAGS = [
		0x00001 => 'CLASS_Abstract',
		0x00002 => 'CLASS_Compiled',
		0x00004 => 'CLASS_Config',
		0x00008 => 'CLASS_Transient',
		0x00010 => 'CLASS_Parsed',
		0x00020 => 'CLASS_Localized',
		0x00040 => 'CLASS_SafeReplace',
		0x00080 => 'CLASS_RuntimeStatic',
		0x00100 => 'CLASS_NoExport',
		0x00200 => 'CLASS_NoUserCreate',
		0x00400 => 'CLASS_PerObjectConfig',
		0x00800 => 'CLASS_NativeReplication',
		// Note: 'Native' is tracked via RF_Native in object flags.
	];
	
	/** Map of opcode byte to mnemonic. Add more as needed per PDF. */
	private const OP = [
		0x00=>'EX_LocalVariable', 
		0x01=>'EX_InstanceVariable', 
		0x02=>'EX_DefaultVariable',
		0x04=>'EX_Return', 
		0x05=>'EX_Switch', 
		0x06=>'EX_Jump', 
		0x07=>'EX_JumpIfNot',
		0x08=>'EX_Stop', 
		0x09=>'EX_Assert', 
		0x0A=>'EX_Case', 
		0x0B=>'EX_Nothing',
		0x0C=>'EX_LabelTable', 
		0x0D=>'EX_GotoLabel', 
		0x0E=>'EX_EatString',
		0x0F=>'EX_Let', 
		0x10=>'EX_DynArrayElement', 
		0x11=>'EX_New',
		0x12=>'EX_ClassContext', 
		0x13=>'EX_MetaCast', 
		0x14=>'EX_LetBool',
		0x16=>'EX_EndFunctionParms', 
		0x17=>'EX_Self', 
		0x18=>'EX_Skip',
		0x19=>'EX_Context', 
		0x1A=>'EX_ArrayElement', 
		0x1B=>'EX_VirtualFunction',
		0x1C=>'EX_FinalFunction', 
		0x1D=>'EX_IntConst', 
		0x1E=>'EX_FloatConst',
		0x1F=>'EX_StringConst', 
		0x20=>'EX_ObjectConst', 
		0x21=>'EX_NameConst',
		0x22=>'EX_RotationConst', 
		0x23=>'EX_VectorConst', 
		0x24=>'EX_ByteConst',
		0x25=>'EX_IntZero', 
		0x26=>'EX_IntOne', 
		0x27=>'EX_True', 
		0x28=>'EX_False',
		0x29=>'EX_NativeParm', 
		0x2A=>'EX_NoObject', 
		0x2C=>'EX_IntConstByte',
		0x2D=>'EX_BoolVariable', 
		0x2E=>'EX_DynamicCast', 
		0x2F=>'EX_Iterator',
		0x30=>'EX_IteratorPop', 
		0x31=>'EX_IteratorNext',
		0x32=>'EX_StructCmpEq', 
		0x33=>'EX_StructCmpNe',
		0x34=>'EX_UnicodeStringConst', 
		0x36=>'EX_StructMember',
		0x38=>'EX_GlobalFunction', 
		0x39=>'EX_RotatorToVector',
		0x60=>'EX_ExtendedNative', 
		0x70=>'EX_FirstNative'
	];
	
	// Known-but-limited name-table flag hints, these are editor/name flags).
	// Works for UE1/UE2 (u32) and UE3 (u64). Anything not mapped is shown as hex.
	private static array $NAME_FLAG_HINTS = [
		0x0000000000000001 => 'NAME_None',            // reserved name
		0x0000000000000008 => 'Intrinsic',            // hardcoded / engine-defined
		0x0000000000000010 => 'LoadedFromPackage',    // initialized from package load
		// Higher bits are intentionally unspecified; different tools used them ad-hoc.
	];
/*
	public function __construct(string $path)
    {
        if (!is_file($path)) 
			throw new \InvalidArgumentException("File not found: $path");
		
        $this->path = $path;
        $bytes      = file_get_contents($path);
		
        if ($bytes === false) 
			throw new \RuntimeException("Failed to read file: $path");				
		
        $this->R = new UEReader($bytes);
        $this->readHeader();
        $this->R->setVersion($this->header['version'] ?? 0);
        //$this->readCompressionHeaderIfAny();
        $this->readNameTable();
		
		if ($this->isUE3Package()) {
			$this->readImportTableUE3(); // fills $this->imports with UE3-safe shape
			// and when printing:
			//$name  = $this->importObjectNameUE3($i);
			//$cls   = $this->importClassNameUE3($i);
			//$pkg   = $this->importClassPackageNameUE3($i);
		} else {
			// keep your existing UE1/UE2 calls
			$this->readImportTable();
			// your old accessors here ...
		}
				
        $this->readExportTable();
		$this->readAllExportProperties();
		$this->resolveImportOuters();
		$this->resolveExportRefs();		
    }
	
	
	*/
	
	
public function __construct(string $path)
{
    if (!is_file($path)) {
        throw new \InvalidArgumentException("File not found: $path");
    }

    $this->path  = $path;
    $bytes       = file_get_contents($path);
    if ($bytes === false) {
        throw new \RuntimeException("Failed to read file: $path");
    }

    $this->R = new UEReader($bytes);

    // Header: must populate version, counts/offsets, compression flags/chunks, etc.
    $this->readHeader();

    if (method_exists($this->R, 'setVersion')) {
        $this->R->setVersion((int)($this->header['version'] ?? 0));
    }

    // Pass 1: raw tables only (store top-level + ['raw'])
    $this->readNames();
    $this->readImports();
    $this->readExportTable();   // internally dispatches to UE3 reader for UE3

    // Pass 2: resolve and attach ['view'] now that all tables are present
    if (method_exists($this, 'finalizeViews')) {
        $this->finalizeViews();
    }

    // Optional legacy steps; safe now that arrays exist
    if (method_exists($this, 'readAllExportProperties')) {
        $this->readAllExportProperties(); // ensure it uses exportBodyReader() for UE3
    }
    if (method_exists($this, 'resolveImportOuters')) {
        $this->resolveImportOuters();
    }
    if (method_exists($this, 'resolveExportRefs')) {
        $this->resolveExportRefs();
    }
}


	
private function readNames(): void
{
    $count  = (int)($this->header['nameCount']  ?? 0);
    $offset = (int)($this->header['nameOffset'] ?? 0);

    $this->names = [];
    if ($count <= 0 || $offset <= 0) return;

    $ver = (int)($this->header['version'] ?? 0);
    // UE3 tables must be read from the logical UNCOMPRESSED space when compressed
    $R = ($ver >= 334 && $this->isCompressed && !empty($this->chunks))
        ? $this->makeConcatUncompressedReader()
        : $this->R;

    $save = $R->tell();
    $R->seek($offset);

    for ($i = 0; $i < $count; $i++) {
        // FString exactly like C++: int32 len, +ve ANSI, -ve UTF-16LE
        $len = $R->i32();
        if ($len === 0) {
            $nameStr = '';
        } elseif ($len > 0) {
            $nameStr = $R->bytes($len);
            if ($nameStr !== '' && $nameStr[strlen($nameStr)-1] === "\0") {
                $nameStr = substr($nameStr, 0, -1);
            }
        } else {
            $wlen  = -$len;
            $bytes = $R->bytes($wlen * 2);
            // convert and trim trailing wide NUL
            $nameStr = function_exists('iconv')
                ? @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes)
                : '';
            if ($nameStr === false) $nameStr = '';
        }

        // Flags (UE1/UE2/UE3): often 32 bits (some tools store hi/lo; we keep a single u32 here)
        $flags = $R->u32();

        // RAW
        $raw = ['name' => $nameStr, 'flags' => $flags];
        $row = $raw; $row['raw'] = $raw;
        $this->names[] = $row;
    }

    $R->seek($save);
}



private function readImports(): void
{
    $count  = (int)($this->header['importCount']  ?? 0);
    $offset = (int)($this->header['importOffset'] ?? 0);

    $this->imports = [];
    if ($count <= 0 || $offset <= 0) return;

    $ver = (int)($this->header['version'] ?? 0);
    $R = ($ver >= 334 && $this->isCompressed && !empty($this->chunks))
        ? $this->makeConcatUncompressedReader()
        : $this->R;

    $save = $R->tell();
    $R->seek($offset);

    for ($i = 0; $i < $count; $i++) {
        // FObjectImport (UE1/UE2/UE3): ClassPackage, ClassName, OuterIndex, ObjectName
        $classPackage = $R->i32(); // FName index
        $className    = $R->i32(); // FName index
        $outerIndex   = $R->i32(); // signed package index
        $objectName   = $R->i32(); // FName index

        $raw = [
            'classPackage' => $classPackage,
            'className'    => $className,
            'outerIndex'   => $outerIndex,
            'objectName'   => $objectName,
        ];
        $row = $raw; $row['raw'] = $raw;
        $this->imports[] = $row;
    }

    $R->seek($save);
}


/** Best-effort: bytes remaining in current reader bounds, or a large sentinel. */
private function readerRemaining($R): int
{
    // Try common methods on your UEReader
    if (method_exists($R, 'remaining')) return (int)$R->remaining();
    if (method_exists($R, 'left'))      return (int)$R->left();
    if (method_exists($R, 'tell') && method_exists($R, 'limit')) {
        return max(0, (int)$R->limit() - (int)$R->tell());
    }
    if (method_exists($R, 'tell') && method_exists($R, 'end')) {
        return max(0, (int)$R->end() - (int)$R->tell());
    }
    // Fallback: very large to avoid false negatives
    return 1 << 30; // ~1GB
}

/** Is an FString length plausible given remaining bytes? */
private function isPlausibleFStringLen(int $len, int $remain): bool
{
    // Names are short. Use conservative caps to reject bogus endian reads.
    // Positive: ANSI length must fit in remaining and under 8 MB
    if ($len > 0)  return $len <= $remain && $len <= 8 * 1024 * 1024;
    if ($len < 0) {
        $need = (-$len) * 2;                // UTF-16LE bytes required
        return $need <= $remain && $need <= 8 * 1024 * 1024;
    }
    return true; // zero is always fine
}


/**
 * Read an Unreal FString exactly as stored:
 *   int32 Len; 0 => "";  Len>0 => ANSI bytes (often with trailing NUL);
 *   Len<0 => UTF-16LE bytes (often with trailing wide NUL).
 */
/**
 * Defensive FName/FString reader for UE1/UE2/UE3.
 * - Reads 4 raw bytes for length
 * - Interprets both LE and BE
 * - Chooses the plausible one (fits remaining and sane limits)
 * - Positive: ANSI bytes; Negative: UTF-16LE code units
 */
private function readFStringCompat($R): string
{
    // Read length bytes raw
    $lenBytes = $R->bytes(4);
    $uLE = unpack('V', $lenBytes)[1]; // little-endian unsigned
    $uBE = unpack('N', $lenBytes)[1]; // big-endian unsigned

    // Convert to signed
    $sLE = ($uLE & 0x80000000) ? ($uLE - 0x100000000) : $uLE;
    $sBE = ($uBE & 0x80000000) ? ($uBE - 0x100000000) : $uBE;

    // How many bytes remain in this reader's bounds (best-effort)
    $remain = $this->readerRemaining($R);

    // Plausibility checks
    $okLE = $this->isPlausibleFStringLen($sLE, $remain);
    $okBE = $this->isPlausibleFStringLen($sBE, $remain);

    // Pick the plausible one (prefer LE if both plausible)
    $len = $okLE ? $sLE : ($okBE ? $sBE : $sLE);

    if ($len === 0) return '';

    if ($len > 0) {
        // ANSI bytes (often with trailing NUL)
        $bytes = $R->bytes($len);
        if ($bytes !== '' && $bytes[strlen($bytes)-1] === "\0") {
            $bytes = substr($bytes, 0, -1);
        }
        return $bytes;
    }

    // UTF-16LE (negative length = number of wchar_t code units)
    $wlen  = -$len;
    $bytes = $R->bytes($wlen * 2);
    if ($wlen > 0 && substr($bytes, -2) === "\0\0") {
        $bytes = substr($bytes, 0, -2);
    }

    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16LE');
    }
    if (function_exists('iconv')) {
        $out = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
        return $out !== false ? $out : '';
    }
    // Last resort: naive low-byte extraction
    $out = '';
    for ($i = 0, $n = strlen($bytes); $i + 1 < $n; $i += 2) $out .= $bytes[$i];
    return $out;
}









		
	// NEW: read UE-style FName (index, number) and resolve to text; used only in UE3 property tags
	private function _readFNameStruct(UEReader $R): array
	{
		$nameIdx = $R->i32();
		$number  = $R->i32();
		$base    = $this->nameStr($nameIdx);         // your existing nameStr(int)
		$text    = ($number > 0) ? ($base . '_' . $number) : $base;
		return ['index'=>$nameIdx, 'number'=>$number, 'base'=>$base, 'text'=>$text];
	}
	/** Resolve a “name-like” value to text safely:
	 *  - int    => look up in Name table (UE1/UE2)
	 *  - string => use as-is (UE3 code that already resolved it)
	 *  - array  => UE3 FName struct (prefer ['text'] then ['base'] then ['name'])
	 */
	private function _nameLikeToText($val): string {
		if (is_int($val))   return $this->nameStr($val);
		if (is_string($val)) return $val;
		if (is_array($val)) {
			if (isset($val['text']) && $val['text'] !== '') return (string)$val['text'];
			if (isset($val['base']) && $val['base'] !== '') return (string)$val['base'];
			if (isset($val['name']) && $val['name'] !== '') return (string)$val['name'];
		}
		return '';
	}

	// Pick first present key from a row
	private function _pick(array $row, array $keys) {
		foreach ($keys as $k) {
			if (array_key_exists($k, $row)) return $row[$k];
		}
		return null;
	}


	// Returns how many bytes remain in this reader, or a large number if unknown.
	private function _rem(UEReader $R): int
	{
		if (method_exists($R, 'remaining')) {
			return (int)$R->remaining();
		}
		if (method_exists($R, 'length') && method_exists($R, 'tell')) {
			return (int)$R->length() - (int)$R->tell();
		}
		// Fallback if UEReader doesn't expose length/tell; callers will still try/catch reads.
		return PHP_INT_MAX;
	}

	// Always return a valid PackageIndex (int) from an import/export row, default 0
	public function _outerPkgIndex(array $row): int
	{	// Accept many spellings that appear in UE1/UE2/UE3 tools
		$v = $this->_pick($row, ['OuterIndex','outerIndex','PackageIndex','packageIndex','outer','Outer','outerRef','OuterRef']);
		
		return is_numeric($v) ? (int)$v : 0;
	}

	// ---- UE3-ONLY helpers. Keep your legacy UE1/UE2 helpers untouched. ----

	// Resolve a UE3 "name-like" value to text.
	// Accepts: FName struct ['text'|'base'|'name'], string, or legacy int index.
	private function _ue3NameText($val): string {
		if (is_array($val)) {
			if (!empty($val['text'])) return (string)$val['text'];
			if (!empty($val['base'])) return (string)$val['base'];
			if (!empty($val['name'])) return (string)$val['name'];
		}
		if (is_string($val)) return $val;
		if (is_int($val))    return $this->nameStr($val); // legacy shape still works
		return '';
	}

	// Get UE3 "outer" package index from a row (import/export), tolerant to key spellings.
	private function _ue3OuterIndex(array $row): int {
		foreach (['OuterIndex','outerIndex','PackageIndex','packageIndex','outer','Outer'] as $k) {
			if (array_key_exists($k, $row) && is_numeric($row[$k])) return (int)$row[$k];
		}
		return 0;
	}

	// UE3: resolve a PackageIndex (0 / <0 import / >0 export) to a display name.
	// This does NOT affect your legacy displayNameFromRef().
	/*private function displayNameFromRefUE3(int $ref): string
	{
		if ($ref === 0) return '';

		if ($ref > 0) {
			$e = $ref - 1;
			if (!isset($this->exports[$e])) return "__BADEXPORT[$e]__";
			$ex = $this->exports[$e];
			$val = $ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? ($ex['objectName'] ?? null));
			return $this->_ue3NameText($val);
		} else {
			$i = -$ref - 1;
			if (!isset($this->imports[$i])) return "__BADIMPORT[$i]__";
			$im = $this->imports[$i];
			$val = $im['ObjectName'] ?? ($im['ObjectNameText'] ?? ($im['objectName'] ?? null));
			return $this->_ue3NameText($val);
		}
	}
	*/
	/*
	// UE3: build full outer path. (Legacy groupPathFromRef() remains untouched.)
	private function groupPathFromRefUE3(int $ref): string
	{
		$parts = [];
		
		while ($ref !== 0) {
			if ($ref > 0) {
				$e = $ref - 1;
				if (!isset($this->exports[$e])) break;
				$ex = $this->exports[$e];
				$val = $ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? ($ex['objectName'] ?? null));
				$name = $this->_ue3NameText($val);
				if ($name !== '') $parts[] = $name;
				$ref = $this->_ue3OuterIndex($ex);
			} else {
				$i = -$ref - 1;
				if (!isset($this->imports[$i])) break;
				$im = $this->imports[$i];
				$val = $im['ObjectName'] ?? ($im['ObjectNameText'] ?? ($im['objectName'] ?? null));
				$name = $this->_ue3NameText($val);
				if ($name !== '') $parts[] = $name;
				$ref = $this->_ue3OuterIndex($im);
			}
		}
		return implode('.', array_reverse($parts));
	}
	*/
	/*
	// UE3: resolve a class PackageIndex to its class name (for Exports “Class Index” column).
	private function exportClassNameUE3(int $classRef): string
	{
		if ($classRef === 0) return '';
		if ($classRef < 0) {
			$i = -$classRef - 1;
			if (!isset($this->imports[$i])) return "__BADIMPORT[$i]__";
			$im = $this->imports[$i];
			// In UE3 imports, the class name is also a FName
			$val = $im['ClassName'] ?? ($im['ClassNameText'] ?? ($im['className'] ?? null));
			return $this->_ue3NameText($val);
		} else {
			$e = $classRef - 1;
			if (!isset($this->exports[$e])) return "__BADEXPORT[$e]__";
			$ex = $this->exports[$e];
			$val = $ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? ($ex['objectName'] ?? null));
			return $this->_ue3NameText($val);
		}
	}
	*/

	/** Return aligned length: round up to next multiple of $a (a>=1). */
	private static function alignUp(int $v, int $a): int {
		return ($a > 1) ? (($v + ($a - 1)) & ~($a - 1)) : $v;
	}
	

	/**
	 * Build a single contiguous buffer representing the package's
	 * virtual UNCOMPRESSED address space: [raw prefix][decompressed chunks...].
	 * This makes virtual offset 0 == uncompressed offset 0.
	 *
	 * Leaves existing behavior intact; only fixes alignment.
	 */
	private function buildConcatUncompressed(): void
	{
		if (isset($this->concatBuf) && is_string($this->concatBuf) && $this->concatBuf !== '') {
			return;
		}

		if (!$this->isCompressed || empty($this->chunks)) {
			$this->R->seek(0);
			$this->concatBuf = $this->R->bytes($this->R->length());
			return;
		}

		$R       = $this->R;
		$fileLen = $R->length();

		// Derive the first compressed byte in the file (minimum cOff as read)
		$firstCompressedOff = PHP_INT_MAX;
		
		foreach ($this->chunks as $ch) {
			if (isset($ch['cOff'])) {
				$firstCompressedOff = min($firstCompressedOff, (int)$ch['cOff']);
			}
		}
		
		if ($firstCompressedOff === PHP_INT_MAX) {
			// If not provided, fall back to a reasonable minimum (end of header region)
			$firstCompressedOff = (int)($this->header['headerSize'] ?? 0);
		}

		// Normalize all chunks
		$norm = [];
		
		foreach ($this->chunks as $i => $ch) {
			$tuple = [$ch['uOff'] ?? 0, $ch['uLen'] ?? 0, $ch['cOff'] ?? 0, $ch['cLen'] ?? 0];
			$norm[] = $this->normalizeChunkQuad($tuple, $fileLen, $firstCompressedOff);
		}

		// Sort by uncompressed offset
		usort($norm, fn($a,$b) => $a['uOff'] <=> $b['uOff']);

		// Prefix: copy RAW bytes from file start up to first compressed offset (virtual should match)
		$buf = '';
		$prefixRawLen = max(0, min($firstCompressedOff, $norm[0]['uOff']));
		
		if ($prefixRawLen > 0) {
			$R->seek(0);
			$buf .= $R->bytes($prefixRawLen);
		}

		// Append each decompressed chunk in uncompressed order
		$expectedNextU = $prefixRawLen;
		
		foreach ($norm as $i => $ch) {
			$uOff = (int)$ch['uOff'];
			$uLen = (int)$ch['uLen'];
			$cOff = (int)$ch['cOff'];
			$cLen = (int)$ch['cLen'];

			if ($uLen <= 0 || $cLen <= 0) {
				throw new \RuntimeException("Bad chunk lengths at #$i (uLen=$uLen, cLen=$cLen)");
			}
			
			if ($cOff < 0 || $cOff + $cLen > $fileLen) {
				throw new \RuntimeException("Bad chunk file range at #$i (cOff=$cOff, cLen=$cLen, fileLen=$fileLen)");
			}

			// Fill any virtual gap conservatively with zeros (rare)
			if ($uOff > $expectedNextU) {
				$buf .= str_repeat("\x00", $uOff - $expectedNextU);
				$expectedNextU = $uOff;
			} elseif ($uOff < $expectedNextU) {
				throw new \RuntimeException("Overlapping uncompressed offsets at #$i (uOff=$uOff < expected=$expectedNextU)");
			}

			// Read compressed bytes from the correct file offset
			$R->seek($cOff);
			$cData = $R->bytes($cLen);
			// Decompress using header sniffing + flags
			$uData = $this->decompressUE3Chunk($cData, (int)$this->compressionFlags);

			if (strlen($uData) !== $uLen) {
				throw new \RuntimeException(
					"Chunk #$i decompressed length mismatch: got ".strlen($uData)." expected $uLen (cOff=$cOff, cLen=$cLen)"
				);
			}

			$buf .= $uData;
			$expectedNextU = $uOff + $uLen;
		}

		$this->concatBuf = $buf;
	}
	/** Build and cache the concatenated uncompressed buffer and a mapping table.
	 *  concatBuf: chunk0||chunk1||... (size = sum(uLen))
	 *  offsetMap: [ ['uOff'=>u0, 'uLen'=>l0, 'cum'=>0],
	 *               ['uOff'=>u1, 'uLen'=>l1, 'cum'=>l0], ... ]
	 */
	private function buildUncompressedConcat(): void
	{
		if (!$this->isCompressed) 
			return;
		
		if (isset($this->concatBuf)) 
			return; // already built

		$total = 0;
		
		foreach ($this->chunks as $c) 
			$total += (int)$c['uLen'];
			
		$buf = '';
		$map = [];
		$cum = 0;
		
		foreach ($this->chunks as $idx => $c) {
			$this->ensureChunkLoaded($idx); // fills chunkCache[$idx]
			$block = $this->chunkCache[$idx];
			// Defensive: trim/pad each block to advertised uLen
			$uLen = (int)$c['uLen'];
			
			if (strlen($block) > $uLen) 
				$block = substr($block, 0, $uLen);
			
			elseif (strlen($block) < $uLen) 
				$block = str_pad($block, $uLen, "\x00");

			$map[] = ['uOff' => (int)$c['uOff'], 'uLen' => $uLen, 'cum' => $cum];
			$buf  .= $block;
			$cum  += $uLen;
		}

		$this->concatBuf  = $buf;
		$this->offsetMap  = $map;
		$this->concatSize = $cum;
	}
	/** Pick whichever header permutation passes basic sanity. */
	private function chooseValidChunkHeader(array $A, array $B, int $bufLen): array
	{
		$isReasonable = function(array $h) use ($bufLen): bool {
			if ($h['blockSize'] <= 0 || $h['blockSize'] > (1<<20)) return false; // up to 1 MiB
			if ($h['blockCount'] <= 0 || $h['blockCount'] > 10000) return false;
			$need = $h['tableOfs'] + $h['blockCount'] * 8;
			if ($need > $bufLen) return false;
			// if totals present, ensure they're not absurd
			if (!empty($h['compTotal'])   && ($h['compTotal']   > $bufLen)) return false;
			if (!empty($h['uncompTotal']) && ($h['uncompTotal'] > (256<<20))) return false; // 256 MiB cap
			return true;
		};

		$aOk = $isReasonable($A);
		$bOk = $isReasonable($B);

		if ($aOk && !$bOk) return $A;
		if ($bOk && !$aOk) return $B;
		if ($aOk && $bOk) {
			// Prefer the one whose uncompressed total is closer to blockCount*blockSize (heuristic)
			$aDelta = isset($A['uncompTotal']) ? abs(($A['blockCount'] * $A['blockSize']) - $A['uncompTotal']) : PHP_INT_MAX;
			$bDelta = isset($B['uncompTotal']) ? abs(($B['blockCount'] * $B['blockSize']) - $B['uncompTotal']) : PHP_INT_MAX;
			return ($aDelta <= $bDelta) ? $A : $B;
		}

		// Neither looks sane → fall back to assume A but caller will likely fail with clearer message
		return $A;
	}
	/**
	 * Compute vertex and wedge normals for a LodMesh geo block.
	 * geo must be the result of readLodMeshGeometry().
	 *
	 * Returns ['vertexNormals'=>[ [x,y,z], ... ],
	 *          'wedgeNormals' =>[ [x,y,z], ... ] ]
	 */
	public function computeLodMeshNormals(array $geo): array {
		$P     = $geo['Points']    ?? [];
		$W     = $geo['Wedges']    ?? [];
		$F     = $geo['Faces']     ?? [];
		$pn    = count($P);
		$vn    = array_fill(0, $pn, [0.0,0.0,0.0]); // vertex normal accumulators
		// Helper closures
		$add   = function(array &$a, array $b): void { $a[0]+=$b[0]; $a[1]+=$b[1]; $a[2]+=$b[2]; };
		$sub   = fn(array $a, array $b) => [$a[0]-$b[0], $a[1]-$b[1], $a[2]-$b[2]];
		$cross = fn(array $a, array $b) => [
			$a[1]*$b[2]-$a[2]*$b[1],
			$a[2]*$b[0]-$a[0]*$b[2],
			$a[0]*$b[1]-$a[1]*$b[0]
		];
		
		$norm = function(array $v): array {
			$len = sqrt($v[0]*$v[0]+$v[1]*$v[1]+$v[2]*$v[2]);
			
			if ($len > 1e-12) { $inv = 1.0/$len; 
				return [$v[0]*$inv,$v[1]*$inv,$v[2]*$inv]; 
			}
			
			return [0.0,0.0,1.0]; // fallback
		};

		// Accumulate face normals to each involved vertex (via wedge -> vertexIndex)
		foreach ($F as $tri) {
			$w1 = $tri['w1'] ?? null; 
			$w2 = $tri['w2'] ?? null; 
			$w3 = $tri['w3'] ?? null;
			
			if ($w1===null || $w2===null || $w3===null) 
				continue;
			
			if (!isset($W[$w1], $W[$w2], $W[$w3])) 
				continue;

			$i1 = (int)$W[$w1]['vertexIndex'];
			$i2 = (int)$W[$w2]['vertexIndex'];
			$i3 = (int)$W[$w3]['vertexIndex'];
			
			if (!isset($P[$i1], $P[$i2], $P[$i3])) 
				continue;

			$p1 = $P[$i1]; 
			$p2 = $P[$i2]; 
			$p3 = $P[$i3];
			$e1 = $sub($p2, $p1);
			$e2 = $sub($p3, $p1);

			// Face normal (right-hand): n = normalize(cross(e1, e2))
			$fn = $norm($cross($e1, $e2));

			// Accumulate to vertex normals
			$add($vn[$i1], $fn);
			$add($vn[$i2], $fn);
			$add($vn[$i3], $fn);
		}

		// Normalize accumulators
		foreach ($vn as $k => $acc) { 
			$vn[$k] = $norm($acc); 
		}

		// Per-wedge normals (copy the vertex normal they reference)
		$wn = [];
		
		foreach ($W as $w) {
			$vi = (int)$w['vertexIndex'];
			$wn[] = $vn[$vi] ?? [0.0,0.0,1.0];
		}

		return ['vertexNormals'=>$vn, 'wedgeNormals'=>$wn];
	}

	/** Strict per-buffer decompressor by magic sniff + flags. Returns string on success, false on failure. */
	// Generic sniff; DO NOT consume UE3 headered chunks here.
	private function decompressBySniff(string $buf, int $flags)
	{
		if (strlen($buf) >= 4 && $this->u32_from($buf,0) === 0x9E2A83C1) return false;

		$b0 = ord($buf[0] ?? "\x00"); $b1 = ord($buf[1] ?? "\x00");

		// gzip?
		if ($b0===0x1F && $b1===0x8B) {
			if (function_exists('gzdecode')) { $u=@gzdecode($buf); if($u!==false) return $u; }
			$u=@gzuncompress($buf); if($u!==false) return $u;
			$u=@gzinflate($buf);    if($u!==false) return $u;
		}

		// deflate/zlib?
		$u=@gzinflate($buf); if($u!==false) return $u;
		$u=@gzuncompress($buf); if($u!==false) return $u;
		if (function_exists('zlib_decode')) { $u=@zlib_decode($buf); if($u!==false) return $u; }

		// LZO single-blob (rare but seen)
		if (($flags & 0x02) || ($flags & 0x40)) {
			if (!function_exists('lzo1x_decompress')) {
				throw new \RuntimeException("UT3/UE3 requires LZO (flags=0x".dechex($flags).") but php-lzo is not available.");
			}
			$u = @lzo1x_decompress($buf);
			if ($u !== false && $u !== null) return $u;
		}

		return false;
	}
	// Decode Name-table flags using a tiny hint table.
	// $isUE3 controls width for pretty hex (64-bit vs 32-bit).
	private function decodeNameFlags(int $flags, bool $isUE3): array
	{
		$labels = [];
		$knownMask = 0;

		foreach (self::$NAME_FLAG_HINTS as $bit => $label) {
			if (($flags & $bit) !== 0) {
				$labels[] = $label;
				$knownMask |= $bit;
			}
		}

		$unknown = $flags & (~$knownMask);

		// If there are unknown bits, append a compact hex marker.
		if ($unknown !== 0) {
			$labels[] = $isUE3
				? ('+' . '0x' . str_pad(dechex($unknown), 16, '0', STR_PAD_LEFT))
				: ('+' . '0x' . str_pad(dechex($unknown), 8,  '0', STR_PAD_LEFT));
		}

		return $labels; // e.g., ['Intrinsic','LoadedFromPackage','+0x0000000000000100']
	}
	/*
	[magic][blockSize][compTotal][uncompTotal][ (c,u) * n ][ payload bytes… ]
	C1 83 2A 9E   // magic
	00 00 02 00   // BlockSize = 0x20000 (131,072)
	62 48 03 00   // CompressedTotal = 215,138
	C6 FE 0F 00   // UncompressedTotal = 1,048,262
	... then 8 pairs of (CompressedSize, UncompressedSize), e.g.
	BE 5D 00 00 | 00 00 02 00   // 23998, 131072
	31 60 00 00 | 00 00 02 00   // 24625, 131072
		
		 * Decompress a single UE3 compressed CHUNK (the region at cOff..cOff+cLen).
		 * UE3 packs a header + block table at the start of the chunk, then block payloads.
		 * This function parses that wrapper and inflates each block in order.
		 */
		/**
	 * UE3 headered chunk decompressor.
	 * Layout:
	 *   u32 magic = 0x9E2A83C1
	 *   u32 blockSize
	 *   u32 compTotal
	 *   u32 uncompTotal
	 *   repeat:
	 *      u32 cSize, u32 uSize
	 *     ... until sum(cSize) == compTotal
	 *   [payload bytes for each block in order...]
	 */
	// Decompress a UE3 headered chunk (0x9E2A83C1). Supports LZO (0x02/0x40) and Zlib (0x10).
	private function decompressUE3Chunk(string $chunk, int $flags): string
	{
		if (strlen($chunk) < 16) throw new \RuntimeException("UE3 chunk too small");

		$off   = 0;
		$magic = $this->u32_from($chunk, $off); $off += 4;
		if ($magic !== 0x9E2A83C1) throw new \RuntimeException("UE3 chunk bad magic: 0x".dechex($magic));

		$blockSize   = $this->u32_from($chunk, $off); $off += 4;
		$compTotal   = $this->u32_from($chunk, $off); $off += 4;
		$uncompTotal = $this->u32_from($chunk, $off); $off += 4;

		if ($blockSize === 0 || $compTotal === 0 || $uncompTotal === 0) {
			throw new \RuntimeException("UE3 chunk header invalid (zeros)");
		}

		// Table: (cSize,uSize) until sumC == compTotal
		$pairs = []; $sumC = 0; $sumU = 0; $len = strlen($chunk);
		while ($sumC < $compTotal) {
			if ($off + 8 > $len) throw new \RuntimeException("UE3 chunk table truncated");
			$c = $this->u32_from($chunk, $off);
			$u = $this->u32_from($chunk, $off + 4);
			$off += 8;
			if ($c <= 0 || $u < 0) throw new \RuntimeException("UE3 chunk table entry invalid (c=$c,u=$u)");
			$pairs[] = [$c,$u];
			$sumC += $c; $sumU += $u;
			if ($u > $blockSize && $sumU < $uncompTotal) {
				throw new \RuntimeException("UE3 block ulen > blockSize");
			}
		}

		$useLzo  = (($flags & 0x02) !== 0) || (($flags & 0x40) !== 0);
		$useZlib = (($flags & 0x10) !== 0);
		if (!$useLzo && !$useZlib) $useZlib = true; // pragmatic fallback seen in the wild
		if ($useLzo && !function_exists('lzo1x_decompress')) {
			throw new \RuntimeException("LZO required but lzo1x_decompress is unavailable");
		}

		$pos = $off; $out = '';
		foreach ($pairs as [$c,$u]) {
			if ($pos + $c > $len) throw new \RuntimeException("UE3 block overruns chunk data");
			$payload = substr($chunk, $pos, $c);
			$pos += $c;

			if ($useLzo) {
				$dec = @lzo1x_decompress($payload, $u);
				if ($dec === false || $dec === null) throw new \RuntimeException("LZO decompress failed (c=$c,u=$u)");
			} else {
				$dec = @gzinflate($payload);
				if ($dec === false) $dec = @gzuncompress($payload);
				if ($dec === false && function_exists('zlib_decode')) $dec = @zlib_decode($payload);
				if ($dec === false) throw new \RuntimeException("Zlib decompress failed (c=$c,u=$u)");
			}

			// match block's expected uncompressed size
			$dl = strlen($dec);
			if     ($dl > $u) $dec = substr($dec, 0, $u);
			elseif ($dl < $u) $dec = str_pad($dec, $u, "\x00");
			$out .= $dec;
		}

		// Final clamp
		if (strlen($out) > $uncompTotal)      $out = substr($out, 0, $uncompTotal);
		elseif (strlen($out) < $uncompTotal)  $out = str_pad($out, $uncompTotal, "\x00");

		return $out;
	}
	// In UnrealPackageReader (private helper)
	// Try zlib first (raw deflate then zlib-wrapped), then LZO if available.
	// Clamp/pad to expected length when provided.
	private function decompressBlock(string $blk, int $expectedLen): string
	{
		// If package flags advertise LZO and PHP can't do LZO, fail loudly
		$flags  = (int)($this->compressionFlags ?? 0);
		$hasZ   = (($flags & 0x01) !== 0); // ZLIB
		$hasLZO = (($flags & 0x02) !== 0); // LZO

		// Try zlib first (some chunks can still be zlib even when LZO is advertised)
		$raw = @gzinflate($blk);
		
		if ($raw === false) 
			$raw = @gzuncompress($blk);
		
		if ($raw !== false) {
			if ($expectedLen > 0) {
				$len = strlen($raw);
				
				if     ($len > $expectedLen) 
					$raw = substr($raw, 0, $expectedLen);
				
				elseif ($len < $expectedLen) 
					$raw = str_pad($raw, $expectedLen, "\x00");
			}
			
			return $raw;
		}

		// If LZO is likely needed, enforce the dependency.
		if ($hasLZO && !function_exists('lzo1x_decompress')) {
			throw new \RuntimeException(
				"UT3/UE3 file requires LZO decompression (compressionFlags={$flags}) " .
				"but PHP LZO is not available. Install an LZO decompressor (e.g., php-lzo) " .
				"or use a loader that supports LZO."
			);
		}

		if ($hasLZO) {
			$raw = lzo1x_decompress($blk, max(0, (int)$expectedLen));
			
			if ($raw !== false) {
				if ($expectedLen > 0) {
					$len = strlen($raw);
					if     ($len > $expectedLen) 
						$raw = substr($raw, 0, $expectedLen);
					elseif ($len < $expectedLen) 
						$raw = str_pad($raw, $expectedLen, "\x00");
				}
				
				return $raw;
			}
		}

		throw new \RuntimeException("Failed to decompress block with zlib or LZO (flags={$flags})");
	}
	public function decodeRF(int $flags): array {
			$out = [];
			
			foreach (self::RF_FLAGS as $bit => $name) {
				if (($flags & $bit) === $bit) 
					$out[] = $name;
			}
			
			return $out;
		}	

		public function decodePKG(int $flags): array {
			$out = [];
			
			foreach (self::PKG_FLAGS as $bit => $name) 
				if ($flags & $bit) 
					$out[] = $name;
				
			return $out;
		}

	/** Convenience: walk an 'outer' chain just once for display (group/package) */
	public function displayOuterNameFromRef(int $idx): string {
		$r = $this->resolveObjectRef($idx);
		
		return $r['name'] ?? '';
	}

	/** Build rows for the Export table exactly like your sample */


	/** Try to resolve an import into an external package and read its properties.
	 * Best-effort: looks for a sibling file named after the top-level package (any known Unreal extension).
	 * Returns ['status'=>'ok','path'=>..., 'exportIndex'=>int, 'props'=>array] on success, or ['status'=>'error','reason'=>...] */
	 /*
	public function resolveImportProperties(int $importIndex, ?string $searchDir=null, array $exts=['.u','.utx','.uax','.umx','.ukx','.usx','.unr']): array {
		if (!isset($this->imports[$importIndex])) 
			return ['status'=>'error','reason'=>'invalid-import-index'];
		
		$im       = $this->imports[$importIndex];
		// Follow outer chain to find the root package name
		$outer    = $im['outerIndex'] ?? 0; // DWORD object ref
		$seen     = 0;
		$rootName = null;
		
		while ($outer < 0 && $seen < 16) {
			$seen++;
			$j = -$outer - 1;
			
			if (!isset($this->imports[$j])) 
				break;
			
			$rootName = $this->nameByIndex($this->imports[$j]['objectName']) ?? $rootName;
			$outer    = $this->imports[$j]['outerIndex'] ?? 0;
		}
		
		if (!$rootName) {
			$rootName = $this->nameByIndex($im['objectName']) ?? null;
		}
		
		if (!$rootName) 
			return ['status'=>'error','reason'=>'no-root-name'];
		
		$baseDir    = $searchDir ?: dirname($this->path);
		$candidates = [];
		
		foreach ($exts as $ext) {
			$candidates[] = $baseDir . DIRECTORY_SEPARATOR . $rootName . $ext;
		}
		
		$targetPath = null;
		
		foreach ($candidates as $cand) {
			if (@is_file($cand)) { 
				$targetPath = $cand; 
				break; 
			}
		}
		
		if (!$targetPath) 
			return ['status'=>'error','reason'=>'package-not-found','package'=>$rootName,'candidates'=>$candidates];
		
		try {
			$other = new self($targetPath);
		} catch (\Throwable $e) {
			return ['status'=>'error','reason'=>'open-failed','message'=>$e->getMessage(),'path'=>$targetPath];
		}
		
		$wantName = $this->nameByIndex($im['objectName']) ?? null;
		
		if (!$wantName) 
			return ['status'=>'error','reason'=>'no-object-name'];
		
		$matchIndex = null;
		
		foreach ($other->getExportsRaw() as $k=>$ex) {
			$nm = $other->nameByIndex($ex['objectName'] ?? null);
			
			if ($nm === $wantName) { 
				$matchIndex = $k; 
				break; 
			}
		}
		
		if ($matchIndex === null) {
			return ['status'=>'error','reason'=>'export-not-found-in-external','path'=>$targetPath,'name'=>$wantName];
		}
		
		$props = $other->getExportProperties($matchIndex);
		
		return ['status'=>'ok','path'=>$targetPath,'exportIndex'=>$matchIndex,'props'=>$props];
	}
	*/
	





	
	//.public function exportClassName(int $ref): string;    // raw object ref (INDEX): classIndex
	//.public function exportSuperName(int $ref): string;    // raw object ref (INDEX): superIndex
	//.public function exportPackageName(int $ref): string;  // raw object ref (DWORD/INDEX): packageIndex → group path
	//.public function exportObjectName(int $nameIx): string;// name table index (INDEX): objectName
	// --- helpers (keep private) ----------------------------------------------
	/** Resolve a raw object ref (0/+N/−N) to its display name (imports/exports). */
	/** Resolve a raw object ref (0/+N/−N) to a display name (imports/exports).
	 *  Keeps UE1/UE2 behaviour (int indices) AND supports UE3 (string/FName struct).
	 */
	public function displayNameFromRef(int $ref): string
	{
		if ($ref === 0) return '';

		// EXPORT (+)
		if ($ref > 0) {
			$ix = $ref - 1;
			if (!isset($this->exports[$ix])) return "__BADEXPORT[$ix]__";
			$ex = $this->exports[$ix];

			// Accept all common name fields (UE1/UE2 int index; UE3 string/FName)
			$val = $ex['objectName'] ?? ($ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? null));

			// If UE1/UE2 kept an index:
			if (is_int($val)) return $this->nameStr($val);

			// UE3 / already-resolved
			if (is_array($val)) {
				if (!empty($val['text'])) return (string)$val['text'];
				if (!empty($val['base'])) return (string)$val['base'];
				if (!empty($val['name'])) return (string)$val['name'];
			}
			if (is_string($val)) return $val;

			return '';
		}

		// IMPORT (−)
		$ix = (-$ref) - 1;
		if (!isset($this->imports[$ix])) return "__BADIMPORT[$ix]__";
		$im = $this->imports[$ix];

		$val = $im['objectName'] ?? ($im['ObjectName'] ?? ($im['ObjectNameText'] ?? null));

		if (is_int($val)) return $this->nameStr($val);
		if (is_array($val)) {
			if (!empty($val['text'])) return (string)$val['text'];
			if (!empty($val['base'])) return (string)$val['base'];
			if (!empty($val['name'])) return (string)$val['name'];
		}
		if (is_string($val)) return $val;

		return '';
	}


	
	public function decodePropertyFlags(int $flags): array {
		$out = [];
		
		foreach (self::PROPERTY_FLAGS as $bit => $name)
			if (($flags & $bit) === $bit) $out[] = $name;
			
		return $out;
	}

	public function decodeFunctionFlags(int $flags): array {
		$out = [];
		
		foreach (self::FUNCTION_FLAGS as $bit => $name)
			if (($flags & $bit) === $bit) $out[] = $name;
			
		return $out;
	}

	public function decodeStateFlags(int $flags): array {
		$out = [];
		
		foreach (self::STATE_FLAGS as $bit => $name)
			if (($flags & $bit) === $bit) $out[] = $name;
			
		return $out;
	}

	public function decodeClassFlags(int $flags): array {
		$out = [];
		
		foreach (self::CLASS_FLAGS as $bit => $name)
			if (($flags & $bit) === $bit) $out[] = $name;
			
		return $out;
	}
	
	/** Disassemble tokens into readable lines up to an optional byte limit. */
	private function disasmScript(UEReader $R, int $limit = 4096): array {
		$base  = $R->tell();
		$lines = [];
		$used  = 0;

		$readIndex = function() use ($R) { return $R->index(); };

		while ($R->remaining() > 0 && $used < $limit) {
			$pc   = $R->tell() - $base;
			$opb  = ord($R->bytes(1)); 
			$used++;
			$mn   = self::OP[$opb] ?? sprintf('OP_%02X', $opb);
			$line = sprintf("%06d: %-20s", $pc, $mn);

			switch ($opb) {
				case 0x04: // EX_Return
				case 0x08: // EX_Stop
				case 0x0B: // EX_Nothing
				case 0x17: // EX_Self
				case 0x25: // 0
				case 0x26: // 1
				case 0x27: // True
				case 0x28: // False
				case 0x30: // IteratorPop
				case 0x31: // IteratorNext
					// no payload
					break;

				case 0x06: // Jump
				case 0x07: // JumpIfNot
				case 0x0A: // Case (NextOffset)
				case 0x18: // Skip
					if ($R->remaining() >= 2) {
						$off   = $R->u16(); 
						$used += 2;
						$line .= " off={$off}";
					}
					break;

				case 0x05: // Switch (BYTE size + token)
					if ($R->remaining() >= 1) {
						$sz    = $R->u8(); 
						$used += 1;
						$line .= " size={$sz}";
					}
					// fallthrough — we won’t fully parse nested tokens here
					break;

				case 0x09: // Assert (WORD line + TOKEN)
					if ($R->remaining() >= 2) {
						$ln    = $R->u16(); 
						$used += 2;
						$line .= " line={$ln}";
					}
					break;

				case 0x1D: // IntConst (DWORD)
					if ($R->remaining() >= 4) {
						$v     = $R->u32(); 
						$used += 4;
						$line .= " {$v}";
					}
					break;

				case 0x1E: // FloatConst
					if ($R->remaining() >= 4) {
						$v     = $R->f32(); 
						$used += 4;
						$line .= " {$v}";
					}
					break;

				case 0x1F: // StringConst (ASCIIZ)
					// Minimal: read until 0x00 within sane bound
					$s     = '';
					$guard = min(512, $R->remaining());
					
					for ($i=0; $i<$guard; $i++) {
						$b = $R->bytes(1); 
						$used++;
						
						if ($b === "\x00") 
							break;
						
						$s .= $b;
					}
					
					$line .= " \"".addslashes($s)."\"";
					break;

				case 0x20: // ObjectConst (INDEX object ref)
					if ($R->remaining() >= 1) {
						$ix    = $readIndex(); // size varies; `index()` manages it
						$line .= " ".($this->displayNameFromRef($ix) ?? "ref{$ix}");
						// used bytes already tracked by reader
					}
					break;

				case 0x21: // NameConst (INDEX)
					if ($R->remaining() >= 1) {
						$ix    = $readIndex();
						$line .= " '".($this->nameByIndex($ix) ?? "name{$ix}")."'";
					}
					break;

				case 0x22: // RotationConst (3 * DWORD)
					if ($R->remaining() >= 12) {
						$p     = $R->u32(); 
						$y=$R->u32(); 
						$r=$R->u32(); 
						$used+=12;
						$line .= " pitch={$p} yaw={$y} roll={$r}";
					}
					break;

				case 0x23: // VectorConst (3 * FLOAT)
					if ($R->remaining() >= 12) {
						$x=$R->f32(); 
						$y=$R->f32(); 
						$z=$R->f32(); 
						$used+=12;
						$line .= " X={$x} Y={$y} Z={$z}";
					}
					break;

				case 0x1B: // VirtualFunction (INDEX name) (params until EndFunctionParms)
				case 0x1C: // FinalFunction (INDEX object)
				case 0x38: // GlobalFunction (INDEX name)
					if ($R->remaining() >= 1) {
						$ix    = $readIndex();
						$nm    = ($opb === 0x1C) ? ($this->displayNameFromRef($ix) ?? "ref{$ix}") : ($this->nameByIndex($ix) ?? "name{$ix}");
						$line .= " ".$nm."(";
						// Skip params until EndFunctionParms (0x16)
						$parenDepth = 1;
						$guard2     = 0;
						
						while ($R->remaining() > 0 && $guard2 < 2048) {
							$guard2++;
							$b = ord($R->bytes(1)); $used++;
							
							if ($b === 0x16) { // EndFunctionParms
								break;
							}
							// For display we won’t parse nested tokens here; just count bytes
						}
						$line .= ")";
					}
					break;

				case 0x60: // ExtendedNative (read next to build native id)
					if ($R->remaining() >= 1) {
						$b2     = ord($R->bytes(1)); $used++;
						$native = (($opb - 0x60) << 8) + $b2;
						$line  .= " native={$native}";
					}
					break;
				default:
					// Unknown/less common tokens: we leave as is.
					break;
			}

			$lines[] = $line;
		}
		
		return $lines;
	}
	
	public function exportDisplayName(int $ref): string {
		return $this->displayNameFromRef($ref);
	}	

	// --- new public API (source of truth) -------------------------------------
	/** Class of the object (raw ref from Export.classIndex). */
	public function exportClassName(int $ref): string {
		return $this->displayNameFromRef($ref);
	}

	/** Super (parent) of the object (raw ref from Export.superIndex). */
	public function exportSuperName(int $ref): string {
		return $this->displayNameFromRef($ref);
	}

	/** Package/group path containing the object (raw ref from Export.packageIndex). */
// In UnrealPackageReader.php
public function exportPackageName(int $ref): string
{
    if ($ref === 0) return 'None';

    // Signed package index rules: +exports (1-based), -imports (1-based)
    if ($ref < 0) {
        $i = -$ref - 1;
        if (!isset($this->imports[$i])) return '';
        // Prefer the import's ObjectName
        $nameIdx = (int)($this->imports[$i]['objectName'] ?? -1);
        return $nameIdx >= 0 ? $this->nameStr($nameIdx) : '';
    } else {
        $e = $ref - 1;
        if (!isset($this->exports[$e])) return '';
        // For exports, show that export’s ObjectName
        $nameIdx = (int)($this->exports[$e]['objectName'] ?? -1);
        return $nameIdx >= 0 ? $this->nameStr($nameIdx) : '';
    }
}

	
/** Human-readable name for an Import’s OuterIndex (resolves import/export/null refs). */
public function importOuterName(int $importIndex): string
{
    if (!isset($this->imports[$importIndex])) return '';
    $im   = $this->imports[$importIndex];
    $oref = (int)($im['outerIndex'] ?? ($im['OuterIndex'] ?? 0));
    return $this->displayNameFromRef($oref);
}

/** Full dotted chain for an Import’s outer (walks Outers while they are imports). */
public function importOuterChain(int $importIndex): string
{
    if (!isset($this->imports[$importIndex])) return '';
    $seen   = 0;
    $parts  = [];
    $outer  = (int)($this->imports[$importIndex]['outerIndex'] ?? ($this->imports[$importIndex]['OuterIndex'] ?? 0));

    while ($outer !== 0 && $seen < 32) {
        $seen++;
        if ($outer < 0) {
            $ix = -$outer - 1;                       // import row
            if (!isset($this->imports[$ix])) break;
            $name = $this->nameByIndex($this->imports[$ix]['objectName'] ?? ($this->imports[$ix]['ObjectName']['index'] ?? -1)) ?? '';
            if ($name !== '') $parts[] = $name;
            $outer = (int)($this->imports[$ix]['outerIndex'] ?? ($this->imports[$ix]['OuterIndex'] ?? 0));
        } else { // $outer > 0 → export
            $ix = $outer - 1;
            if (!isset($this->exports[$ix])) break;
            // Exports may store name as index or FName-like struct; try all shapes
            $val = $this->exports[$ix]['objectName'] ?? ($this->exports[$ix]['ObjectName'] ?? null);
            if (is_int($val))       { $parts[] = $this->nameStr($val); }
            elseif (is_array($val)) { $parts[] = ($val['text'] ?? $val['base'] ?? $val['name'] ?? ''); }
            elseif (is_string($val)){ $parts[] = $val; }
            break; // export is usually the leaf; stop here
        }
    }

    return implode('.', array_reverse(array_filter($parts, fn($s)=>$s!=='')));
}

	
		
/** Return a reader bounded to this export’s body in UNCOMPRESSED space. */
function exportBodyReader(int $exportIdx): UEReader
{
    $ex    = $this->exports[$exportIdx] ?? null;
    $start = (int)($ex['serialOffset'] ?? 0);
    $size  = (int)($ex['serialSize']  ?? 0);

    $r = ($this->isCompressed && !empty($this->chunks))
        ? $this->makeConcatUncompressedReader()
        : $this->R;

    if (method_exists($r, 'clone')) $r = $r->clone();
    if (method_exists($r, 'setBounds')) $r->setBounds($start, $start + $size);
    $r->seek($start);
    return $r;
}

		
	public function exportObjectName(int $idxOrRef): string
	{
		// Prefer treating the argument as a direct export-array index first
		if (isset($this->exports) && is_array($this->exports)) {

			// Exact array index?
			if (isset($this->exports[$idxOrRef])) {
				$ex = $this->exports[$idxOrRef];

				// --- UE1/UE2 legacy cases (preserve behavior) ---
				if (isset($ex['objectNameIndex']) && is_int($ex['objectNameIndex'])) {
					// classic readers keep the name index here
					return $this->nameStr((int)$ex['objectNameIndex']);
				}
				if (isset($ex['objectName']) && is_int($ex['objectName'])) {
					// many UE1/UE2 builds store the index under 'objectName'
					return $this->nameStr((int)$ex['objectName']);
				}

				// --- UE3 shapes (string or FName struct kept in the row) ---
				$val = $ex['ObjectName']     ?? null;
				if ($val === null) $val = $ex['ObjectNameText'] ?? null;
				if ($val === null) $val = $ex['objectName']     ?? null;

				$s = $this->_nameLikeToText($val);
				return $s !== '' ? $s : '';
			}

			// Some callers pass a PackageIndex (>0 means export N => index N-1)
			if ($idxOrRef > 0) {
				$e = $idxOrRef - 1;
				if (isset($this->exports[$e])) {
					$ex = $this->exports[$e];

					// --- UE1/UE2 legacy cases (preserve behavior) ---
					if (isset($ex['objectNameIndex']) && is_int($ex['objectNameIndex'])) {
						return $this->nameStr((int)$ex['objectNameIndex']);
					}
					if (isset($ex['objectName']) && is_int($ex['objectName'])) {
						return $this->nameStr((int)$ex['objectName']);
					}

					// --- UE3 shapes ---
					$val = $ex['ObjectName']     ?? null;
					if ($val === null) $val = $ex['ObjectNameText'] ?? null;
					if ($val === null) $val = $ex['objectName']     ?? null;

					$s = $this->_nameLikeToText($val);
					return $s !== '' ? $s : '';
				}
			}
		}

		// Fallback: treat as a raw PackageIndex (0 / <0 import / >0 export)
		// Your displayNameFromRef() is already hardened to handle UE1/UE2 and UE3.
		$s = $this->nameByIndex($idxOrRef);
		return is_string($s) ? $s : '';
	}


	/**
	 * Decompress and cache a chunk (by chunk index in $this->chunks).
	 * Tries headered UE3 layout first (signature + pairs), tolerant to size mismatches.
	 * Falls back to "headerless" tolerant parsing from offset 0 when needed.
	 */
	private function ensureChunkLoaded(int $chunkIndex): void
	{
		if (isset($this->chunkCache[$chunkIndex])) 
			return;

		$c = $this->chunks[$chunkIndex] ?? null;
		
		if (!$c) 
			throw new \RuntimeException("Chunk $chunkIndex not present");

		$cOff = (int)$c['cOff'];
		$cLen = (int)$c['cLen'];
		$uLen = (int)$c['uLen'];

		// Load chunk bytes
		$this->R->seek($cOff);
		$compBuf    = $this->R->bytes($cLen);
		$r          = new UEReader($compBuf);
		$r->setVersion(0);
		$out        = '';
		$usedHeader = false;

		// --- Try UE3 headered ---
		if ($r->length() >= 16) {
			$save        = $r->tell();
			$signature   = $r->u32();
			$blockSize   = $r->u32();
			$compTotal   = $r->u32();
			$uncompTotal = $r->u32();

			if ($blockSize > 0 && $blockSize <= 4_000_000 && $uncompTotal > 0) {
				$numBlocks  = (int)ceil($uncompTotal / $blockSize);
				$pairsBytes = $numBlocks * 8;
				$pairs      = [];
				
				if ($r->remaining() >= $pairsBytes) {
					for ($i = 0; $i < $numBlocks; $i++) {
						if ($r->remaining() < 8) { 
							$pairs = []; 
							break; 
						}
						
						$cs = $r->u32(); $us = $r->u32();
						
						if ($cs <= 0 || $us < 0) { 
							$pairs = []; 
							break; 
						}
						
						$pairs[] = [$cs, $us];
					}
				}

				if (!empty($pairs)) {
					$compStart = $r->tell();
					$sumComp   = 0; 
					
					foreach ($pairs as $p) 
						$sumComp += (int)$p[0];
						
					$compBound = ($compTotal > 0) ? min($compTotal, $sumComp) : $sumComp;
					
					if ($compStart + $compBound > $r->length()) {
						$compBound = max(0, $r->length() - $compStart);
					}

					$headeredOk = true;
					$out        = '';

					foreach ($pairs as [$cs, $us]) {
						$rem = $r->remaining();
						
						if ($cs > $rem) { // clamp, don't throw
							if ($rem <= 0) { 
								$headeredOk = false; 
								break; 
							}
						
							$cs = $rem; $headeredOk = false;
						}
						
						if ($cs <= 0) 
							break;

						$blk = $r->bytes($cs);
						$raw = $this->decompressBlock($blk, (int)$us);

						if ($us > 0) {
							$len = strlen($raw);
							if     ($len > $us) 
								$raw = substr($raw, 0, $us);
							elseif ($len < $us) 
							$raw = str_pad($raw, $us, "\x00");
						}
						
						$out .= $raw;

						if (($r->tell() - $compStart) >= $compBound) 
							break;
						
						if (strlen($out) >= $uncompTotal) 
							break;
					}

					if (strlen($out) > 0) {
						$usedHeader = true;
						$target     = ($uncompTotal > 0) ? $uncompTotal : $uLen;
						
						if (strlen($out) > $target)      
							$out = substr($out, 0, $target);
						elseif (strlen($out) < $target)  
							$out = str_pad($out, $target, "\x00");

						if (strlen($out) > $uLen)        
							$out = substr($out, 0, $uLen);
						elseif (strlen($out) < $uLen)    
							$out = str_pad($out, $uLen, "\x00");
					}
				}
			}

			if (!$usedHeader) 
				$r->seek(0); // restart for fallback
		}

		// --- Headerless fallback ---
		if (!$usedHeader) {
			$out   = '';
			$pairs = [];
			$sumU  = 0;

			while ($r->remaining() >= 8 && $sumU < $uLen) {
				$cs = $r->u32(); $us = $r->u32();
				
				if ($cs <= 0 || $us < 0 || $us > 4_000_000) 
					break;
				
				$pairs[] = [$cs, $us]; $sumU += $us;
			}

			foreach ($pairs as [$cs, $us]) {
				$rem = $r->remaining();
				
				if ($cs > $rem) 
					$cs = $rem;
				
				if ($cs <= 0) 
					break;

				$blk = $r->bytes($cs);
				$raw = $this->decompressBlock($blk, (int)$us);

				if ($us > 0) {
					$len = strlen($raw);
					
					if     ($len > $us) 
						$raw = substr($raw, 0, $us);
					elseif ($len < $us) 
						$raw = str_pad($raw, $us, "\x00");
				}
				
				$out .= $raw;
				
				if (strlen($out) >= $uLen) 
					break;
			}

			if (strlen($out) > $uLen)      
				$out = substr($out, 0, $uLen);		
			elseif (strlen($out) < $uLen)  
				$out = str_pad($out, $uLen, "\x00");
		}

		// --- FINAL: whole-chunk zlib fallback (no pairs). Many UT3 chunks inflate like this. ---
		if (strlen($out) === 0) {
			$raw = @gzinflate($compBuf);
			
			if ($raw === false) 
				$raw = @gzuncompress($compBuf);
			
			if ($raw !== false) {
				if (strlen($raw) > $uLen)      
					$raw = substr($raw, 0, $uLen);
				elseif (strlen($raw) < $uLen)  
					$raw = str_pad($raw, $uLen, "\x00");
					
				$out = $raw;
			}
		}

		// If we *still* failed, at least return zero-padded block of the right size
		if (strlen($out) === 0) {
			$out = str_repeat("\x00", $uLen);
		}

		$this->chunkCache[$chunkIndex] = $out;
	}
	/*
	// Render name flags as a single human-readable string.
	// Falls back to pure hex if nothing matches.
	public function formatNameFlagsPretty(int $flags, bool $isUE3): string
	{
		$labels = $this->decodeNameFlags($flags, $isUE3);
		if (!empty($labels)) {
			return implode('|', $labels);
		}
		// No known bits — show as hex for visibility.
		return $isUE3
			? ('0x' . str_pad(dechex($flags), 16, '0', STR_PAD_LEFT))
			: ('0x' . str_pad(dechex($flags), 8,  '0', STR_PAD_LEFT));
	}
	*/

		

	/*
	// Read little-endian u64; returns PHP int on x64. If you need bcmath on x86, add it later.
	private function u64_from(string $b, int $o = 0): int {
		$lo = $this->u32_from($b, $o);
		$hi = $this->u32_from($b, $o + 4);
		return ($hi << 32) | $lo;
	}
	*/
	/*
	private function toHex64(int $v): string {
		return '0x' . str_pad(dechex($v), 16, '0', STR_PAD_LEFT);
	}
	*/

		
	/** Build full path (Package.Group...) from a raw object ref by walking outers. */
	/** Build full path (Package.Group...) from a raw object ref by walking outers.
	 *  Works for both UE1/UE2 (int indices) and UE3 (string/FName structs).
	 */
	private function groupPathFromRef(int $ref): string
{
    $parts = [];
    $seen  = [];   // track refs we've already visited
    $depth = 0;

    // walk imports/exports by following "outer" package index
    while ($ref !== 0) {
        // break if we've seen this reference before or gone too deep
        if (isset($seen[$ref]) || $depth++ > 64) {
            $parts[] = '__CYCLE__';
            break;
        }
        $seen[$ref] = true;

        if ($ref > 0) {
            $e = $ref - 1;
            if (!isset($this->exports[$e])) break;

            $ex   = $this->exports[$e];
            $val  = $ex['objectName'] ?? ($ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? null));
            $name = is_int($val)
                ? $this->nameStr($val)
                : (is_array($val)
                    ? ((string)($val['text'] ?? $val['base'] ?? $val['name'] ?? ''))
                    : (is_string($val) ? $val : '')
                  );
            if ($name !== '') $parts[] = $name;

            $ref = $this->_outerPkgIndex($ex);
            continue;
        }

        $i = -$ref - 1;
        if (!isset($this->imports[$i])) break;

        $im   = $this->imports[$i];
        $val  = $im['objectName'] ?? ($im['ObjectName'] ?? ($im['ObjectNameText'] ?? null));
        $name = is_int($val)
            ? $this->nameStr($val)
            : (is_array($val)
                ? ((string)($val['text'] ?? $val['base'] ?? $val['name'] ?? ''))
                : (is_string($val) ? $val : '')
              );
        if ($name !== '') $parts[] = $name;

        $ref = $this->_outerPkgIndex($im);
    }

    return implode('.', array_reverse($parts));
}



		/** Return all parsed properties for an export as an associative array. */
	// DROP-IN: keep your signature/return. Only add the UE3 branch.
	/* old
	public function getExportProperties(int $exportIndex): array
	{
		// Use UE3 reader when appropriate
		if (method_exists($this, 'isUE3Package') && $this->isUE3Package()
			&& method_exists($this, 'readPropertiesForExportUE3')) {
			return $this->readPropertiesForExportUE3($exportIndex);
		}

		// Fallback to your existing UE1/UE2 reader (unchanged)
		return $this->readPropertiesForExport($exportIndex);
	}
	*/
	
/** Return row-shaped properties for the viewer across UE1/UE2/UE3. */
public function getExportProperties(int $exportIndex): array
{
    // UE3 path: use your UE3 summary reader, then map to the row shape the viewer expects
    if (method_exists($this, 'isUE3Package') && $this->isUE3Package()
        && method_exists($this, 'readPropertiesForExportUE3')) {

        $tags = $this->readPropertiesForExportUE3($exportIndex); // array of decoded UE3 tags

        // Map UE3 tag objects to the row shape:
        $rows = [];
        foreach ((array)$tags as $t) {
            // Tolerant reads (don’t assume keys exist)
            $name       = (string)($t['name']        ?? '');
            $type       = (string)($t['type']        ?? '');
            $size       = (int)   ($t['size']        ?? 0);
            $arrayIndex = (int)   ($t['arrayIndex']  ?? 0);
            $struct     = '';
            if (isset($t['meta'])) {
                if (is_array($t['meta']) && isset($t['meta']['struct'])) {
                    $struct = (string)$t['meta']['struct'];
                } elseif (is_string($t['meta'])) {
                    $struct = $t['meta'];
                }
            }

            // Prefer real offsets/lengths if your UE3 reader provides them; else fallback
            $offset = isset($t['offset']) ? (int)$t['offset'] : 0;
            $length = isset($t['length']) ? (int)$t['length'] : $size;

            $rows[] = [
                'offset'      => $offset,
                'length'      => $length,
                'name'        => $name,
                'type'        => $type,
                'struct'      => $struct,
                'isArray'     => ($arrayIndex > 0 ? 'Yes' : 'No'),
                'idx'         => ($arrayIndex > 0 ? $arrayIndex : null),
                'idxFromFile' => ($arrayIndex > 0),
                'value'       => $t['value'] ?? '',
                // keep UE3 extras too (non-breaking for the viewer)
                'size'        => $size,
                'arrayIndex'  => $arrayIndex,
                'meta'        => $t['meta']  ?? null,
                'data'        => $t['data']  ?? null,
            ];
        }

        return $rows;
    }

    // UE1/UE2: build a bounded sub-reader over the export body and call your row builder
    $PR = $this->exportBodyReader($exportIndex);
    if (!$PR) return [];

    $saveR = $this->R;
    $this->R = $PR;
    try {
        // readPropertyBlock() already switches to UE3 internally if needed,
        // but we are in the non-UE3 branch here, so this will be UE1/UE2.
        $rows = $this->readPropertyBlock($PR->length());
    } catch (\Throwable $e) {
        $rows = [];
    } finally {
        $this->R = $saveR;
    }
    return $rows;
}



	
	// Return the raw name-string by name table index, or a safe placeholder.
	private function getNameByIndex(int $idx): string
	{
		if (!isset($this->names) || !is_array($this->names)) {
			return "__NO_NAMES__";
		}
		if ($idx < 0 || $idx >= count($this->names)) {
			return "__BADNAME[$idx]__";
		}
		$s = (string)($this->names[$idx]['name'] ?? '');
		return $s !== '' ? $s : "__EMPTY__";
	}
	
	public function getExportDisplayRows(): array {
		$rows = [];
		
		foreach ($this->exports as $i => $ex) {
			$group  = $this->displayOuterNameFromRef($ex['packageIndex']); // “Package & Group”
			$name   = $this->nameByIndex($ex['objectName']);               // object name (from Name table)
			$classN = $this->displayNameFromRef($ex['classIndex']);        // resolve Class ref to a name
			$superN = $this->displayNameFromRef($ex['superIndex']);        // resolve Super ref to a name
			$low32  = (int)$ex['objectFlags'];			
			$rows[] = [
				'group'             => $group ?? '',
				'name'              => $name  ?? '',
				'class'             => $classN ?: '',
				'num'               => $i,
				'super'             => $superN ?: '',
				'serialSize'        => $ex['serialSize'],
				'serialOffset'      => $ex['serialOffset'],
				'flagsHex'          => sprintf('0x%08X', $low32),
				'flagsDec'          => $this->decodeRF($low32),
			];
		}
		
		return $rows;
	}

	/** Build rows for the Import table exactly like your sample */
	public function getImportDisplayRows(): array {
		$rows = [];
		
		foreach ($this->imports as $i => $im) {
			$pkgGroup = $this->displayOuterNameFromRef($im['outerIndex']); // “Package & Group”
			$name     = $this->nameByIndex($im['objectName']);
			$classN   = $this->nameByIndex($im['className']);
			$classPkg = $this->nameByIndex($im['classPackage']);
			$rows[]   = [
				'pkgGroup'          => $pkgGroup ?: '',
				'name'              => $name     ?: '',
				'class'             => $classN   ?: '',
				'classPkg'          => $classPkg ?: '',
				'num'               => $i,
			];
		}
		
		return $rows;
	}






	/** Convenience: get a single property by name (null if missing). */
	/*
	public function getExportProperty(int $exportIndex, string $name, $default = null) {
		$props = $this->getExportProperties($exportIndex) ?? [];
		
		return array_key_exists($name, $props) ? $props[$name] : $default;
	}
*/
	/** Convenience: properties for all exports of a given class (e.g., 'Shader'). */
	/*
	public function getPropertiesByClass(string $className): array {
		$out = [];
		
		foreach ($this->exports ?? [] as $i => $_) {
			if ($this->exportClassName($i) === $className) {
				$out[$i] = $this->getExportProperties($i);
			}
		}
		
		return $out;
	}
	*/

	
	
    public function getFilePath(): string { 
		return $this->path; 
	}

    public function getHeader(): array { 
		return $this->header; 
	}
    public function getNames(): array { 
		return $this->names; 
	}
    public function getExports(): array { 
		return $this->exports; 
	}
	
	public function getFileSize(): int {
		return $this->R->length();                 // uses the already-loaded bytes
	}

	/** Return basic compression summary (UE2/UE3 chunked compression) */
    public function getCompressionInfo(): array {
        $info = ['isCompressed' => (bool)$this->isCompressed, 'chunks' => [], 'totalCompressed' => 0, 'totalUncompressed' => 0, ];
			
        if (!empty($this->chunks)) {
            foreach ($this->chunks as $c) {
                $info['chunks'][] = ['cOff' => (int)$c['cOff'], 'cLen' => (int)$c['cLen'], 'uOff' => (int)$c['uOff'], 'uLen' => (int)$c['uLen'],];
                $info['totalCompressed']   += (int)$c['cLen'];
                $info['totalUncompressed'] += (int)$c['uLen'];
            }
        }
		
        return $info;
    }



	
	public function getUncompressedFileSize(): int {
		return $this->totalUncompressedSize();     // this already exists (private)
	}

    public function getImports(): array { 
		return $this->imports; 
	}
	
	// In UnrealPackageReader4.php (public API)
	//public function getExportProperties(int $exportIndex): ?array {
	//	return $this->exportProperties[$exportIndex] ?? null;   // adjust to your actual storage name
	//}
	
	public function getImportProperties(int $i): array { 
		return $this->importProps[$i] ?? []; 
	}	
	/*
	function matLabel($v, $nameByIndex, $displayNameFromRef) {
	  if (is_int($v)) 
		  return $nameByIndex($v) ?? (string)$v;
	  
	  if (is_array($v) && isset($v['object'])) 
		  return $displayNameFromRef($v['object']);
	  
	  return is_scalar($v) ? (string)$v : json_encode($v);
	}
	*/
	
	/*	
	public function getHeaderRaw(): array {
		// filter header to only raw_* plus raw_guid_bytes, but simplest is to return $this->header and let caller use keys
		return $this->header; // contains both pretty and raw_*; caller picks raw
	}
	public function getNamesRaw(): array { 
		return $this->names; 
	}         // entries contain raw_* already
	
	public function getExportsRaw(): array { 
		return $this->exports; 
	}     // raw core fields
	
	public function getImportsRaw(): array { 
		return $this->imports; 
	}     // raw core fields
	
	public function getExportPropertiesRaw(int $i): array { 
		return $this->exportProps[$i] ?? []; 
	} // each entry already includes raw_* keys
		
	*/	
		

	// Conservative: UE3 if we see UE3 chunk magic anywhere or CompressionFlags!=0 or very high engine/cooker versions
	private function isUE3Package(): bool {
		if (!empty($this->compressionFlags)) return true;
		$eng = (int)($this->header['EngineVersion'] ?? $this->header['engineVersion'] ?? 0);
		$cook= (int)($this->header['CookerVersion'] ?? $this->header['cookerVersion'] ?? 0);
		return ($eng >= 1000) || ($cook > 0); // UT3 is well above this
	}
	
	public function importDisplayName(int $i): string {
		$im = $this->imports[$i] ?? null; 
		
		if (!$im) 
			return '';
		
		return $this->nameStr($im['objectName']);
	}	

	public function importClassPackage(int $i): string {
		$im = $this->imports[$i] ?? null; 
		
		if (!$im) 
			return '';
		
		return $this->nameStr($im['classPackage']);
	}
	
	public function importGroupPath(int $i): string {
		$im   = $this->imports[$i] ?? null; 
		
		if (!$im) 
			return '';
		
		$path = [];
		$k    = $this->refKind($im['outerIndex']); 
		$ix   = $this->refIndex($im['outerIndex']);
		
		while ($k !== 'none') {
			if ($k === 'export') {
				$ex = $this->exports[$ix] ?? null; 
				
				if (!$ex) 
					break;
				
				$path[] = $this->nameStr($ex['objectName']);
				$k      = $this->refKind($ex['packageIndex']); 
				$ix     = $this->refIndex($ex['packageIndex']);
			} else {
				$im2 = $this->imports[$ix] ?? null; 
				
				if (!$im2) 
					break;
				
				$path[] = $this->nameStr($im2['objectName']);
				$k      = $this->refKind($im2['outerIndex']); 
				$ix     = $this->refIndex($im2['outerIndex']);
			}
		}
		
		return implode('.', array_reverse($path));
	}
	





	



	// NEW: read one UE3 property value (size-aware); returns ['type'=>..., 'value'=>..., 'raw'=>...]
	/*
	private function _readUE3PropValue(UEReader $R, string $typeName, int $size): array
	{
		switch ($typeName) {
			case 'IntProperty':   return ['type'=>'IntProperty',   'value'=>$R->i32()];
			case 'FloatProperty': {
				$v = method_exists($R,'f32') ? $R->f32() : (unpack('g', $R->bytes(4))[1]);
				return ['type'=>'FloatProperty','value'=>$v];
			}
			case 'NameProperty':  { $nm=$this->_readFNameStruct($R); return ['type'=>'NameProperty','value'=>$nm['text'],'name'=>$nm]; }
			case 'StrProperty':   {
				$v = method_exists($this,'readFStringFrom') ? $this->readFStringFrom($R)
														   : (method_exists($R,'cstr') ? $R->cstr() : '');
				return ['type'=>'StrProperty','value'=>$v];
			}
			case 'ObjectProperty':
			case 'ComponentProperty': {
				$ref = $R->i32();
				return ['type'=>$typeName,'value'=>$this->displayNameFromRef($ref),'ref'=>$ref];
			}
			case 'ByteProperty': {
				if ($size === 1) return ['type'=>'ByteProperty','value'=>$R->u8()];
				if ($size === 8) { $nm=$this->_readFNameStruct($R); return ['type'=>'ByteProperty','value'=>$nm['text'],'name'=>$nm]; }
				return ['type'=>'ByteProperty','raw'=>$R->bytes($size)];
			}
			case 'BoolProperty':  return ['type'=>'BoolProperty','value'=>null]; // bool byte handled in tag header
			case 'StructProperty':{
				$sname = $this->_readFNameStruct($R);
				$raw   = ($size > 0) ? $R->bytes($size) : '';
				return ['type'=>'StructProperty','struct'=>$sname['text'],'raw'=>$raw];
			}
			case 'ArrayProperty': {
				$count = $R->i32();
				$remaining = $size - 4;
				$raw = ($remaining > 0) ? $R->bytes($remaining) : '';
				return ['type'=>'ArrayProperty','count'=>$count,'raw'=>$raw];
			}
		}
		$raw = ($size > 0) ? $R->bytes($size) : '';
		return ['type'=>$typeName,'raw'=>$raw];
	}
	*/

















	

	/** Object name (name table index from Export.objectName). */
	//public function exportObjectName(int $nameIndex): string {
	//	return $this->nameByIndex($nameIndex);
	//}

	// --- public API for Import table ---

	/** Import.ClassPackage (INDEX → Name Table). */
	public function importClassPackageName(int $nameIndex): string {
		return $this->nameByIndex($nameIndex);
	}

	/** Import.ClassName (INDEX → Name Table). */
	public function importClassName(int $nameIndex): string {
		return $this->nameByIndex($nameIndex);
	}

	/** Import.Package/Outer (RAW object ref 0/+N/−N → path). */
	public function importPackageName(int $ref): string {
		// Walk outer chain to build Package.Group...
		return $this->groupPathFromRef($ref);
	}

	/** Import.ObjectName (INDEX → Name Table). */
	public function importObjectName(int $nameIndex): string {
		return $this->nameByIndex($nameIndex);
	}
	


	// These NEVER return null; they’re UE3-safe. Use these in UE3 views.
	private function importClassPackageNameUE3(int $i): string
	{
		if (!isset($this->imports) || $i < 0 || $i >= count($this->imports)) return '';
		return (string)($this->imports[$i]['classPackageName'] ?? '');
	}
	private function importClassNameUE3(int $i): string
	{
		if (!isset($this->imports) || $i < 0 || $i >= count($this->imports)) return '';
		return (string)($this->imports[$i]['className'] ?? '');
	}
	private function importObjectNameUE3(int $i): string
	{
		if (!isset($this->imports) || $i < 0 || $i >= count($this->imports)) return '';
		return (string)($this->imports[$i]['objectName'] ?? '');
	}



	function makeConcatUncompressedReader(): UEReader
	{
		if (!$this->isCompressed) {
			return $this->R;
		}
		$this->buildConcatUncompressed();
		$r = new UEReader($this->concatBuf);
		$r->setVersion((int)($this->header['version'] ?? 0));
		return $r;
	}
	// New: build a reader over the FULL uncompressed stream
	private function makeFullUncompressedReader(): UEReader
	{
		$total = $this->totalUncompressedSize();
		$buf   = $this->readUncompressedRange(0, $total);
		
		if ($this->isCompressed && $total > 0 && strlen($buf) < 64) {
			// almost certainly failed decompression; bail clearly
			throw new \RuntimeException(
				"Decompression produced too little data (" . strlen($buf) . " bytes of $total). ".
				"Check LZO availability and compression flags."
			);
		}		
		
		$r     = new UEReader($buf);
		
		if (method_exists($r, 'setVersion')) 
			$r->setVersion((int)($this->header['version'] ?? 0));
		
		return $r;
	}

	/** Return a UEReader that sees the *concatenated* uncompressed stream.
	 *  Seeks into this reader must be done with mapUncompressedOffset().
	 */
	public function makeMappedUncompressedReader(): UEReader
	{
		if (!$this->isCompressed) 
			return $this->R;

		$this->buildUncompressedConcat();
		$r = new UEReader($this->concatBuf);
		$r->setVersion($this->header['version'] ?? 0);
		
		return $r;
	}
	// NEW: build a bounded UEReader over one export's serialized bytes (UE3-safe; doesn't touch UE1/UE2)
	// Ultra-tolerant: always return a bounded reader (possibly empty) for a UE3 export’s serialized data.
	private function makeExportDataReaderUE3(int $exportIndex): UEReader
	{
		if (!isset($this->exports[$exportIndex])) {
			// If the caller asked for a non-existent export, return an empty reader instead of throwing.
			$r = new UEReader('');
			if (method_exists($r, 'setVersion') && isset($this->header['version'])) {
				$r->setVersion((int)$this->header['version']);
			}
			return $r;
		}

		$e = $this->exports[$exportIndex];

		// Helper: first matching key or null
		$pick = function(array $arr, array $keys) {
			foreach ($keys as $k) {
				if (array_key_exists($k, $arr)) return $arr[$k];
			}
			return null;
		};

		// Accept a wide set of possible spellings
		$offVal = $pick($e, [
			'SerialOffset','serialOffset','serial_offset','dataOffset','DataOffset',
			'objectOffset','ObjectOffset','offset','Offset'
		]);
		$sizVal = $pick($e, [
			'SerialSize','serialSize','serial_size','dataSize','DataSize',
			'objectSize','ObjectSize','size','Size','length','Length'
		]);

		// Coerce
		$serialOffset = is_numeric($offVal) ? (int)$offVal : null;
		$serialSize   = is_numeric($sizVal) ? (int)$sizVal : null;

		// Zero or missing size => valid, just return an empty reader.
		if ($serialSize === null || $serialSize <= 0) {
			$r = new UEReader('');
			if (method_exists($r, 'setVersion') && isset($this->header['version'])) {
				$r->setVersion((int)$this->header['version']);
			}
			return $r;
		}

		// If offset is missing, assume 0 (we’ll clamp later). Some tools omit it for small payloads.
		if ($serialOffset === null || $serialOffset < 0) {
			$serialOffset = 0;
		}

		// Read from the concatenated uncompressed buffer
		$big = $this->makeConcatUncompressedReader();

		// Figure out total length of the big buffer
		$bigLen = 0;
		if (method_exists($big, 'length')) {
			$bigLen = (int)$big->length();
		} elseif (method_exists($big, 'getBuffer')) {
			$buf = $big->getBuffer(); // if your UEReader exposes it
			$bigLen = is_string($buf) ? strlen($buf) : 0;
		} else {
			// Fallback: try to seek to end and tell()
			$cur = method_exists($big, 'tell') ? $big->tell() : 0;
			if (method_exists($big, 'seek')) $big->seek(PHP_INT_MAX);
			$bigLen = method_exists($big, 'tell') ? $big->tell() : 0;
			if (method_exists($big, 'seek')) $big->seek($cur);
		}

		// Clamp to file bounds
		if ($serialOffset > $bigLen) $serialOffset = $bigLen;
		$maxReadable = max(0, $bigLen - $serialOffset);
		$toRead = min($serialSize, $maxReadable);

		// Slice (may be empty)
		if (method_exists($big, 'seek')) $big->seek($serialOffset);
		$slice = ($toRead > 0) ? $big->bytes($toRead) : '';

		$r = new UEReader($slice);
		if (method_exists($r, 'setVersion') && isset($this->header['version'])) {
			$r->setVersion((int)$this->header['version']);
		}
		return $r;
	}

	/** Name table helper */
	public function nameByIndex(?int $idx): ?string {
		if ($idx === null) 
			return null;
		
		return $this->names[$idx]['name'] ?? null;
	}


	private function nameStr(int $nameIndex): string {
		return ($nameIndex >= 0 && $nameIndex < count($this->names)) ? (string)($this->names[$nameIndex]['name'] ?? '') : '';
	}

	
	/** Normalize a raw chunk quadruple to ['uOff','uLen','cOff','cLen'].
	 * Accepts either (uOff,uLen,cOff,cLen) or (cOff,cLen,uOff,uLen), etc.
	 * Uses simple sanity/ordering rules against fileLen and first compressed offset.
	 */
	private function normalizeChunkQuad(array $q, int $fileLen, int $firstCompressedOff): array
	{
		// All candidates must be 4 unsigned 32-bit ints
		$a = array_values($q);
		if (count($a) !== 4) {
			throw new \RuntimeException("Invalid chunk tuple shape.");
		}

		// Try (uOff,uLen,cOff,cLen)
		[$uOff,$uLen,$cOff,$cLen] = $a;
		$ok1 = ($uLen > 0) && ($cLen > 0) &&
			   ($cOff >= $firstCompressedOff) &&
			   ($cOff + $cLen <= $fileLen) &&
			   ($uOff >= 0);

		if ($ok1) {
			return ['uOff'=>$uOff, 'uLen'=>$uLen, 'cOff'=>$cOff, 'cLen'=>$cLen];
		}

		// Try (cOff,cLen,uOff,uLen)
		[$cOff,$cLen,$uOff,$uLen] = $a;
		$ok2 = ($uLen > 0) && ($cLen > 0) &&
			   ($cOff >= $firstCompressedOff) &&
			   ($cOff + $cLen <= $fileLen) &&
			   ($uOff >= 0);

		if ($ok2) {
			return ['uOff'=>$uOff, 'uLen'=>$uLen, 'cOff'=>$cOff, 'cLen'=>$cLen];
		}

		// Fallback: try all pairings (slower, but defensive)
		$pairs = [
			[0,1,2,3], [2,3,0,1], [0,2,1,3], [2,0,1,3], [1,3,0,2], [3,1,0,2]
		];
		foreach ($pairs as $p) {
			$uOff = $a[$p[0]]; $uLen = $a[$p[1]];
			$cOff = $a[$p[2]]; $cLen = $a[$p[3]];
			$ok = ($uLen > 0) && ($cLen > 0) &&
				  ($cOff >= $firstCompressedOff) &&
				  ($cOff + $cLen <= $fileLen) &&
				  ($uOff >= 0);
			if ($ok) {
				return ['uOff'=>$uOff, 'uLen'=>$uLen, 'cOff'=>$cOff, 'cLen'=>$cLen];
			}
		}

		throw new \RuntimeException("Unable to normalize chunk tuple: ".json_encode($q));
	}


	public static function describeCompressionFlags(int $flags): string {
		if ($flags === 0) 
			return 'None';
		
		$names = [];
		
		foreach (self::$UE3_COMPRESSION_FLAGS as $bit => $name) {
			if ($flags & $bit) 
				$names[] = $name;
		}
		// Include unknown bits if present
		$unknown = $flags & ~array_sum(array_keys(self::$UE3_COMPRESSION_FLAGS));
		
		if ($unknown) 
			$names[] = sprintf('0x%X(unknown)', $unknown);
		
		return implode(',', $names);
	}



	/** Map an original uncompressed offset (with gaps) to the concatenated buffer.
	 *  Returns -1 if the offset is not covered by any chunk (i.e., falls in a gap).
	 */
	 /*
	private function mapUncompressedOffset(int $ofs): int
	{
		if (!$this->isCompressed) 
			return $ofs;
		
		if (!isset($this->offsetMap)) 
			$this->buildUncompressedConcat();

		foreach ($this->offsetMap as $row) {
			$u0 = $row['uOff']; $u1 = $u0 + $row['uLen'];
			
			if ($ofs >= $u0 && $ofs < $u1) {
				return $row['cum'] + ($ofs - $u0);
			}
		}
		
		return -1; // in a gap
	}
	*/
	
	/*
	private function formatGuid(string $raw16): string
	{
		if (strlen($raw16) !== 16) 
			return '';
		
		$b = array_values(unpack('C16', $raw16));
		// Data1 (4 bytes LE), Data2 (2 LE), Data3 (2 LE), Data4 (8 BE-ish)
		$d1 = sprintf('%02x%02x%02x%02x', $b[4],  $b[3],  $b[2],  $b[1]);
		$d2 = sprintf('%02x%02x',         $b[6],  $b[5]);
		$d3 = sprintf('%02x%02x',         $b[8],  $b[7]);
		$d4 = sprintf('%02x%02x',         $b[9],  $b[10]);
		$d5 = sprintf('%02x%02x%02x%02x%02x%02x', $b[11], $b[12], $b[13], $b[14], $b[15], $b[16]);
		
		return strtoupper("{$d1}-{$d2}-{$d3}-{$d4}-{$d5}");
	}
	*/

    public function propertyTypeName(int $code): string { 
		return self::PROPERTY_TYPES[$code] ?? ('Type(0x'.strtoupper(dechex($code)).')'); 
	}
	





	



    
	
	/*	
	// Get readable text from a value that might be a UE3 FName struct or a plain string/int.
	private function _fnameText($val): string
	{
		if (is_array($val)) {
			// UE3 path: our FName structs look like ['text' => 'Foo', 'base' => 'Foo', ...]
			if (isset($val['text']) && $val['text'] !== '') return (string)$val['text'];
			if (isset($val['base']) && $val['base'] !== '') return (string)$val['base'];
			if (isset($val['name']) && $val['name'] !== '') return (string)$val['name'];
		}
		if (is_string($val)) return $val;
		// If it's a name index (UE1/UE2 style), only now call nameStr(int)
		if (is_int($val))   return $this->nameStr($val);
		return '';
	}
	*/



	


	/*
	private function readUENameString(UEReader $R): string
	{
		// Peek 4 bytes; if not enough, fall back to cstr
		$save = $R->tell();
		
		if (method_exists($R, 'remaining') && $R->remaining() < 4) {
			return $R->cstr();
		}
		
		$len = $R->i32(); // SIGNED length if this is FString

		// ANSI FString
		if ($len > 0 && $len <= 1_000_000 && method_exists($R, 'remaining') && $R->remaining() >= $len) {
			$s = rtrim($R->bytes($len), "\x00");
			
			return $s;
		}

		// UTF-16LE FString
		if ($len < 0) {
			$need = (-$len) * 2;
			
			if ($need > 0 && $need <= 2_000_000 && method_exists($R,'remaining') && $R->remaining() >= $need) {
				$raw = $R->bytes($need);
				$s   = @iconv('UTF-16LE','UTF-8//IGNORE',$raw);
				
				return rtrim($s ?: '', "\x00");
			}
		}

		// Not a plausible FString → revert and read ASCIIZ
		$R->seek($save);
		
		return $R->cstr();
	}
	*/
	
	// --- EXACT PORT OF JAVA headered chunk loader with zlib/lzo ---
	/**
	 * Decompress a package chunk into memory (cached by index).
	 * Tries UE3 headered layout first (signature + block table), then
	 * falls back to a tolerant headerless reader. Never throws on minor
	 * size mismatches; clamps/pads to the advertised uncompressed sizes.
	 */

	
	
	/*
	private function makeUncompressedReader(int $offset, int $limitBytes): UEReader {
		$total = $this->totalUncompressedSize();
		$limit = max(0, min($limitBytes, $total - $offset));
		$bytes = $this->readUncompressedRange($offset, $limit);
		$sub   = new UEReader($bytes);
		$sub->setVersion($this->header['version'] ?? 0);
		
		return $sub;
	}
	*/
	/*
    private function readCompressionHeaderIfAny(): void
    {
        $R             = $this->R;
        $start         = $R->tell();
        $version       = $this->header['version'] ?? 0;
        $firstTableOff = min(array_filter([
            $this->header['nameOffset'] ?? PHP_INT_MAX,
            $this->header['importOffset'] ?? PHP_INT_MAX,
            $this->header['exportOffset'] ?? PHP_INT_MAX
        ], fn($v)=>$v>0));
		
        if (!$firstTableOff) { 
			$R->seek($start); 
			
			return; 
		}

        if ($version >= 334 && ($R->tell() + 8) <= $firstTableOff) {
            $compressionFlags = $R->u32();
            $chunkCount       = $R->u32();
			
            if ($compressionFlags !== 0 && $chunkCount > 0) {
                $need = $chunkCount * 16;
				
                if ($R->tell() + $need <= $firstTableOff) {
                    for ($i=0;$i<$chunkCount;$i++){
                        $R->u32(); 
						$R->u32(); 
						$R->u32(); 
						$R->u32();
                    }
                }
				
				$this->isCompressed    = true;
            }
        }
		
        $R->seek($start);
    }
	*/
	

	
	private function peekAfterProperties(UEReader $R): UEReader {
		// Move $R past the serialized Properties list (ended by Name 'None')
		// This mirrors your property-reading loop but discards values and stops at terminator.
		$start = $R->tell();
		
		while ($R->tell() < $R->length()) {
			$propStart = $R->tell();
			$nameIdx   = $R->index();
			$nameStr   = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') 
				break;

			$info     = $R->u8();
			$typeCode = $info & 0x0F;
			$sizeCode = ($info >> 4) & 0x07;
			$isArray  = ($info & 0x80) !== 0 && $typeCode !== 0x03;

			$structNameIdx = ($typeCode === 0x0A) ? $R->index() : null; // Struct name if needed

			// size field
			$payloadLen = match ($sizeCode) {
				0 => 1, 
				1 => 2, 
				2 => 4, 
				3 => 12, 
				4 => 16,
				5 => $R->u8(),
				6 => $R->u16(),
				7 => $R->u32(),
			};

			if ($isArray) {
				$b = $R->u8();
				
				if (($b & 0x80) === 0) { /* 1 byte idx */ 
				}				
				elseif (($b & 0xC0) === 0x80) { 
					$R->u8(); /* word idx */ 
				}
				else { 
					$R->u16(); 
					$R->u8(); /* int idx (3 bytes left here) */ 
				}
			}

			if ($typeCode !== 0x03 && $payloadLen > 0) {
				$R->seek(min($R->tell() + $payloadLen, $R->length()));
			}
		}
		
		return $R;
	}

	public function peekTextureSummary(int $exportIndex): ?array {
		$e = $this->exports[$exportIndex] ?? null;
		
		if (!$e || ($e['serialSize'] ?? 0) <= 0) 
			return null;

		$R = clone $this->R;
		$R->seek((int)$e['serialOffset']);
		$this->peekAfterProperties($R);

		$remaining = $e['serialOffset'] + $e['serialSize'] - $R->tell();
		
		if ($remaining <= 0) 
			return null;

		// According to PDF: BYTE MipMapCount, then (sometimes) WidthOffset (>=63),
		// then INDEX MipMapSize + data ... Width/Height at tail
		$pos0    = $R->tell();
		$summary = ['mipmaps'=>null,'width'=>null,'height'=>null];

		if ($R->remaining() >= 1) {
			$summary['mipmaps'] = $R->u8();
		}
		// Best-effort: scan last 10 bytes of object range for Width/Height if present.
		// (Guarded: some packages omit these depending on version/format.)
		$save      = $R->tell();
		$tailStart = max($e['serialOffset'], $e['serialOffset'] + $e['serialSize'] - 10);
		$R->seek($tailStart);
		
		if ($R->remaining() >= 8) {
			$summary['width']  = $R->u32();
			$summary['height'] = $R->u32();
		}
		
		$R->seek($save);

		return $summary;
	}
	/*
	public function peekPaletteSummary(int $exportIndex): ?array {
		$e = $this->exports[$exportIndex] ?? null;
		if (!$e || ($e['serialSize'] ?? 0) <= 0) 
			return null;

		$R = clone $this->R;
		$R->seek((int)$e['serialOffset']);
		$this->peekAfterProperties($R);

		if ($R->remaining() < 4) 
			return null;
		
		$count = $R->index(); // PDF: INDEX PaletteSize
		// Don’t read all colors; just preview first up to 3 entries if present
		$preview = [];
		
		for ($i=0; $i<min(3, $count) && $R->remaining() >= 4; $i++) {
			$r         = ord($R->bytes(1)); 
			$g         = ord($R->bytes(1)); 
			$b         = ord($R->bytes(1)); 
			$a         = ord($R->bytes(1));
			$preview[] = sprintf('(%d,%d,%d,%d)', $r,$g,$b,$a);
		}
		
		return ['size'=>$count, 'preview'=>$preview];
	}
	*/
	/*
	public function peekTextBufferSummary(int $exportIndex): ?array {
		$e = $this->exports[$exportIndex] ?? null;
		
		if (!$e || ($e['serialSize'] ?? 0) <= 0) 
			return null;

		$R = clone $this->R;
		$R->seek((int)$e['serialOffset']);
		$this->peekAfterProperties($R);

		if ($R->remaining() < 8) 
			return null;
		
		$pos = $R->u32(); 
		$top = $R->u32(); // usually zero
		
		if ($R->remaining() < 4) 
			return ['pos'=>$pos,'top'=>$top];
		
		$len = $R->index(); // TextSize
		$txt = ($len>0 && $R->remaining() >= $len) ? $R->bytes($len) : '';
		$txt = rtrim($txt, "\x00");
		
		return ['pos'=>$pos,'top'=>$top,'bytes'=>$len,'preview'=>substr($txt,0,120)];
	}
	*/
	/*
	public function peekSoundSummary(int $exportIndex): ?array {
		$e = $this->exports[$exportIndex] ?? null;
		
		if (!$e || ($e['serialSize'] ?? 0) <= 0) 
			return null;

		$R = clone $this->R;
		$R->seek((int)$e['serialOffset']);
		$this->peekAfterProperties($R);

		if ($R->remaining() < 4) 
			return null;
		
		$fmtIdx = $R->index(); // Name index of "WAV" per PDF
		$fmt    = $this->nameByIndex($fmtIdx) ?? '';
		// Optional OffsetNext (>=63) — skip if present but we don’t need it
		if (($this->header['version'] ?? 0) >= 63 && $R->remaining() >= 4) { 
			$R->u32(); 
		}
		
		if ($R->remaining() < 4) 
			return ['format'=>$fmt];
		
		$sz = $R->index();
		
		return ['format'=>$fmt, 'size'=>$sz];
	}
	*/
	
	/** Peek the body of a UFunction export (after properties). */
	/*
	public function peekFunction(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$ver = (int)($this->header['version'] ?? 0);
		$out = [];

		// Function (inherits Struct)
		if ($ver <= 63) {
			if ($R->remaining() < 2) 
				return $out;
			
			$out['ParmsSize'] = $R->index(); // historically INDEX; many builds use WORD/INDEX
		}

		if ($R->remaining() < 2) 
			return $out;
		
		$out['iNative'] = $R->u16(); // WORD

		if ($ver <= 63) {
			if ($R->remaining() < 4) 
				return $out;
			
			$out['NumParms']           = $R->index();
			$out['OperatorPrecedence'] = $R->u8();
			$out['ReturnValueOffset']  = $R->index();
		}

		if ($R->remaining() >= 4) {
			$ff = $R->u32();
			$out['FunctionFlagsRaw'] = $ff;
			$out['FunctionFlags']    = $this->decodeFunctionFlags($ff);
		}
		
		if (!empty($out['FunctionFlags']) && in_array('FUNC_Net', $out['FunctionFlags'], true)) {
			if ($R->remaining() >= 2) {
				$out['ReplicationOffset'] = $R->u16();
			}
		}

		// Script starts here — capture a disassembly preview
		if ($R->remaining() > 0) {
			$out['ScriptPreview'] = $this->disasmScript($R, limit: 2048);
		}

		return $out;
	}
	*/

	/** Peek the body of a UState export (after properties). */
	/*public function peekState(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$out = [];
		// QWORD ProbeMask / IgnoreMask → read as two u32 each to avoid PHP int size issues
		if ($R->remaining() >= 8) {
			$lo = $R->u32(); $hi = $R->u32();
			$out['ProbeMask'] = [$lo, $hi];
		}
		
		if ($R->remaining() >= 8) {
			$lo = $R->u32(); $hi = $R->u32();
			$out['IgnoreMask'] = [$lo, $hi];
		}
		
		if ($R->remaining() >= 2) { 
			$out['LabelTableOffset'] = $R->u16(); 
		}
		
		if ($R->remaining() >= 4) {
			$sf = $R->u32();
			$out['StateFlagsRaw'] = $sf;
			$out['StateFlags']    = $this->decodeStateFlags($sf);
		}

		// Script follows; preview (optional)
		if ($R->remaining() > 0) {
			$out['ScriptPreview'] = $this->disasmScript($R, limit: 2048);
		}
		
		return $out;
	}
	*/

	/** Peek the body of a UClass export (the meta 'class' object), after properties. */
	/*
	public function peekClass(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$ver = (int)($this->header['version'] ?? 0);
		$out = [];

		if ($ver <= 61 && $R->remaining() >= 4) {
			$out['OldClassRecordSize'] = $R->u32();
		}
		
		if ($R->remaining() >= 4) {
			$cf = $R->u32();
			$out['ClassFlagsRaw'] = $cf;
			$out['ClassFlags']    = $this->decodeClassFlags($cf);
		}
		
		if ($R->remaining() >= 16) {
			$out['ClassGuid'] = bin2hex($R->bytes(16));
		}
		
		if ($R->remaining() >= 4) {
			$depCount            = $R->index();
			$out['Dependencies'] = $depCount;
			// Each dependency: INDEX ClassRef; DWORD Deep; DWORD ScriptTextCRC
			$deps = [];
			
			for ($i=0; $i<$depCount && $R->remaining() >= 12; $i++) {
				$cref   = $R->index();
				$deep   = $R->u32();
				$crc    = $R->u32();
				$deps[] = ['class'=>$this->displayNameFromRef($cref), 'deep'=>$deep, 'crc'=>$crc];
			}
			
			$out['DepsDetail'] = $deps;
		}
		// PackageImports (count + list):
		if ($R->remaining() >= 4) {
			$impCount = $R->index();
			$out['PackageImports'] = $impCount;
			$imps = [];
			
			for ($i=0; $i<$impCount && $R->remaining() >= 4; $i++) {
				$iref = $R->index();
				$imps[] = $this->displayNameFromRef($iref);
			}
			
			$out['ImportsDetail'] = $imps;
		}
		if ($ver >= 62) {
			if ($R->remaining() >= 4) {
				$within = $R->index();
				$out['ClassWithin'] = $this->displayNameFromRef($within);
			}
			if ($R->remaining() >= 4) {
				$cfg = $R->index();
				$out['ClassConfigName'] = $this->nameByIndex($cfg);
			}
		}

		// Script follows after header; preview
		if ($R->remaining() > 0) {
			$out['ScriptPreview'] = $this->disasmScript($R, limit: 2048);
		}
		
		return $out;
	}
	*/
	/*
	public function peekMusicSummary(int $exportIndex): ?array {
		$e = $this->exports[$exportIndex] ?? null;
		
		if (!$e || ($e['serialSize'] ?? 0) <= 0) 
			return null;

		$R = clone $this->R;
		$R->seek((int)$e['serialOffset']);
		$this->peekAfterProperties($R);

		// Minimal, per PDF: usually single chunk; format often first Name in package
		// Provide bytes remaining as rough size indicator
		$rem = max(0, ($e['serialOffset'] + $e['serialSize']) - $R->tell());
		
		return ['bytes'=>$rem];
	}
	*/
	
	private function readImportTable(): void
	{
		$count   = $this->header['importCount'];
		$offset  = $this->header['importOffset'];
		$version = (int)($this->header['version'] ?? 0);

		if ($version >= 334 && $this->isCompressed) {
			$R = $this->makeFullUncompressedReader();
			$R->seek($offset);
		} else {
			$R = $this->isCompressed ? $this->makeFullUncompressedReader() : $this->R;
			$R->seek($offset);
		}

		$imports = [];
		
		for ($i=0; $i<$count; $i++) {
			$classPackage = $R->index();   // name index
			$className    = $R->index();   // name index
			$outerIndex   = $R->i32();     // ref: 0/±
			$objectName   = $R->index();   // name index
			$imports[]    = compact('classPackage', 'className', 'outerIndex', 'objectName');
		}
		
		$this->imports = $imports;
	}

private function readExportTable(): void
{
    $count  = (int)($this->header['exportCount']  ?? 0);
    $offset = (int)($this->header['exportOffset'] ?? 0);
    $ver    = (int)($this->header['version']      ?? 0);

    if ($count <= 0 || $offset <= 0) { $this->exports = []; return; }

    if ($ver >= 334) {
        $this->exports = $this->readExportTableUE3();
        return;
    }

    // ---- UE1/UE2 baseline ----
    $R = $this->R;
    $R->seek($offset);

    $exports = [];
    for ($i = 0; $i < $count; $i++) {
        $classIndex   = $R->i32();
        $superIndex   = $R->i32();
        $outerIndex   = $R->i32();
        $objectName   = $R->i32();
        $objectFlags  = ($ver >= 195) ? (($R->u32() | ($R->u32() << 32))) : $R->u32();
        $serialSize   = $R->i32();
        $serialOffset = $serialSize > 0 ? $R->i32() : null;

        $raw = [
            'classIndex'   => $classIndex,
            'superIndex'   => $superIndex,
            'outerIndex'   => $outerIndex,
            'packageIndex' => $outerIndex, // viewer alias
            'objectName'   => $objectName,
            'objectFlags'  => $objectFlags,
            'serialSize'   => $serialSize,
            'serialOffset' => $serialOffset,
        ];
        $row = $raw; $row['raw'] = $raw;
        $exports[] = $row;
    }

    $this->exports = $exports;
}


// --- diagnostics (safe to remove later) ---
public function debugSummary(): array {
    return [
        'version'          => $this->header['version'] ?? null,
        'licenseeVersion'  => $this->header['licenseeVersion'] ?? null,
        'isCompressed'     => $this->isCompressed ?? null,
        'compressionFlags' => $this->compressionFlags ?? 0,
        'chunkCount'       => is_array($this->chunks ?? null) ? count($this->chunks) : 0,
        'nameCount'        => $this->header['nameCount'] ?? 0,
        'importCount'      => $this->header['importCount'] ?? 0,
        'exportCount'      => $this->header['exportCount'] ?? 0,
        'nameOffset'       => $this->header['nameOffset'] ?? 0,
        'importOffset'     => $this->header['importOffset'] ?? 0,
        'exportOffset'     => $this->header['exportOffset'] ?? 0,
    ];
}

public function debugFirstExports(int $n = 5): array {
    $out = [];
    for ($i = 0; $i < min($n, count($this->exports)); $i++) {
        $e = $this->exports[$i];
        $out[] = [
            'i'            => $i+1,
            'ClassIndex'   => $e['classIndex']   ?? null,
            'SuperIndex'   => $e['superIndex']   ?? null,
            'OuterIndex'   => $e['outerIndex']   ?? null,
            'ObjectName'   => $this->nameStr((int)($e['objectName'] ?? 0)),
            'SerialSize'   => $e['serialSize']   ?? null,
            'SerialOffset' => $e['serialOffset'] ?? null,
        ];
    }
    return $out;
}

	
/** Returns a UEReader bounded to exactly this export’s body (uncompressed space). */
private function readerForExportBody(array $ex): UEReader
{
    $start = (int)($ex['serialOffset'] ?? 0);
    $size  = (int)($ex['serialSize']  ?? 0);

    $r = ($this->isCompressed && !empty($this->chunks))
        ? $this->makeConcatUncompressedReader()
        : $this->R;

    // Isolate to export body; prevents accidental over-reads.
    if (method_exists($r, 'clone')) {
        $r = $r->clone();
    } else {
        // create a new uncompressed reader if available
        if ($this->isCompressed && !empty($this->chunks)) {
            $r = $this->makeConcatUncompressedReader();
        }
    }

    if (method_exists($r, 'setBounds')) {
        $r->setBounds($start, $start + $size);
        $r->seek($start);
    } else {
        $r->seek($start);
    }
    return $r;
}

	
private function classNameFromIndex(int $idx): string {
    $k = $this->refKind($idx);
    $i = $this->refIndex($idx);
    if ($k === 'import') {
        $im = $this->imports[$i] ?? null;
        if (!$im) return '';
        // import.ClassName is a FName index
        return $this->nameStr((int)($im['className'] ?? 0));
    }
    if ($k === 'export') {
        $ex = $this->exports[$i] ?? null;
        if (!$ex) return '';
        return $this->nameStr((int)($ex['objectName'] ?? 0));
    }
    return '';
}

private function outerNameFromIndex(int $idx): string {
    $k = $this->refKind($idx);
    $i = $this->refIndex($idx);
    if ($k === 'import') {
        $im = $this->imports[$i] ?? null;
        return $im ? $this->nameStr((int)($im['objectName'] ?? 0)) : '';
    }
    if ($k === 'export') {
        $ex = $this->exports[$i] ?? null;
        return $ex ? $this->nameStr((int)($ex['objectName'] ?? 0)) : '';
    }
    return '';
}
private function resolveRefKind(?int $i): string {
    if ($i === null) return 'none';
    if ($i === 0)    return 'none';
    return ($i > 0) ? 'export' : 'import';
}

private function resolveRefIndex(?int $i): ?int {
    if ($i === null) return null;
    if ($i === 0)    return null;           // truly none
    return ($i > 0) ? ($i - 1) : ((-$i) - 1);
}

private function resolveRefName(?int $i): ?string {
    $k = $this->resolveRefKind($i);
    $z = $this->resolveRefIndex($i);
    if ($k === 'none' || $z === null) return null;

    if ($k === 'import') {
        $im = $this->imports[$z] ?? null;
        if (!$im) return null;
        $on = $im['raw']['objectName'] ?? null;
        return $this->nameStrSafe($on);
    }
    if ($k === 'export') {
        $ex = $this->exports[$z] ?? null;
        if (!$ex) return null;
        $on = $ex['raw']['objectName'] ?? null;
        return $this->nameStrSafe($on);
    }
    return null;
}

private function resolveClassNameFromIndex(?int $idx): ?string {
    $k = $this->resolveRefKind($idx);
    $z = $this->resolveRefIndex($idx);
    if ($k === 'none' || $z === null) return null;

    if ($k === 'import') {
        $im = $this->imports[$z] ?? null;
        if (!$im) return null;
        $cn = $im['raw']['className'] ?? null; // FName index
        return $this->nameStrSafe($cn);
    }
    if ($k === 'export') {
        $ex = $this->exports[$z] ?? null;
        if (!$ex) return null;
        // For exports, commonly the object’s own name is used for class text if you don’t resolve forward
        $on = $ex['raw']['objectName'] ?? null;
        return $this->nameStrSafe($on);
    }
    return null;
}

private function outerChainFromIndex(?int $idx): array {
    $chain = [];
    $seen  = 0;
    $cur   = $idx;
    while ($cur !== null && $cur !== 0 && $seen < 256) {
        $name = $this->resolveRefName($cur);
        if ($name !== null) $chain[] = $name;

        // Move to the next outer:
        $k = $this->resolveRefKind($cur);
        $z = $this->resolveRefIndex($cur);
        if ($k === 'import') {
            $cur = $this->imports[$z]['raw']['outerIndex'] ?? null;
        } elseif ($k === 'export') {
            $cur = $this->exports[$z]['raw']['outerIndex'] ?? null;
        } else {
            $cur = null;
        }
        $seen++;
    }
    return $chain;
}

private function nameStrSafe($nameIndex): ?string {
    if ($nameIndex === null) return null;
    $i = (int)$nameIndex;
    // Assuming $this->names[$i]['raw']['name'] holds the string:
    $row = $this->names[$i] ?? null;
    return $row ? (string)($row['raw']['name'] ?? '') : null;
}


public function finalizeViews(): void
{
    // Names view (string + index)
    foreach ($this->names as $i => $n) {
        $s = (string)($n['raw']['name'] ?? '');
        $this->names[$i]['view'] = [
            'index' => $i,
            'text'  => $s,
        ];
    }

    // Imports view
    foreach ($this->imports as $i => $im) {
        $cp   = $im['raw']['classPackage'] ?? null;
        $cn   = $im['raw']['className']    ?? null;
        $oi   = $im['raw']['outerIndex']   ?? null;
        $on   = $im['raw']['objectName']   ?? null;

        $this->imports[$i]['view'] = [
            'classPackageText' => $this->resolveRefName($cp),       // class pkg is a name index
            'classNameText'    => $this->nameStrSafe($cn),
            'objectNameText'   => $this->nameStrSafe($on),
            'outerIndex'       => $oi,
            'outerChain'       => $this->outerChainFromIndex($oi),
        ];
    }

    // Exports view
    foreach ($this->exports as $i => $ex) {
        $ci  = $ex['raw']['classIndex']   ?? null; // signed ref
        $si  = $ex['raw']['superIndex']   ?? null; // signed ref (0 allowed)
        $pi  = $ex['raw']['outerIndex']   ?? null; // aka packageIndex
        $on  = $ex['raw']['objectName']   ?? null; // name index
        $flg = $ex['raw']['objectFlags']  ?? null;

        $this->exports[$i]['view'] = [
            'objectNameText'   => $this->nameStrSafe($on),
            'classKind'        => $this->resolveRefKind($ci),           // import/export/none
            'classIndex0'      => $this->resolveRefIndex($ci),          // zero-based
            'classNameText'    => $this->resolveClassNameFromIndex($ci),
            'superKind'        => $this->resolveRefKind($si),
            'superIndex0'      => $this->resolveRefIndex($si),
            'superNameText'    => $this->resolveRefName($si),
            'packageKind'      => $this->resolveRefKind($pi),
            'packageIndex0'    => $this->resolveRefIndex($pi),
            'packageNameText'  => $this->resolveRefName($pi),
            'outerChain'       => $this->outerChainFromIndex($pi),
            'flagsDecoded'     => $this->decodePKG((int)($flg ?? 0)),   // your existing decoder
        ];
    }
}


	
// --- UE3 export table reader: stable core fields, compressed + uncompressed ---
private function readExportTableUE3(): array
{
    $count  = (int)($this->header['exportCount']  ?? 0);
    $offset = (int)($this->header['exportOffset'] ?? 0);
    $ver    = (int)($this->header['version']      ?? 0);
    if ($count <= 0 || $offset <= 0) return [];

    // Always parse tables from the logical UNCOMPRESSED stream for UE3
    $R = ($this->isCompressed && !empty($this->chunks))
        ? $this->makeConcatUncompressedReader()
        : $this->R;

    $save = $R->tell();
    $R->seek($offset);

    $exports = [];

    for ($i = 0; $i < $count; $i++) {
        // C++ (UnPackage3):
        // ClassIndex, SuperIndex, PackageIndex(=Outer), ObjectName,
        // [Archetype if ver>=220],
        // ObjectFlags (low32), [ObjectFlags2 (hi64?) if ver>=195],
        // SerialSize, [SerialOffset if SerialSize>0 or ver>=249], then variant tails.
        $classIndex  = $R->i32();
        $superIndex  = $R->i32();
        $outerIndex  = $R->i32();
        $objectName  = $R->i32();
        $archetype   = ($ver >= 220) ? $R->i32() : null;

        $objFlags32  = $R->u32();              // always present
        $objFlagsHi  = ($ver >= 195) ? $R->u32() : 0;  // many builds store hi bits separately
        $objectFlags = ($objFlagsHi << 32) | $objFlags32;

        $serialSize   = $R->i32();
        $serialOffset = ($serialSize > 0 || $ver >= 249) ? $R->i32() : null;

        // We deliberately stop before per-title tails (component map, export flags, GUID, etc.)

        $raw = [
            'classIndex'    => $classIndex,
            'superIndex'    => $superIndex,
            'outerIndex'    => $outerIndex,
            'packageIndex'  => $outerIndex,   // viewer alias
            'objectName'    => $objectName,
            'archetype'     => $archetype,
            'objectFlags'   => $objectFlags,
            'serialSize'    => $serialSize,
            'serialOffset'  => $serialOffset,
        ];
        $row = $raw; $row['raw'] = $raw;
        $exports[] = $row;
    }

    $R->seek($save);
    return $exports;
}




	
/**** UE3 export table reader (handles compressed or not) ****/
/*
private function readExportTableUE3(): array
{
    // Use concat reader when compressed (avoid full-file buffers)
    if (!empty($this->isCompressed)) {
        if (method_exists($this, 'makeConcatUncompressedReader')) {
            $R = $this->makeConcatUncompressedReader();
        } elseif (method_exists($this, 'makeFullUncompressedReader')) {
            $R = $this->makeFullUncompressedReader(); // fallback only
        } else {
            $R = $this->R;
        }
    } else {
        $R = $this->R;
    }

    $count  = (int)($this->header['exportCount']  ?? 0);
    $offset = (int)($this->header['exportOffset'] ?? 0);
    if ($count <= 0 || $offset <= 0) return [];

    $save = $R->tell();
    $R->seek($offset);

    $exports = [];
    for ($i = 0; $i < $count; $i++) {
        $entryStart = $R->tell();

        // ---- UE3 field widths (order matters) ----
        $classIndex   = $R->index();  // INDEX ref
        $superIndex   = $R->index();  // INDEX ref
        $packageIndex = $R->index();  // INDEX ref (UE3: NOT DWORD)
        $objectName   = $R->index();  // INDEX (name table)
        $objectFlags  = $R->u64();    // QWORD (64-bit)
        $serialSize   = $R->index();  // INDEX
        $serialOffset = ($serialSize > 0) ? $R->index() : 0;

        // ---- UE3 tail: prevents drift into next record ----
        $exportFlags  = ($R->remaining() >= 4) ? $R->u32() : 0;
        $archetype    = ($R->remaining() >= 1) ? $R->index() : 0;

        $exports[] = [
            'classIndex'   => $classIndex,
            'superIndex'   => $superIndex,
            'packageIndex' => $packageIndex,
            'objectName'   => $objectName,
            'objectFlags'  => $objectFlags,
            'serialSize'   => $serialSize,
            'serialOffset' => $serialOffset,
            'exportFlags'  => $exportFlags,
            'archetype'    => $archetype,
        ];

        // Forward progress guard (debug)
        if ($R->tell() <= $entryStart) break;
    }

    $R->seek($save);
    return $exports;
}
*/




    private function readNAME(UEReader $R, int $version): string
	{
		if ($version < 64) { // ASCIIZ
			$s = '';
			
			while (true) {
				$c = $R->u8();
				if ($c === 0) break;
				$s .= chr($c);
			}
			
			return $s;
		}

		// UE2 style: length is CompactIndex (>117) or u8 (<=117)
		$len = ($version > 117) ? $R->index() : $R->u8();

		if ($len === 0) 
			return '';

		if ($len < 0) {	// UTF-16LE; length is in 16-bit code units incl. the terminator
			$bytes = $R->bytes(-$len * 2);
			$s     = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes) ?: '';
			
			return rtrim($s, "\x00"); //rtrim($s ?? '', " "); // Defensive: drop any trailing NUL that may survive conversion
		} else { // ANSI; length includes the trailing NUL byte
			$bytes = $R->bytes($len);
			
		// Drop exactly one trailing NUL if present
		if ($len > 0 && $bytes[$len - 1] === "\x00") {
			$bytes = substr($bytes, 0, $len - 1);
		}
			
			return $bytes;
		}
	}

	public function readMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$out = ['Vertices'=>[], 'Triangles'=>[], 'Scale'=>null, 'Origin'=>null];

		if ($R->remaining() < 4) 
			return $out;
		
		$vertCount = $R->index();
		
		for ($i=0; $i<$vertCount && $R->remaining() >= 12; $i++) {
			$x = $R->f32(); 
			$y = $R->f32(); 
			$z = $R->f32();
			$out['Vertices'][] = [$x,$y,$z];
		}

		if ($R->remaining() >= 4) {
			$triCount = $R->index();
			
			for ($i=0; $i<$triCount && $R->remaining() >= 6; $i++) {
				$v0 = $R->u16(); 
				$v1 = $R->u16(); 
				$v2 = $R->u16();
				$out['Triangles'][] = [$v0,$v1,$v2];
			}
		}

		// Optional scale/origin vectors (if present)
		if ($R->remaining() >= 12) {
			$out['Scale'] = [$R->f32(), $R->f32(), $R->f32()];
		}
		if ($R->remaining() >= 12) {
			$out['Origin'] = [$R->f32(), $R->f32(), $R->f32()];
		}

		return $out;
	}
	// NEW: read all UE3 properties for a given export index.
	// Does NOT alter your UE1/UE2 reader. Returns an array of tags in source order.
	// Robust UE3 property reader: guards every read, never overruns, stops cleanly on 'None'.
	private function readPropertiesForExportUE3(int $exportIndex): array
	{
		$R = $this->makeExportDataReaderUE3($exportIndex);
		$props = [];

		while (true) {
			// NEW: remember the start of this tag (relative to the export body reader)
			$tagStart = $R->tell();
			
			// Need at least 8 bytes for a Name FName
			if ($this->_rem($R) < 8) break;

			// --- Name (FName) ---
			try {
				$name = $this->_readFNameStruct($R);
			} catch (\OutOfBoundsException $e) {
				break; // truncated tag -> stop safely
			}

			// Terminator
			if ($name['base'] === 'None') break;

			// Need at least 8 more bytes for Type FName
			if ($this->_rem($R) < 8) break;

			// --- Type (FName) ---
			try {
				$type = $this->_readFNameStruct($R);
			} catch (\OutOfBoundsException $e) {
				break;
			}

			// Need at least 8 bytes for Size + ArrayIndex
			if ($this->_rem($R) < 8) break;

			// --- Size + ArrayIndex ---
			$size       = $R->i32();
			$arrayIndex = $R->i32();
			if ($size < 0) {
				// corrupt tag; stop rather than overrun
				break;
			}

			// --- BoolProperty special byte right after header (no payload) ---
			if ($type['base'] === 'BoolProperty') {
				if ($this->_rem($R) < 1) {
					// malformed bool tag; stop
					break;
				}
				$boolVal = ($R->u8() !== 0);
				$props[] = [
					'name'        => $name['text'],
					'type'        => 'BoolProperty',
					'value'       => $boolVal,
					'arrayIndex'  => $arrayIndex,					
					'value'       => $value,
					'offset'      => $currentOffset,
					'raw'         => bin2hex($rawBytes),
					'len'         => strlen($rawBytes),
					'struct'      => $structName,		
					'size'        => 0,      // bool payload is in the header byte	
					'isArray'     => ($arrayIndex > 0 ? 'Yes' : 'No'),
					'idx'         => ($arrayIndex > 0 ? $arrayIndex : null),
					'idxFromFile' => ($arrayIndex > 0),		
					'offset'      => $tagStart,
					'length'      => ($tagEnd - $tagStart),					
				];
				continue;
			}

			// For Struct/Byte/Array there is a small type-meta BEFORE payload; account for it.
			// We won't parse deeply here—just consume the meta so payload boundaries stay correct.
			$prePayloadMeta = 0;
			$meta = [];

			if ($type['base'] === 'StructProperty') {
				// StructName FName precedes payload
				if ($this->_rem($R) < 8) break;
				$sname = $this->_readFNameStruct($R);
				$meta['struct'] = $sname['text'];
				$prePayloadMeta = 0; // UE3 counts only payload bytes in Size (struct data), keep 0
			} elseif ($type['base'] === 'ByteProperty') {
				// EnumName FName may precede payload in UE3 (often 'None' if raw byte)
				if ($this->_rem($R) >= 8) {
					$enumName = $this->_readFNameStruct($R);
					$meta['enum'] = $enumName['text'];
				}
			} elseif ($type['base'] === 'ArrayProperty') {
				// InnerType FName precedes payload (element type)
				if ($this->_rem($R) < 8) break;
				$inner = $this->_readFNameStruct($R);
				$meta['inner'] = $inner['text'];
			}

			// Finally, read exactly $size bytes as the payload (if available), using a type-aware reader where possible
			if ($this->_rem($R) < $size) {
				// truncated payload; stop to avoid overrun
				break;
			}

			// Type-specific parse (bounded to $size). Unknown types -> raw bytes.
			$value = null;
			$data  = null;
			$startPos = method_exists($R, 'tell') ? $R->tell() : null;

			switch ($type['base']) {
				case 'IntProperty':
					if ($size >= 4) { $value = $R->i32(); $consumed = 4; }
					break;

				case 'FloatProperty':
					if ($size >= 4) {
						$value = method_exists($R,'f32') ? $R->f32() : (unpack('g', $R->bytes(4))[1]);
						$consumed = 4;
					}
					break;

				case 'NameProperty':
					if ($size >= 8) {
						$nm = $this->_readFNameStruct($R);
						$value = $nm['text'];
						$data  = ['name'=>$nm];
						$consumed = 8;
					}
					break;

				case 'StrProperty':
					// FString inside payload; let your UTF-8 reader handle it; then skip any remainder
					$value = method_exists($this,'readFStringFrom') ? $this->readFStringFrom($R) : (method_exists($R,'cstr') ? $R->cstr() : '');
					$consumed = (method_exists($R, 'tell') && $startPos !== null) ? ($R->tell() - $startPos) : 0;
					break;

				case 'ObjectProperty':
				case 'ComponentProperty':
					if ($size >= 4) {
						$ref = $R->i32();
						$value = $this->displayNameFromRef($ref);
						$data  = ['ref'=>$ref];
						$consumed = 4;
					}
					break;

				case 'ByteProperty':
					if ($size === 1) {
						$value = $R->u8();
						$consumed = 1;
					} elseif ($size >= 8) {
						// enum value as FName
						$nm = $this->_readFNameStruct($R);
						$value = $nm['text'];
						$data  = ['name'=>$nm];
						$consumed = 8;
					}
					break;

				case 'StructProperty':
				case 'ArrayProperty':
					// We don’t parse nested payloads here; keep raw but bounded.
					$data = ['raw' => ($size > 0 ? $R->bytes($size) : '')] ;
					$consumed = $size;
					break;

				default:
					// Unknown type: consume raw bytes
					$data = ['raw' => ($size > 0 ? $R->bytes($size) : '')];
					$consumed = $size;
					break;
			}

			// If type-specific reader consumed fewer than $size, skip the remainder so we stay aligned
			if (isset($consumed) && $consumed < $size) {
				$skip = $size - $consumed;
				if ($skip > 0) $R->bytes($skip);
			}
			$tagEnd = $R->tell(); // after consuming the bool byte

			$props[] = array_filter([
			
				'offset'      => $tagStart,
				'length'      => ($tagEnd - $tagStart),
				'struct'      => isset($meta['struct']) ? (string)$meta['struct'] : '',
				'isArray'     => ($arrayIndex > 0 ? 'Yes' : 'No'),
				'idx'         => ($arrayIndex > 0 ? $arrayIndex : null),
				'idxFromFile' => ($arrayIndex > 0),			
				'name'       => $name['text'],
				'type'       => $type['base'],
				'value'      => $value,
				'meta'       => $meta ?: null,
				'data'       => $data,
				'size'       => $size,
				'arrayIndex' => $arrayIndex,
			], fn($v) => $v !== null && $v !== []);
		}

		return $props;
	}
	
	/*
	 * Parse just the serialized properties for a single export (bounded).
	 * Returns ['PropName' => mixed, ...]. Unknown types are returned as ['raw'=>hex,'len'=>N,'type'=>code].
	 */
	public function readPropertiesForExport(int $exportIndex): array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return [];

		$props = [];
		// read until Name == 'None'
		while ($R->remaining() > 0) {
			$nameIdx = $R->index();
			$nameStr = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') 
				break;

			$info      = $R->u8();
			$typeCode  = $info & 0x0F;
			$sizeCode  = ($info >> 4) & 0x07;
			$isArrayIx = (($info & 0x80) !== 0) && ($typeCode !== 0x03); // 0x03 = BoolProperty (bool uses the bit)

			// If struct, the next INDEX is the struct name (we keep it for context)
			$structName = null;
			if ($typeCode === 0x0A && $R->remaining() > 0) {
				$structName = $this->nameByIndex($R->index());
			}

			// Array index field (packed) if present
			if ($isArrayIx) {
				// decode packed index length (we don't actually need the value—just advance)
				$b = $R->u8();
				if (($b & 0x80) === 0x00) {
					// 1 byte total
				} elseif (($b & 0xC0) === 0x80) {
					$R->u8(); // 2 bytes total
				} else {
					$R->u16(); 
					$R->u8(); // 4 bytes total
				}
			}

			// Compute payload length
			$payloadLen = match ($sizeCode) {
				0 => 1, 1 => 2, 2 => 4, 3 => 12, 4 => 16,
				5 => $R->u8(),
				6 => $R->u16(),
				7 => $R->u32(),
			};

			// Decode known types, otherwise store raw
			$val = null;
			
			switch ($typeCode) {
				case 0x00: // (Guard) None – UE packages use Name=="None" as the true terminator.
					$val = null; // Shouldn't normally occur if your outer loop stops on Name "None".
					break;

				case 0x01: // ByteProperty
					$val = ($payloadLen === 1 && $R->remaining() >= 1) ? $R->u8() : $R->bytes($payloadLen);
					break;

				case 0x02: // IntProperty
					$val = ($payloadLen === 4 && $R->remaining() >= 4) ? $R->i32() : $R->bytes($payloadLen);
					break;

				case 0x03: // BoolProperty (value is in the high bit of the info byte)
					$val = (($info & 0x80) !== 0);
					break;

				case 0x04: // FloatProperty
					$val = ($payloadLen === 4 && $R->remaining() >= 4) ? $R->f32() : $R->bytes($payloadLen);
					break;

				case 0x05: // ObjectProperty (index)
					if ($payloadLen > 0 && $R->remaining() > 0) {
						$ix   = $R->index();
						$val  = $this->displayNameFromRef($ix) ?? $ix;
						$left = $payloadLen - 0; // index() consumes varint; we don't know exact byte count here
						
						if ($left > 0 && $R->remaining() >= $left) 
							$R->skip($left);
					} else { 
						$val  = null; 
					}
					break;

				case 0x06: // NameProperty (index)
					if ($payloadLen > 0 && $R->remaining() > 0) {
						$ix   = $R->index();
						$val  = $this->nameByIndex($ix);
						$left = $payloadLen - 0;
						
						if ($left > 0 && $R->remaining() >= $left) 
							$R->skip($left);
					} else { 
						$val  = null; 
					}
					break;

				case 0x07: // StringProperty (length already reflected by sizeCode/payloadLen)
				case 0x0D: // StrProperty (older/alt spelling)
					if ($payloadLen > 0 && $R->remaining() >= $payloadLen) {
						$bytes = $R->bytes($payloadLen);
						$val   = rtrim($bytes, "\x00");
					} else { 
						$val   = ''; 
					}
					break;

				case 0x08: // ClassProperty (index to class object) – treat like ObjectProperty
					if ($payloadLen > 0 && $R->remaining() > 0) {
						$ix   = $R->index();
						$val  = $this->displayNameFromRef($ix) ?? $ix;
						$left = $payloadLen - 0;
						
						if ($left > 0 && $R->remaining() >= $left) 
							$R->skip($left);
					} else { $val = null; }
					break;

				case 0x09: // ArrayProperty – inner layout varies; keep raw bytes safely
					$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Array'] : ['raw' => '', 'len' => 0, 'type' => 'Array'];
					break;

				case 0x0A: // StructProperty – you already read $structName before size
					if ($payloadLen > 0 && $R->remaining() >= $payloadLen) {
						if (strcasecmp($structName, 'Vector') === 0 && $payloadLen >= 12) {
							$val  = ['x' => $R->f32(), 'y' => $R->f32(), 'z' => $R->f32()];
							$left = $payloadLen - 12; 
							
							if ($left > 0) 
								$R->skip($left);
						} else if (strcasecmp($structName, 'Rotator') === 0 && $payloadLen >= 12) {
							$val  = ['pitch' => $R->i32(), 'yaw' => $R->i32(), 'roll' => $R->i32()];
							$left = $payloadLen - 12; 
							
							if ($left > 0) 
								$R->skip($left);
						} else {
							$val = ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'struct' => $structName];
						}
					} else { 
						$val = null; 
					}
					break;

				case 0x0B: // VectorProperty – some packages serialize as Struct(Vector)
					if ($payloadLen >= 12 && $R->remaining() >= 12) {
						$val  = ['x' => $R->f32(), 'y' => $R->f32(), 'z' => $R->f32()];
						$left = $payloadLen - 12; if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
					} else {
						$val  = ($payloadLen > 0) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Vector'] : null;
					}
					break;

				case 0x0C: // RotatorProperty – some packages serialize as Struct(Rotator)
					if ($payloadLen >= 12 && $R->remaining() >= 12) {
						$val  = ['pitch' => $R->i32(), 'yaw' => $R->i32(), 'roll' => $R->i32()];
						$left = $payloadLen - 12; if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
					} else {
						$val  = ($payloadLen > 0) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Rotator'] : null;
					}
					break;

				case 0x0E: // MapProperty – opaque
					$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Map']	: ['raw' => '', 'len' => 0, 'type' => 'Map'];
					break;

				case 0x0F: // FixedArrayProperty – opaque without element schema
					$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'FixedArray'] : ['raw' => '', 'len' => 0, 'type' => 'FixedArray'];
					break;

				default: // Unknown/unsupported
					$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => $typeCode]	: null;
					break;
			}

			$props[$nameStr] = $val;
		}

		return $props;
	}
	
	
	

	public function readLodMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$out = ['LODs'=>[], 'BoundingBox'=>null, 'BoundingSphere'=>null];

		// BoundingBox
		if ($R->remaining() >= 25) {
			$min   = [$R->f32(), $R->f32(), $R->f32()];
			$max   = [$R->f32(), $R->f32(), $R->f32()];
			$valid = (bool)$R->u8();
			$out['BoundingBox'] = ['Min'=>$min, 'Max'=>$max, 'Valid'=>$valid];
		}

		// BoundingSphere
		if ($R->remaining() >= 16) {
			$center = [$R->f32(), $R->f32(), $R->f32()];
			$radius = $R->f32();
			$out['BoundingSphere'] = ['Center'=>$center, 'Radius'=>$radius];
		}

		// Vertex count + preview first few
		if ($R->remaining() >= 4) {
			$vertCount = $R->index();
			$out['VertexCount'] = $vertCount;
			$verts     = [];
			
			for ($i=0; $i<min($vertCount, 10) && $R->remaining() >= 12; $i++) {
				$verts[] = [$R->f32(), $R->f32(), $R->f32()];
			}
			
			$out['VertexPreview'] = $verts;
		}

		// Material list (optional)
		if ($R->remaining() >= 4) {
			$matCount = $R->index();
			$mats     = [];
			
			for ($i=0; $i<$matCount && $R->remaining() >= 4; $i++) {
				$ix     = $R->index();
				$mats[] = $this->displayNameFromRef($ix);
			}
			
			$out['Materials'] = $mats;
		}

		return $out;
	}

/** Resolve a package object reference to a display name.
 *  0         => ''
 *  <0 (imp)  => imports[-ref-1]
 *  >0 (exp)  => exports[ ref-1]
 */
private function _refToDisplayName(int $ref): string
{
    if ($ref === 0) return '';

    if ($ref < 0) {
        $j = -$ref - 1;
        if (!isset($this->imports[$j])) return "__BADIMPORT[$j]__";
        $im  = $this->imports[$j];
        $val = $im['objectName'] ?? ($im['ObjectName'] ?? ($im['ObjectNameText'] ?? null));
        if (is_int($val))       return $this->nameStr($val);
        if (is_array($val))     return (string)($val['text'] ?? $val['base'] ?? $val['name'] ?? '');
        if (is_string($val))    return $val;
        return '';
    }

    // $ref > 0  (export)
    $e = $ref - 1;
    if (!isset($this->exports[$e])) return "__BADEXPORT[$e]__";
    $ex  = $this->exports[$e];
    $val = $ex['objectName'] ?? ($ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? null));
    if (is_int($val))       return $this->nameStr($val);
    if (is_array($val))     return (string)($val['text'] ?? $val['base'] ?? $val['name'] ?? '');
    if (is_string($val))    return $val;
    return '';
}

/** Build a dotted chain of outers for an import (e.g., UTGame.UTWeapon). */
private function _importOuterChain(int $importIndex): string
{
    if (!isset($this->imports[$importIndex])) return '';
    $parts = [];
    $seen  = 0;
    $outer = (int)($this->imports[$importIndex]['outerIndex'] ?? ($this->imports[$importIndex]['OuterIndex'] ?? 0));

    // Follow import-outers until null or we hit an export; cap depth for safety
    while ($outer !== 0 && $seen < 32) {
        $seen++;
        if ($outer < 0) {
            $j = -$outer - 1;
            if (!isset($this->imports[$j])) break;
            $name = $this->_refToDisplayName($outer);
            if ($name !== '') $parts[] = $name;
            $outer = (int)($this->imports[$j]['outerIndex'] ?? ($this->imports[$j]['OuterIndex'] ?? 0));
        } else {
            // Export as the top; include it then stop
            $name = $this->_refToDisplayName($outer);
            if ($name !== '') $parts[] = $name;
            break;
        }
    }
    $parts = array_values(array_filter($parts, fn($s)=>$s!==''));
    return implode('.', array_reverse($parts));
}
/** Resolve any object reference (import/export/null) to a display string. */
private function _resolveRefName(int $ref): string
{
    // delegate to the existing logic (whatever it’s named/located in your class)
    // if displayNameFromRef is private in an older build, call the tolerant resolver:
    if (method_exists($this, 'displayNameFromRef')) {
        // if it’s public now, this works; if not, we’ll fall back below
        try { return $this->displayNameFromRef($ref); } catch (\Throwable $e) {}
    }
	
    if ($ref === 0) 
		return '';

    if ($ref < 0) { // import
        $j = -$ref - 1;
		
        if (!isset($this->imports[$j])) 
			return "__BADIMPORT[$j]__";
		
        $im  = $this->imports[$j];
        $val = $im['objectName'] ?? ($im['ObjectName'] ?? ($im['ObjectNameText'] ?? null));
		
        if (is_int($val))    
			return $this->nameStr($val);
		
        if (is_array($val))  
			return (string)($val['text'] ?? $val['base'] ?? $val['name'] ?? '');
		
        if (is_string($val)) 
			return $val;
		
        return '';
    }

    // export
    $e = $ref - 1;
	
    if (!isset($this->exports[$e])) 
		return "__BADEXPORT[$e]__";
	
    $ex  = $this->exports[$e];
    $val = $ex['objectName'] ?? ($ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? null));
	
    if (is_int($val))    
		return $this->nameStr($val);
	
    if (is_array($val))  
		return (string)($val['text'] ?? $val['base'] ?? $val['name'] ?? '');
	
    if (is_string($val)) 
		return $val;
	
    return '';
}

private function _isValidUE3ExportEntry(array $row, int $nameCount, int $impCount, int $expCount): bool
{
    $n = $row['objectName'] ?? -1;
    return (is_int($n) && $n >= 0 && $n < $nameCount);
}

/*

private function _isValidUE3ExportEntry(array $row, int $nameCount, int $impCount, int $expCount): bool
{
    // objectName must be a valid name-table index
    $n = $row['objectName'] ?? -1;
    if (!is_int($n) || $n < 0 || $n >= $nameCount) return false;

    // Class/Super/Package/Archetype must be plausible object refs
    // Valid refs are 0, negative (imports) or positive (exports):
    //   import j  => - (j + 1), so min is -impCount .. -1
    //   export k  => (k + 1),   so 1 .. expCount
    $maxRef = max($impCount, $expCount) + 1; // +1 for the +1/-1 encoding
    foreach (['classIndex','superIndex','packageIndex','archetype'] as $k) {
        if (!array_key_exists($k, $row)) continue;
        $v = $row[$k];
        if (!is_int($v)) return false;
        if ($v !== 0 && abs($v) > $maxRef) return false;
    }
    return true;
}
*/

/**
 * Try one concrete UE3 layout variant; return [row, bytesRead] or [null, 0] if invalid.
 * $variant: 1 => package=index(), tail=exportFlags+archetype
 *           2 => package=i32(),   tail=exportFlags+archetype
 *           3 => package=index(), tail=none
 */
private function _tryUE3ExportVariant($R, int $variant, int $nameCount, int $impCount, int $expCount)
{
    $start = $R->tell();

    // Common head
    $classIndex = $R->index();
    $superIndex = $R->index();

    // Package: differs by variant
    $packageIndex = ($variant === 2) ? $R->i32() : $R->index();

    // Name index (FName index)
    $objectName  = $R->index();

    // 64-bit flags in UE3
    $objectFlags = $R->u64();

    // Serial block
    $serialSize   = $R->index();
    $serialOffset = ($serialSize > 0) ? $R->index() : 0;

    // Tail
    $exportFlags = 0;
    $archetype   = 0;
    if ($variant === 1 || $variant === 2) {
        if ($R->remaining() >= 4)     $exportFlags = $R->u32();
        if ($R->remaining() >= 1)     $archetype   = $R->index();
    }

    $row = [
        'classIndex'   => $classIndex,
        'superIndex'   => $superIndex,
        'packageIndex' => $packageIndex,
        'objectName'   => $objectName,
        'objectFlags'  => $objectFlags,
        'serialSize'   => $serialSize,
        'serialOffset' => $serialOffset,
    ];
    if ($variant === 1 || $variant === 2) {
        $row['exportFlags'] = $exportFlags;
        $row['archetype']   = $archetype;
    }

    // Validate
	if (!$this->_isValidUE3ExportEntry($row, $nameCount, $impCount, $expCount)) {
		echo "<pre>Invalid export at {$start}, variant {$variant}\n";
		print_r($row);
		echo "</pre>";
		$R->seek($start);
		return [null, 0];
	}


    $readBytes = $R->tell() - $start;
    return [$row, $readBytes];
}

/** Post-pass: resolve common reference fields inside every export row. */
private function resolveExportRefs(): void
{
    if (empty($this->exports)) 
		return;

    foreach ($this->exports as $i => &$ex) {
        $ex['classNameResolved']   = $this->_resolveRefName((int)($ex['classIndex']   ?? $ex['ClassIndex']   ?? 0));
        $ex['superNameResolved']   = $this->_resolveRefName((int)($ex['superIndex']   ?? $ex['SuperIndex']   ?? 0));
        $ex['outerNameResolved']   = $this->_resolveRefName((int)($ex['outerIndex']   ?? $ex['OuterIndex']   ?? 0));
        $ex['packageNameResolved'] = $this->_resolveRefName((int)($ex['packageIndex'] ?? $ex['PackageIndex'] ?? 0));
        // optional: some UE3 builds include Archetype
        if (isset($ex['archetypeIndex']) || isset($ex['ArchetypeIndex'])) {
            $ex['archetypeNameResolved'] = $this->_resolveRefName((int)($ex['archetypeIndex'] ?? $ex['ArchetypeIndex']));	
        }
		
		if (isset($ex['archetype'])) {
			$ex['archetypeNameResolved'] = $this->_resolveRefName((int)$ex['archetype']);
		}
    }
    unset($ex);
}


/** Post-pass: fill resolved outer fields *in-place* on every import row. */
private function resolveImportOuters(): void
{
    if (empty($this->imports)) 
		return;

    foreach ($this->imports as $i => &$im) {
        $oref = (int)($im['outerIndex'] ?? ($im['OuterIndex'] ?? 0));
        $im['outerName']  = $this->_refToDisplayName($oref);   // e.g., "UTWeapon"
        $im['outerChain'] = $this->_importOuterChain((int)$i); // e.g., "UTGame.UTWeapon"
        // Optional: stable alias if you prefer predictable casing
        $im['outerIndexResolved'] = $im['outerName'];
    }
    unset($im);
}


	// NEW: UE3-only import reader. Does not modify your UE1/UE2 readImportTable().
	private function readImportTableUE3(): void
	{
		// If already read (UE3), don't duplicate
		if (isset($this->imports) && is_array($this->imports) && ($this->importsUE3 ?? false)) {
			return;
		}

		$R            = $this->makeConcatUncompressedReader();
		$importCount  = (int)($this->header['ImportCount']  ?? $this->header['importCount']  ?? 0);
		$importOffset = (int)($this->header['ImportOffset'] ?? $this->header['importOffset'] ?? 0);

		if ($importCount < 0 || $importOffset < 0) {
			throw new \RuntimeException("Invalid ImportCount/ImportOffset");
		}

		$this->imports    = [];
		$this->importsUE3 = true;

		if ($importCount === 0) return;

		$R->seek($importOffset);

		for ($i = 0; $i < $importCount; $i++) {
			// UE3 layout:
			// FName ClassPackage; FName ClassName; int32 OuterIndex; FName ObjectName;
			$classPackage = $this->readFNameStruct($R);
			$className    = $this->readFNameStruct($R);
			//$outerIndex   = $this->readFNameStruct($R);
			$outerIndex   = $R->i32();                 // PackageIndex (0 / <0 import / >0 export)
			$objectName   = $this->readFNameStruct($R);
						
			// ---- New: resolve OuterIndex immediately ----
			if ($outerIndex === 0) {
				$im['outerName'] = '';
			} elseif ($outerIndex < 0) {
				$ix = (-$outerIndex) - 1;
				if (isset($this->imports[$ix])) {
					$outer = $this->imports[$ix];
					$val   = $outer['objectName'] ?? ($outer['ObjectName'] ?? ($outer['ObjectNameText'] ?? null));
					if (is_int($val)) {
						$im['outerName'] = $this->nameStr($val);
					} elseif (is_array($val)) {
						$im['outerName'] = $val['text'] ?? $val['base'] ?? $val['name'] ?? '';
					} elseif (is_string($val)) {
						$im['outerName'] = $val;
					} else {
						$im['outerName'] = '';
					}
				} else {
					$im['outerName'] = "__BADIMPORT[" . $ix . "]__";
				}
			} else { // $outerIndex > 0 → export reference
				$ix = $outerIndex - 1;
				if (isset($this->exports[$ix])) {
					$ex = $this->exports[$ix];
					$val = $ex['objectName'] ?? ($ex['ObjectName'] ?? ($ex['ObjectNameText'] ?? null));
					if (is_int($val)) {
						$im['outerName'] = $this->nameStr($val);
					} elseif (is_array($val)) {
						$im['outerName'] = $val['text'] ?? $val['base'] ?? $val['name'] ?? '';
					} elseif (is_string($val)) {
						$im['outerName'] = $val;
					} else {
						$im['outerName'] = '';
					}
				} else {
					$im['outerName'] = "__BADEXPORT[" . $ix . "]__";
				}
			}
			

			// Store both raw and convenience strings so UI code can be simple
			$this->imports[] = [
				'ClassPackage'     => $classPackage,          // raw FName struct
				'ClassName'        => $className,             // raw FName struct
				'OuterIndex'       => $outerIndex,
				'ObjectName'       => $objectName,            // raw FName struct
				// Convenience fields (strings) — IMPORTANT: never null
				'classPackageName' => $classPackage['text'],
				'className'        => $className['text'],
				'outerIndex'       => $outerIndex,
				'objectName'       => $objectName['text'],			
			];
		}
	}
	/**
	 * Read LodMesh geometry arrays:
	 * - Points: float[3] per vertex (reference geometry)
	 * - Wedges: { vertexIndex: u16, u: float, v: float } with u=S/255, v=1 - T/255
	 * - Faces:  { w1,w2,w3, materialIndex }
	 * - Materials: array of object refs (textures/materials)
	 *
	 * Returns null on failure; otherwise an assoc array with counts + arrays.
	 */
	public function readLodMeshGeometry(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = [
			'Points'    => [],
			'Wedges'    => [],
			'Faces'     => [],
			'Materials' => [],
			'Counts'    => ['Points'=>0,'Wedges'=>0,'Faces'=>0,'Materials'=>0],
		];

		// --- Points (aka Verts / Points) ---
		if ($R->remaining() < 4) return $out;
		$pointsCount = $R->index();
		$out['Counts']['Points'] = $pointsCount;

		for ($i=0; $i<$pointsCount && $R->remaining() >= 12; $i++) {
			$x = $R->f32(); $y = $R->f32(); $z = $R->f32();
			$out['Points'][] = [$x,$y,$z];
		}
		// If truncated, bail gracefully (we keep what we got)

		// --- Wedges (UVs + vertex index) ---
		if ($R->remaining() < 4) return $out;
		$wedgeCount = $R->index();
		$out['Counts']['Wedges'] = $wedgeCount;

		for ($i=0; $i<$wedgeCount && $R->remaining() >= 4; $i++) {
			$vIndex = $R->u16();               // WORD VertexIndex
			$S      = ord($R->bytes(1));       // BYTE S = U (0..255)
			$T      = ord($R->bytes(1));       // BYTE T = 255 - V (PDF)
			// Convert to normalized UVs: U = S/255, V = 1 - T/255
			$u = $S / 255.0;
			$v = 1.0 - ($T / 255.0);
			$out['Wedges'][] = ['vertexIndex'=>$vIndex, 'u'=>$u, 'v'=>$v];
		}

		// --- Faces (triangles by wedge indices + materialIndex) ---
		if ($R->remaining() < 4) return $out;
		$faceCount = $R->index();
		$out['Counts']['Faces'] = $faceCount;

		for ($i=0; $i<$faceCount && $R->remaining() >= 8; $i++) {
			$w1 = $R->u16();
			$w2 = $R->u16();
			$w3 = $R->u16();
			$matIndex = $R->u16(); // WORD MaterialIndex (per PDF)
			$out['Faces'][] = ['w1'=>$w1,'w2'=>$w2,'w3'=>$w3,'materialIndex'=>$matIndex];
		}

		// --- Materials (INDEX refs -> names) ---
		if ($R->remaining() >= 4) {
			$matCount = $R->index();
			$out['Counts']['Materials'] = $matCount;
			for ($i=0; $i<$matCount && $R->remaining() >= 4; $i++) {
				$ix = $R->index();
				$out['Materials'][] = $this->displayNameFromRef($ix);
			}
		}

		return $out;
	}
	
	// Reads a UE3 FString from the current reader position.
	// len>0  : ANSI bytes (including 1-byte null), return sans trailing null
	// len<0  : UTF-16LE words (including 2-byte null), return UTF-8 sans trailing null
	function readUENameString(UEReader $R): string
	{
		// If we don't have 4 bytes to peek a length, fallback to ASCIIZ
		$save = $R->tell();
		if (method_exists($R, 'remaining') && $R->remaining() < 4) {
			return $R->cstr();
		}

		$len = $R->i32(); // signed

		// ANSI FString
		if ($len > 0) {
			if (method_exists($R, 'remaining') && $R->remaining() >= $len && $len <= 1_000_000) {
				$raw = $R->bytes($len);
				// drop the single-byte null terminator if present
				if ($raw !== '' && $raw[strlen($raw)-1] === "\x00") {
					$raw = substr($raw, 0, -1);
				}
				return $raw;
			}
			// not plausible -> revert and read cstr
			$R->seek($save);
			return $R->cstr();
		}

		// UTF-16LE FString
		if ($len < 0) {
			$need = (-$len) * 2;
			if ($need > 0 && $need <= 2_000_000 && method_exists($R, 'remaining') && $R->remaining() >= $need) {
				$bytes = $R->bytes($need);
				// drop 2-byte null terminator if present
				$n = strlen($bytes);
				if ($n >= 2 && substr($bytes, $n-2) === "\x00\x00") {
					$bytes = substr($bytes, 0, $n-2);
				}
				$utf8 = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
				return $utf8 !== false ? $utf8 : '';
			}
			// not plausible -> revert and read cstr
			$R->seek($save);
			return $R->cstr();
		}

		// len == 0 => empty string
		return '';
	}
				
	public function readLodMeshFull(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = ['LODs'=>[], 'BoundingBox'=>null, 'BoundingSphere'=>null];

		// Root bounding volumes
		if ($R->remaining() >= 25) {
			$min = [$R->f32(), $R->f32(), $R->f32()];
			$max = [$R->f32(), $R->f32(), $R->f32()];
			$valid = (bool)$R->u8();
			$out['BoundingBox'] = ['Min'=>$min,'Max'=>$max,'Valid'=>$valid];
		}
		if ($R->remaining() >= 16) {
			$center = [$R->f32(), $R->f32(), $R->f32()];
			$radius = $R->f32();
			$out['BoundingSphere'] = ['Center'=>$center,'Radius'=>$radius];
		}

		// LOD count
		if ($R->remaining() < 4) return $out;
		$lodCount = $R->index();
		$out['LODCount'] = $lodCount;

		for ($lod=0; $lod<$lodCount; $lod++) {
			if ($R->remaining() < 4) break;
			$numVerts = $R->index();
			$numTris  = $R->index();
			$numSecs  = $R->index();

			$lodInfo = [
				'NumVerts'=>$numVerts,
				'NumTris'=>$numTris,
				'NumSections'=>$numSecs,
				'Vertices'=>[],
				'Triangles'=>[],
				'Sections'=>[],
				'BoundingBox'=>null,
				'BoundingSphere'=>null
			];

			// Vertices
			for ($i=0; $i<min($numVerts,200) && $R->remaining()>=12; $i++) {
				$lodInfo['Vertices'][] = [$R->f32(), $R->f32(), $R->f32()];
			}
			if ($numVerts>200 && $R->remaining()>=($numVerts-200)*12)
				$R->skip(($numVerts-200)*12);

			// Triangles
			for ($i=0; $i<min($numTris,200) && $R->remaining()>=6; $i++) {
				$lodInfo['Triangles'][] = [$R->u16(), $R->u16(), $R->u16()];
			}
			if ($numTris>200 && $R->remaining()>=($numTris-200)*6)
				$R->skip(($numTris-200)*6);

			// --- New: Material Sections ---
			for ($s=0; $s<$numSecs && $R->remaining()>=12; $s++) {
				$matRef = $R->index();          // material reference (INDEX)
				$firstTri = $R->index();        // first triangle index
				$numTri   = $R->index();        // number of triangles in this section
				$lodInfo['Sections'][] = [
					'Material'=>$this->displayNameFromRef($matRef),
					'FirstTri'=>$firstTri,
					'NumTris'=>$numTri
				];
			}

			// Materials (for convenience)
			if ($R->remaining()>=4) {
				$matCount = $R->index();
				$mats=[];
				for ($i=0; $i<$matCount && $R->remaining()>=4; $i++) {
					$ix = $R->index();
					$mats[] = $this->displayNameFromRef($ix);
				}
				$lodInfo['Materials']=$mats;
			}

			// Per-LOD bounding box
			if ($R->remaining()>=25) {
				$min=[$R->f32(),$R->f32(),$R->f32()];
				$max=[$R->f32(),$R->f32(),$R->f32()];
				$valid=(bool)$R->u8();
				$lodInfo['BoundingBox']=['Min'=>$min,'Max'=>$max,'Valid'=>$valid];
			}

			// Per-LOD bounding sphere
			if ($R->remaining()>=16) {
				$center=[$R->f32(),$R->f32(),$R->f32()];
				$radius=$R->f32();
				$lodInfo['BoundingSphere']=['Center'=>$center,'Radius'=>$radius];
			}

			$out['LODs'][]=$lodInfo;
		}

		return $out;
	}
	/*
	$lod = $pkg->readLodMeshFull($i);
	echo "<b>LOD count:</b> {$lod['LODCount']}<br>";
	foreach ($lod['LODs'] as $n => $l) {
		echo "<hr><b>LOD #{$n}</b><br>";
		echo "Verts: {$l['NumVerts']}, Tris: {$l['NumTris']}, Sections: {$l['NumSections']}<br>";
		foreach ($l['Sections'] as $s) {
			echo "&nbsp;&nbsp;Material: {$s['Material']} (Tris {$s['FirstTri']}–"
				 .($s['FirstTri']+$s['NumTris']-1).")<br>";
		}
	}

	or

	if ($pkg->exportClassName($i) === 'LodMesh') {
		$lod = $pkg->readLodMeshFull($i);
		echo "<b>LOD Count:</b> {$lod['LODCount']}<br>";
		foreach ($lod['LODs'] as $n => $l) {
			echo "<b>LOD #{$n}</b> – {$l['NumVerts']} verts, {$l['NumTris']} tris<br>";
			if (!empty($l['Materials'])) {
				echo "Materials: ".implode(', ', $l['Materials'])."<br>";
			}
		}
	}
	*/
	/*
	public function readTexture(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$start = $R->tell();
		$this->skipProperties($R);

		$out = ['mips'=>[]];
		if ($R->remaining() < 1) return $out;
		$out['MipMapCount'] = $R->u8();

		// Optional WidthOffset (>=63) per PDF
		if (($this->header['version'] ?? 0) >= 63 && $R->remaining() >= 4) {
			$out['WidthOffset'] = $R->u32();
		}

		// Read first mip header only (full data blocks can be very large)
		if ($R->remaining() >= 4) {
			$size = $R->index(); // INDEX MipMapSize
			$out['FirstMipSize'] = $size;
			if ($size > 0 && $R->remaining() >= $size) {
				$R->skip($size); // don’t materialize the image in memory by default
			}
		}

		// Tail: Width/Height/BitsWidth/BitsHeight (if present)
		if ($R->remaining() >= 8) {
			$out['Width']  = $R->u32();
			$out['Height'] = $R->u32();
		}
		if ($R->remaining() >= 2) {
			$out['BitsWidth']  = $R->u8();
			$out['BitsHeight'] = $R->u8();
		}

		$out['bytesParsed'] = $R->tell() - $start;
		return $out;
	}
	*/
private function safeRemain($R): int
{
    if (method_exists($R, 'remaining')) return (int)$R->remaining();
    if (method_exists($R, 'left'))      return (int)$R->left();
    // Best-effort fallback if your UEReader does tell/limit
    if (method_exists($R, 'tell') && method_exists($R, 'limit')) {
        return max(0, (int)$R->limit() - (int)$R->tell());
    }
    return 1 << 30; // big sentinel
}

    private function readHeader(): void
{
    $R = $this->R;
    $start = $R->tell();

    // Common header
    $this->header = [];
    $this->header['tag']             = $R->u32();     // 0x9E2A83C1 or similar
    $this->header['version']         = $R->i32();     // FileVersion
    $this->header['licenseeVersion'] = $R->i32();

    $ver = (int)$this->header['version'];

    // ---- UE3 / UE2 / UE1: core fields in the SAME order as the C++ ----
    // PackageFlags
    $this->header['packageFlags'] = $R->u32();

    // Name/Export/Import counts + offsets
    $this->header['nameCount']    = $R->i32();
    $this->header['nameOffset']   = $R->i32();
    $this->header['exportCount']  = $R->i32();
    $this->header['exportOffset'] = $R->i32();
    $this->header['importCount']  = $R->i32();
    $this->header['importOffset'] = $R->i32();

    // DependsOffset: present in late UE2 and UE3; it’s harmless to read for UE1 (many parsers do)
    $this->header['dependsOffset'] = $R->i32();

    // Guid (16 bytes)
    $this->header['guid'] = [
        'a' => $R->u32(),
        'b' => $R->u32(),
        'c' => $R->u32(),
        'd' => $R->u32(),
    ];

    // Generations
    $genCount = $R->i32();
    $gens = [];
    for ($i = 0; $i < $genCount; $i++) {
        // In UE1/UE2/UE3 the pair is (exportCount, nameCount)
        $gens[] = ['exportCount' => $R->i32(), 'nameCount' => $R->i32()];
    }
    $this->header['generations'] = $gens;

    // -------- Optional, but AFTER the generations (C++ order) --------
    // Some UE2/UE3 builds append engine/cooker versions here. Read only if enough bytes remain.
    // Reading them BEFORE the tables is the bug that corrupts NameOffset for UE3.
    if ($this->safeRemain($R) >= 8) {
        // It’s ok if a given file doesn’t have these; they’ll just be junk if not present.
        // If you prefer, guard these by a known min version for your corpus.
        $peekPos = $R->tell();
        $engineCandidate = $R->i32();
        $cookerCandidate = $R->i32();
        // Keep, but don’t REQUIRE them
        $this->header['engineVersionOpt'] = $engineCandidate;
        $this->header['cookerVersionOpt'] = $cookerCandidate;
        // No seek-back; we consumed them intentionally (same as many UE tools).
    }

    // -------- UE3 Compression info (after gens / optional versions) --------
    // UE3 packages may be chunk-compressed; the table of compressed chunks comes AFTER the core header.
    $this->isCompressed     = false;
    $this->compressionFlags = 0;
    $this->chunks           = [];

    if ($ver >= 334 && $this->safeRemain($R) >= 4) {
        // Many UE3 builds place CompressionFlags (u32) and, if non-zero, a chunk array.
        $posBefore = $R->tell();
        $flags = $R->u32();
        if ($flags !== 0) {
            $this->compressionFlags = $flags;
            // Chunk count
            if ($this->safeRemain($R) >= 4) {
                $chunkCount = $R->i32();
                $chunks = [];
                for ($i = 0; $i < $chunkCount; $i++) {
                    // Each chunk has uncompressed/compressed sizes and block descriptors.
                    // Common layout (UnPackage3): UncompressedSize, CompressedSize, BlockCount, then blocks.
                    $ucSize = $R->i32();
                    $cSize  = $R->i32();
                    $blockCount = $R->i32();
                    $blocks = [];
                    for ($b = 0; $b < $blockCount; $b++) {
                        // Block: CompressedSize, UncompressedSize
                        $bC = $R->i32();
                        $bU = $R->i32();
                        $blocks[] = ['c' => $bC, 'u' => $bU];
                    }
                    $chunks[] = [
                        'ucSize' => $ucSize,
                        'cSize'  => $cSize,
                        'blocks' => $blocks,
                    ];
                }
                $this->chunks = $chunks;
                $this->isCompressed = (count($chunks) > 0);
            }
        } else {
            // no compression; rewind the peeked flags to keep stream alignment for some titles
            $R->seek($posBefore); // optional; safe to leave consumed too
        }
    }

    // Done
    // (Do not read any FString like FolderName here for UE3; putting it here breaks offsets.)
}

	/** Read properties for every export whose serial block exists */
	private function readAllExportProperties(): void
	{
		$this->exportProps = [];
		$R                 = $this->R;
		
		if ($this->isCompressed) {
			foreach ($this->exports as $i => $_) 
				$this->exportProps[$i] = [];
				
			return;
		}

		foreach ($this->exports as $i => $ex) {
			$size   = (int)$ex['serialSize'];
			$offset = (int)$ex['serialOffset'];
			
			if ($size <= 0 || $offset <= 0) { 
				$this->exportProps[$i] = []; 
				continue; 
			}

			// bounds guard
			$end = $offset + $size;
			
			if ($end > $R->length()) { 
				$end = $R->length(); 
			}

			$save = $R->tell();
			
			try {
				$R->seek($offset);
				$this->exportProps[$i] = $this->readPropertyBlock($size);
			} catch (\Throwable $e) {
				$this->exportProps[$i] = [];
			} finally {
				$R->seek($save);
			}
		}
	}
	
	
	
	function readUncompressedRange(int $start, int $length): string {
		if (!$this->isCompressed) {
			$cur   = $this->R->tell();
			$this->R->seek($start);
			$bytes = $this->R->bytes($length);
			$this->R->seek($cur);
			
			return $bytes;
		}
		
		if ($length <= 0) 
			return '';

		$end  = $start + $length;
		$out  = '';
		$curU = $start;

		foreach ($this->chunks as $idx => $c) {
			$cu0 = (int)$c['uOff'];
			$cu1 = (int)($c['uOff'] + $c['uLen']);

			if ($cu1 <= $start || $cu0 >= $end) 
				continue;

			if ($curU < $cu0) {
				$gapEnd = min($cu0, $end);
				$out   .= str_repeat("\x00", $gapEnd - $curU);
				$curU   = $gapEnd;
			}

			$ovlStart = max($curU, $cu0);
			$ovlEnd   = min($end, $cu1);
			
			if ($ovlEnd > $ovlStart) {
				$this->ensureChunkLoaded($idx);
				$relStart = $ovlStart - $cu0;
				$relLen   = $ovlEnd   - $ovlStart;
				$chunkBuf = $this->chunkCache[$idx];
				
				if ($relStart < 0) 
					$relStart = 0;
				
				if ($relStart + $relLen > strlen($chunkBuf)) {
					$relLen = max(0, strlen($chunkBuf) - $relStart);
				}
				
				if ($relLen > 0) {
					$out  .= substr($chunkBuf, $relStart, $relLen);
					$curU  = $ovlEnd;
				}
			}

			if ($curU >= $end) 
				break;
		}

		if ($curU < $end) {
			$out .= str_repeat("\x00", $end - $curU);
		}

		return (strlen($out) > $length) ? substr($out, 0, $length) : $out;
	} 
	
	public function readPalette(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		if ($R->remaining() < 4) return null;
		$count = $R->index();
		$cols  = [];
		$max   = min($count, 256); // safety
		for ($i=0; $i<$max && $R->remaining() >= 4; $i++) {
			$r = ord($R->bytes(1)); $g = ord($R->bytes(1));
			$b = ord($R->bytes(1)); $a = ord($R->bytes(1));
			$cols[] = [$r,$g,$b,$a];
		}
		return ['PaletteSize'=>$count, 'Colors'=>$cols];
	}
	/*
	public function readTextBuffer(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		if ($R->remaining() < 8) return null;
		$pos = $R->u32(); $top = $R->u32(); // usually 0
		if ($R->remaining() < 4) return ['Pos'=>$pos, 'Top'=>$top];

		$len = $R->index();
		$txt = ($len>0 && $R->remaining() >= $len) ? $R->bytes($len) : '';
		$txt = rtrim($txt, "\x00");
		// Optional trailing null byte when TextSize>0
		return ['Pos'=>$pos,'Top'=>$top,'TextSize'=>$len,'Text'=>$txt];
	}
	*/
	/*
	public function readSound(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		if ($R->remaining() < 4) return null;
		$fmtIdx = $R->index();
		$fmt    = $this->nameByIndex($fmtIdx) ?? '';

		if (($this->header['version'] ?? 0) >= 63 && $R->remaining() >= 4) {
			$outNext = $R->u32(); // OffsetNext (we expose it but don’t jump)
		}

		if ($R->remaining() < 4) return ['Format'=>$fmt];
		$size = $R->index();
		$wav  = ($size>0 && $R->remaining() >= $size) ? $R->bytes($size) : '';
		return ['Format'=>$fmt, 'Size'=>$size, 'Data'=>$wav];
	}
	*/
	/** UE1/UE2-style property tag block: sequence of (Name,Type, [Struct], Size, ArrayIndex, Payload) ... until Name=='None' */
	private function readPropertyBlock(int $blockSize): array
	{
		if (($this->header['version'] ?? 0) > 220) {
			return $this->readPropertyBlockUE3($blockSize);
		}
		
		$R     = $this->R;
		$start = $R->tell();
		$end   = $start + $blockSize;
		
		if ($end > $R->length()) 
			$end = $R->length();

		$props = [];

		// helper: packed array index per PDF
		$readPackedArrayIndex = function() use ($R): int {
			$b0 = $R->u8();
			
			if (($b0 & 0xC0) === 0xC0) { // 4 bytes (int with MSB OR 0xC0)
				$b1 = $R->u8(); 
				$b2 = $R->u8(); 
				$b3 = $R->u8();
				return (($b0 & 0x3F) << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
			} elseif (($b0 & 0x80) === 0x80) { // 2 bytes (word with MSB OR 0x80)
				$b1 = $R->u8();
				return (($b0 & 0x7F) << 8) | $b1;
			} else { // 1 byte
				return $b0;
			}
		};

		while ($R->tell() < $end) {
			$propStart = $R->tell();
			// 1) Name (Index)
			$nameIdx = $R->index();
			$nameStr = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') {
				// ---- TERMINATOR ("None") ----
				// bytes consumed by the CompactIndex for the terminator name
				$nameBytes  = $R->tell() - $propStart;
				// compute how many bytes remain in this declared properties block
				$blockEnd   = min($start + $blockSize, $R->length());
				$remaining  = $blockEnd - $R->tell();
				
				if ($remaining < 0) 
					$remaining = 0;

				// consume only what fits inside the block (padding/leftover)
				$pad = 0;
				
				if ($remaining > 0) {
					$pad = (int)$remaining;
					$R->bytes($pad);
				}

				// total row length = bytes for the name index + any in-block padding
				$totalLen = ($nameBytes + $pad);

				$props[] = [
					'offset'                   => $propStart - $start,
					'length'                   => $totalLen,              // e.g. 2 for 0x1E 0x00
					'name'                     => 'None',
					'type'                     => 'None',
					'struct'                   => '',
					'isArray'                  => 'No',
					'idx'                      => null,
					'idxFromFile'              => false,
					'value'                    => '',
				];

				break; // end of properties
			}

			// 2) Info byte
			if ($R->tell() >= $end) 
				break;
			
			// 2) Info byte
			$info     = $R->u8();
			$typeCode =  $info        & 0x0F;   // bits 0..3
			$sizeCode = ($info >> 4)  & 0x07;   // bits 4..6
			$hiBit    =  ($info & 0x80) !== 0;  // bit 7


			// 3) If StructProperty, struct name (Index) follows
			$structName    = '';
			$structNameIdx = null;
			
			if ($typeCode === 0x0A) {
				if ($R->tell() >= $end) 
					break;
				
				//$structName = $this->nameByIndex($R->index()) ?? '';
				$structNameIdx = $R->index();                      // raw struct name index
				$structName    = $this->nameByIndex($structNameIdx) ?? '';
			}

			// 4) Size from sizeCode
			$payloadLen = 0;
			
			switch ($sizeCode) {
				case 0: $payloadLen = 1;  break;
				case 1: $payloadLen = 2;  break;
				case 2: $payloadLen = 4;  break;
				case 3: $payloadLen = 12; break;
				case 4: $payloadLen = 16; break;
				case 5: $payloadLen = $R->u8();  break;   // real size in next byte
				case 6: $payloadLen = $R->u16(); break;   // real size in next word
				case 7: $payloadLen = $R->u32(); break;   // real size in next dword
			}

			// 5) Array index if hiBit set and not Boolean
			$hasArrayFlag = ($hiBit && $typeCode !== 0x03);
			$arrayIdx     = null;
			
			if ($hasArrayFlag) {
				$arrayIdx = $readPackedArrayIndex();  // raw array index from file (>0)
			}
			
			$isArray = ($arrayIdx !== null) ? 'Yes' : 'No';

			// 6) Boolean value (bit 7) has no payload
			$valDisplay = '';
			
			if ($typeCode === 0x03) { // BooleanProperty
				$valDisplay = $hiBit ? 'True' : 'False';
				$payloadLen = 0;
			}

			// 7) Read payload (bounded)
			$remaining = $end - $R->tell();
			
			if ($payloadLen > $remaining) {
				// clamp and warn; prevents OOM on bad headers
				$payloadLen            = max(0, (int)$remaining);
			}
			
			$payload = ($payloadLen > 0) ? $R->bytes($payloadLen) : '';

			// decode common types
			if ($typeCode !== 0x03) { // non-boolean
				$PR = new UEReader($payload);
				$PR->setVersion($R->getVersion());

				switch ($typeCode) {
					case 0x01: /* Byte */ {
						$valDisplay = ($payloadLen >= 1) ? (string)ord($payload[0]) : '0';
						break;
					}
					case 0x02: /* Integer */ {
						$v          = ($payloadLen >= 4) ? $PR->i32() : 0;
						$valDisplay = sprintf("%d (0x%08X)", $v, ($v < 0 ? (0x100000000 + $v) : $v));
						break;
					}
					case 0x04: /* Float */ {
						$v          = ($payloadLen >= 4) ? $PR->f32() : 0.0;
						$valDisplay = rtrim(sprintf('%.6f', $v), '0.');
						break;
					}
					case 0x05: /* Object */ {
						$ref        = ($payloadLen > 0) ? $PR->index() : 0;
						$nm         = $this->displayNameFromRef($ref);
						$valDisplay = ($nm !== '') ? ($nm . " (ref {$ref})") : (string)$ref;
						break;
					}
					case 0x06: /* Name */ {
						$ni         = ($payloadLen > 0) ? $PR->index() : 0;
						$valDisplay = $this->nameByIndex($ni) ?? (string)$ni;
						break;
					}
					case 0x07: /* StringProperty */ {
						// Try detect UTF-16LE by even length and presence of NUL in every other byte
						if ($payloadLen >= 2 && ($payloadLen % 2) === 0 && strpos($payload, "\x00") !== false) {
							$s          = @iconv('UTF-16LE', 'UTF-8//IGNORE', $payload);
							$valDisplay = rtrim((string)$s, "\x00");
						} else {
							$valDisplay = rtrim($payload, "\x00");
						}
						break;
					}
					case 0x08: /* ClassProperty */ {
						$ref        = ($payloadLen > 0) ? $PR->index() : 0;
						$nm         = $this->displayNameFromRef($ref);
						$valDisplay = ($nm !== '') ? "class {$nm}" : "class(ref {$ref})";
						break;
					}
					case 0x0A: /* StructProperty */ {
						$sn = strtolower($structName);
						if ($sn === 'color' && $payloadLen >= 4) {
							// decode directly from bytes to avoid any endian surprises
							$r          = ord($payload[0]);
							$g          = ord($payload[1]);
							$b          = ord($payload[2]);
							$a          = ord($payload[3]);
							$valDisplay = "Color (R={$r},G={$g},B={$b},A={$a})";
						} elseif ($sn === 'vector' && $payloadLen >= 12) {
							$x          = $PR->f32(); 
							$y          = $PR->f32(); 
							$z          = $PR->f32();
							$valDisplay = sprintf("Vector (X=%.3f,Y=%.3f,Z=%.3f)", $x, $y, $z);
						} elseif ($sn === 'rotator' && $payloadLen >= 12) {
							$pitch      = $PR->i32(); 
							$yaw        = $PR->i32(); 
							$roll       = $PR->i32();
							$valDisplay = "Rotator (Pitch={$pitch},Yaw={$yaw},Roll={$roll})";
						}elseif ($sn === 'boundingbox' && $payloadLen >= (12+12+1)) {
							// Vector Min (3*float) + Vector Max (3*float) + BYTE IsValid
							$minX       = $PR->f32(); 
							$minY       = $PR->f32(); 
							$minZ       = $PR->f32();
							$maxX       = $PR->f32(); 
							$maxY       = $PR->f32(); 
							$maxZ       = $PR->f32();
							$valid      = ord($PR->bytes(1)) ? 'true' : 'false';
							$valDisplay = sprintf("BoundingBox Min(%.3f,%.3f,%.3f) Max(%.3f,%.3f,%.3f) Valid=%s", $minX,$minY,$minZ,$maxX,$maxY,$maxZ,$valid);
						}
						elseif ($sn === 'boundingsphere' && $payloadLen >= (12+4)) {
							// Vector Position (3*float) + Float W (if PackageVersion>61 per PDF)
							$px         = $PR->f32(); 
							$py         = $PR->f32(); 
							$pz         = $PR->f32();
							$w          = ($this->header['version'] ?? 0) > 61 && ($PR->tell()+4)<= $payloadLen ? $PR->f32() : 0.0;
							$valDisplay = sprintf("BoundingSphere Pos(%.3f,%.3f,%.3f) W=%.3f", $px,$py,$pz,$w);
						}							
						elseif (($sn === 'adrop' || $sn === 'aspark') && $payloadLen >= 2+1+1+4) {
							$u1         = $PR->u16();
							$x          = ord($PR->bytes(1));
							$y          = ord($PR->bytes(1));
							$u2         = $PR->u32();
							$valDisplay = "ADrop/ASpark u1={$u1} x={$x} y={$y} u2={$u2}";	
						} else {
							$valDisplay = "Struct {$structName} ({$payloadLen} bytes)";
						}
						break;
					}
					case 0x0B: /* VectorProperty */ {
						if ($payloadLen >= 12) {
							$x          = $PR->f32(); 
							$y          = $PR->f32(); 
							$z          = $PR->f32();
							$valDisplay = sprintf("Vector (X=%.3f,Y=%.3f,Z=%.3f)", $x, $y, $z);
						} else 
							$valDisplay = "Vector ({$payloadLen} bytes)";
						break;
					}
					case 0x0C: /* RotatorProperty */ {
						if ($payloadLen >= 12) {
							$pitch      = $PR->i32();
							$yaw        = $PR->i32();
							$roll       = $PR->i32();
							$valDisplay = "Rotator (Pitch={$pitch},Yaw={$yaw},Roll={$roll})";
						} else {
							$valDisplay = "Rotator ({$payloadLen} bytes)";
						}
						break;
					}					
					case 0x0D: /* StrProperty — Index length + ASCIIZ */ {
						if ($payloadLen > 0) {
							$decl = $PR->index();
							
							if ($decl > 0 && ($PR->tell() + $decl) <= $payloadLen) {
								$s          = $PR->bytes($decl);
								$valDisplay = rtrim($s, "\x00");
							} elseif ($decl < 0 && ($PR->tell() + (-$decl*2)) <= $payloadLen) {
								$bytes = $PR->bytes(-$decl * 2);
								$s     = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
								$valDisplay = rtrim((string)$s, "\x00");
							} else {
								$valDisplay = rtrim($payload, "\x00");
							}
							
						} else $valDisplay = '';
						break;
					}
					case 0x0E: /* MapProperty */ {
						$valDisplay = "Map ({$payloadLen} bytes, raw)";
						break;
					}
					case 0x0F: /* FixedArrayProperty */ {
						$valDisplay = "FixedArray ({$payloadLen} bytes, raw)";
						break;
					}		
					default: {
						if ($typeCode === 0x03) { // boolean handled earlier: no payload
							$valDisplay = $hiBit ? 'True' : 'False';
						} elseif ($typeCode === 0x00) {
							$valDisplay = '';
						} else {
							$valDisplay = sprintf('Type(0x%02X) raw (%d bytes)', (int)$typeCode, (int)$payloadLen);
						}
						break;
					}
				}
			}

			$props[] = [
				// human-friendly
				'offset'               => $propStart - $start,
				'length'               => ($R->tell() - $propStart),
				'name'                 => $nameStr,
				'type'                 => $this->propertyTypeName($typeCode),
				'struct'               => $structName ?? '',
				'isArray'              => $isArray,
				'idx'                  => $arrayIdx,                 // NULL unless stored in file
				'idxFromFile'          => ($arrayIdx !== null),      // TRUE only when array flag was present
				'value'                => $valDisplay,
			];

			if ($R->tell() <= $propStart) { // forward progress guard
				break;
			}
		}
		
		// Post-pass: if we see any element with idx>0, mark the first as array start (inferred idx=0).
		$nameToFirstIndex = [];
		
		for ($k = 0; $k < count($props); $k++) {
			$p = $props[$k];
			
			if (($p['name'] ?? '') === '' || $p['name'] === 'None') 
				continue;
			
			$nm = $p['name'];

			if (!array_key_exists($nm, $nameToFirstIndex)) {
				$nameToFirstIndex[$nm] = $k;
			}
			
			if (isset($p['idx']) && $p['idx'] > 0) {
				$firstK = $nameToFirstIndex[$nm];
				
				if (!isset($props[$firstK]['idx'])) {
					// set only human-facing fields; leave all raw_* NULL/unchanged
					$props[$firstK]['isArray']     = 'Yes';
					$props[$firstK]['idx']         = 0;      // inferred for UI
					$props[$firstK]['idxFromFile'] = false;  // inferred
					// raw_idx and raw_idxFromFile stay NULL/false
				}
			}
		}

		return $props;
	}
	/*
	public function readMusic(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		// The PDF notes “single chunk” typical; layout varies by version.
		$rem = $R->remaining();
		$chunk = ($rem > 0) ? $R->bytes($rem) : '';
		return ['Bytes'=>strlen($chunk), 'Data'=>$chunk];
	}
	*/
	private function readPropertyBlockUE3(int $blockSize): array
	{
		$R     = $this->R;
		$start = $R->tell();
		$end   = min($start + $blockSize, $R->length());
		$props = [];

		while ($R->tell() < $end) {
			$propStart = $R->tell();
			// Name (Index)
			$nameIdx   = $R->index();
			$nameStr   = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') {
				// ---- TERMINATOR ("None") ----
				// bytes consumed by the CompactIndex for the name
				$nameBytes = $R->tell() - $propStart;
				// compute remaining bytes within the declared block
				$blockEnd   = min($start + $blockSize, $R->length());
				$remaining  = $blockEnd - $R->tell();
				
				if ($remaining < 0) 
					$remaining = 0;

				$pad = 0; // consume only what fits (padding)
				
				if ($remaining > 0) {
					$pad = (int)$remaining;
					$R->bytes($pad);
				}

				$totalLen = ($nameBytes + $pad);
				$props[]  = [
					'offset'                    => $propStart - $start,
					'length'                    => $totalLen,
					'name'                      => 'None',
					'type'                      => 'None',
					'struct'                    => '',
					'isArray'                   => 'No',
					'idx'                       => null,
					'idxFromFile'               => false,
					'value'                     => '',
				];

				break; // end of properties

			}

			// Type name (Index)
			$typeNameIdx = $R->index();
			$typeName    = $this->nameByIndex($typeNameIdx) ?? '';

			// Size + ArrayIndex (DWORDs in UE3)
			if ($R->tell()+8 > $end) 
				break;
			
			$payloadLen    = $R->u32();
			$arrayIdx      = $R->u32();
			$structNameIdx = null;
			$structName    = '';
			// StructProperty: read struct name (and optional GUID)
			if (strcasecmp($typeName, 'StructProperty') === 0) {
				$structNameIdx = $R->index();
				$structName    = $this->nameByIndex($structNameIdx) ?? '';
				// Optional struct GUID in UE3 when not a core/native struct.
				// Only consume if there are at least 16 more bytes before payload.
				if ($R->tell()+16 <= $end) {
					// Peek: if the remaining till payload is >=16, consume it as GUID bytes.
					// This keeps alignment with Java which conditionally reads GUID.
					// (Skip storing—rarely displayed.)
					$guidPeekPos        = $R->tell();
					$remainingToPayload = ($start+$blockSize) - $R->tell();
					
					if ($remainingToPayload >= ($payloadLen + 16)) {
						$R->bytes(16);
					} else {
						// leave as-is; some packages omit GUID
					}
				}
			}

			// BoolProperty: a single byte BoolVal comes here (header), then no payload
			$boolVal = null;
			
			if (strcasecmp($typeName, 'BoolProperty') === 0) {
				if ($R->tell() < $end) 
					$boolVal = (bool)$R->u8();
				// Most UE3 bool tags have size 0; in any case, don't read payload
				$payloadLen = 0;
			}

			// Bound payload
			$remaining = $end - $R->tell();
			
			if ($payloadLen < 0 || $payloadLen > $remaining) {
				$payloadLen = max(0, min((int)$payloadLen, (int)$remaining));
			}
			
			$payload    = ($payloadLen > 0) ? $R->bytes($payloadLen) : '';
			$valDisplay = ''; // Decode value (human) 
			$PR         = new UEReader($payload); $PR->setVersion($R->getVersion());
			
			switch (strtolower($typeName)) {
				case 'byteproperty':
					$valDisplay = ($payloadLen >= 1) ? (string)ord($payload[0]) : '0';
					break;
				case 'intproperty':
				case 'integerproperty':
					$v          = ($payloadLen >= 4) ? $PR->i32() : 0;
					$valDisplay = sprintf("%d (0x%08X)", $v, ($v<0? (0x100000000+$v):$v));
					break;
				case 'floatproperty':
					$v          = ($payloadLen >= 4) ? $PR->f32() : 0.0;
					$valDisplay = rtrim(sprintf('%.6f', $v), '0.');
					break;
				case 'nameproperty': {
					$ni         = ($payloadLen > 0) ? $PR->index() : 0;
					$valDisplay = $this->nameByIndex($ni) ?? (string)$ni;
					break;
				}
				case 'objectproperty': {
					$ref        = ($payloadLen > 0) ? $PR->i32() : 0; // UE3 object ref usually 32-bit here
					$nm         = $this->displayNameFromRef($ref);
					$valDisplay = ($nm !== '') ? $nm : (string)$ref;
					break;
				}
				case 'strproperty':
				case 'stringproperty': {
					if ($payloadLen > 0) {
						$decl           = $PR->index();
						
						if ($decl > 0 && ($PR->tell()+$decl)<= $payloadLen) {
							$s          = $PR->bytes($decl); 
							$valDisplay = rtrim($s, "\x00");
						} elseif ($decl < 0 && ($PR->tell()+(-$decl*2)) <= $payloadLen) {
							$bytes      = $PR->bytes(-$decl*2);
							$s          = @iconv('UTF-16LE','UTF-8//IGNORE',$bytes);
							$valDisplay = rtrim((string)$s, "\x00");
						} else {
							$valDisplay = rtrim($payload, "\x00");
						}
					}
					break;
				}
				case 'structproperty': {
					$sn = strtolower($structName);
					if ($sn === 'color' && $payloadLen >= 4) {
						$r          = ord($payload[0]); 
						$g          = ord($payload[1]); 
						$b          = ord($payload[2]); 
						$a          = ord($payload[3]);
						$valDisplay = "Color (R={$r},G={$g},B={$b},A={$a})";
					} elseif ($sn === 'vector' && $payloadLen >= 12) {
						$x          = $PR->f32(); 
						$y          = $PR->f32(); 
						$z          = $PR->f32();
						$valDisplay = sprintf("Vector (X=%.3f,Y=%.3f,Z=%.3f)", $x,$y,$z);
					} elseif ($sn === 'rotator' && $payloadLen >= 12) {
						$pitch      = $PR->i32(); 
						$yaw        = $PR->i32(); 
						$roll       = $PR->i32();
						$valDisplay = "Rotator (Pitch={$pitch},Yaw={$yaw},Roll={$roll})";
					} else {
						$valDisplay = "Struct {$structName} ({$payloadLen} bytes)";
					}
					break;
				}
				case 'boolproperty':
					$valDisplay = ($boolVal ? 'True' : 'False');
					break;
				case 'arrayproperty':
					// payload begins with element count (INDEX), then element blobs
					$count = $PR->index();
					$vals  = [];
					
					for ($k=0; $k<$count; $k++) {
						// If the element type is known (some UE3 builds prepend TypeName),
						// you may need to read that; otherwise reuse the property reader
						// for a single “anonymous” value of the same field type.
						$vals[] = $this->readUE3ArrayElem($PR /*, maybe $elemType */);
					}
					
					$valDisplay = 'Array['.$count.']';
					break;
				default:
					$valDisplay = ($typeName !== '' ? ('Type(' . $typeName . ') raw (' . (int)$payloadLen . ' bytes)') : ('raw (' . (int)$payloadLen . ' bytes)'));
					break;
			}

			$props[] = [
				// human
				'offset'              => $propStart-$start,
				'length'              => $R->tell()-$propStart,
				'name'                => $nameStr,
				'type'                => $typeName,
				'struct'              => $structName,
				'isArray'             => ($arrayIdx>0?'Yes':'No'),
				'idx'                 => ($arrayIdx>0?$arrayIdx:null),
				'idxFromFile'         => ($arrayIdx>0),
				'value'               => $valDisplay,
			];
		}
		// No “infer idx 0” in UE3 – arrayIndex is written explicitly there
		return $props;
	}
	/*
	public function getCompressionHeader(): array {
		return [
			'isCompressed'      => (bool)$this->isCompressed,
			'compressionFlags'  => (int)$this->compressionFlags,
			'chunkCount'        => is_array($this->chunks) ? count($this->chunks) : 0,
			'firstChunk'        => $this->chunks[0]  ?? null,
			'lastChunk'         => $this->chunks ? $this->chunks[count($this->chunks)-1] : null,
		];
	}
	*/

	private function readNameTable(): void
	{
		$count  = (int)($this->header['nameCount']  ?? 0);
		$offset = (int)($this->header['nameOffset'] ?? 0);
		$ver    = (int)($this->header['version']    ?? 0);

		// 1) pick the correct stream
		$R = ($this->isCompressed && !empty($this->chunks)) ? $this->makeConcatUncompressedReader() : $this->R;

		// 2) dispatch by format
		if ($ver >= 334) {
			$this->readNameTableUE3($R, $count, $offset);
		} else {
			$this->readNameTableUE1UE2($R, $count, $offset); // your existing logic
		}
	}
	
	// --- NEW: UE3 implementation (uses FString + 64-bit flags) ---
	private function readNameTableUE3($R, int $count, int $offset): void
	{
		$this->names = [];
		$fileLen     = $R->length();

		if ($offset < 0 || $offset > $fileLen) {
			throw new \RuntimeException("Name table offset $offset out of bounds");
		}
		
		$R->seek($offset);

		for ($i = 0; $i < $count; $i++) {			
			if ($R->tell() + 4 > $fileLen) { // FString length is SIGNED i32
				throw new \RuntimeException("EOF before FString length (name #$i)");
			}
			
			$len = $R->i32();

			if ($len === 0) {
				$name = '';
			} elseif ($len > 0) { // ANSI bytes, includes trailing NUL
				if ($len > 1_048_576 || $R->tell() + $len > $fileLen) {
					throw new \RuntimeException("Bogus ANSI length $len at name #$i");
				}
				
				$name = rtrim($R->bytes($len), "\x00");
			} else { // UTF-16LE, includes trailing NUL code unit
				$need = (-$len) * 2;
				
				if ($need > 2_097_152 || $R->tell() + $need > $fileLen) {
					throw new \RuntimeException("Bogus UTF-16 length $len at name #$i");
				}
				
				$raw  = $R->bytes($need);
				$name = rtrim(@iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) ?: '', "\x00");
			}
			
			if ($R->tell() + 8 > $fileLen) { // UE3 usually uses 64-bit flags
				throw new \RuntimeException("EOF before name flags (name #$i)");
			}
			
			$flagsLo       = $R->u32();
			$flagsHi       = $R->u32();
			$flags         = ($flagsHi << 32) | $flagsLo;
			$this->names[] = ['index' => $i, 'name' => $name, 'flags' => $flags];
		}
	}

	// --- Existing UE1/UE2 stays as-is; just wrap it ---
	private function readNameTableUE1UE2($R, int $count, int $offset): void
	{	
		$R      = $this->R;
		$hdr    = $this->header;
		$count  = (int)($hdr['nameCount']  ?? 0);
		$offset = (int)($hdr['nameOffset'] ?? 0);
		$ver    = (int)($hdr['version']    ?? 0);

		if ($count < 0 || $offset < 0) {
			throw new \RuntimeException("Invalid name table header values.");
		}

		$fileLen = $R->length();
		
		if ($offset > $fileLen) {
			throw new \RuntimeException("Name table offset ($offset) beyond file size ($fileLen).");
		}

		$R->seek($offset);
		$this->names = [];

		// ---------- UE3+ (keep existing functionality) ----------
		// Your previous code required a branch for version >= 334.
		// We preserve that behavior here using FString (signed i32 length) + 64-bit flags.
		if ($ver >= 334) {
			for ($i = 0; $i < $count; $i++) {
				// FString: signed int32 length. <0 => UTF-16LE (chars = -len), >0 => ANSI/UTF-8-ish (bytes = len)
				$len = $R->i32();  // you should already have i32(); if not, implement via signed unpack
				
				if ($len === 0) {
					$name = '';
				} elseif ($len < 0) {
					$chars = -$len;                    // includes trailing NUL
					$bytes = $R->bytes($chars * 2);
					// Convert UTF-16LE -> UTF-8, strip trailing NUL
					$name  = rtrim(@iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes) ?: '', "\x00");
				} else { // $len > 0
					$bytes = $R->bytes($len);          // includes trailing NUL
					$name  = rtrim($bytes, "\x00");
				}

				// UE3 name flags are 64-bit (two u32 little-endian parts)
				$flagsLo = $R->u32();
				$flagsHi = $R->u32();
				// Compose 64-bit into PHP int if available, or keep as string if you prefer
				$flags = ($flagsHi << 32) | $flagsLo;

				$this->names[] = [
					'index' => $i,
					'name'  => $name,
					'flags' => $flags,
				];

				if ($R->tell() > $fileLen) {
					throw new \RuntimeException("Overran file while reading name #$i (UE3+).");
				}
			}
			
			return;
		}

		// ---------- UE1/UE2 ----------
		// v < 64  : ASCIIZ name + u32 flags
		// v >= 64 : 1-byte length (includes trailing NUL) + bytes + u32 flags
		for ($i = 0; $i < $count; $i++) {
			if ($ver < 64) {
				// Older UE1 packages: ASCIIZ
				$name = $R->cstr();
			} else {
				// UE1/UE2 “v >= 64”: 1-byte length including trailing NUL
				$L = $R->u8();
				
				if ($L === 0) {
					$name = '';
				} else {
					$raw  = $R->bytes($L);
					$name = rtrim($raw, "\x00");
				}
			}

			// UE1/UE2 flags are 32-bit
			$flags = $R->u32();

			$this->names[] = [
				'index' => $i,
				'name'  => $name,
				'flags' => $flags,
			];

			if ($R->tell() > $fileLen) {
				throw new \RuntimeException("Overran file while reading name #$i (UE1/UE2).");
			}
		}
	}
	// UE3 FString at current reader position (ANSI or UTF-16LE)
	private function readFStringFrom(UEReader $R): string
	{
		$pos = $R->tell();
		if (method_exists($R, 'remaining') && $R->remaining() < 4) {
			return $R->cstr(); // fallback if we can't even read length
		}
		$len = $R->i32(); // signed

		if ($len === 0) return '';

		if ($len > 0) { // ANSI
			$raw = $R->bytes($len);
			$n = strlen($raw);
			if ($n && $raw[$n-1] === "\x00") $raw = substr($raw, 0, $n-1);
			return $raw;
		}

		// UTF-16LE
		$need = (-$len) * 2;
		if (method_exists($R, 'remaining') && $R->remaining() < $need) {
			$R->seek($pos);
			return $R->cstr();
		}
		$bytes = $R->bytes($need);
		$n = strlen($bytes);
		if ($n >= 2 && substr($bytes, $n-2) === "\x00\x00") $bytes = substr($bytes, 0, $n-2);
		$utf8 = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
		return $utf8 !== false ? $utf8 : '';
	}

	// UE FName = (nameIndex, number). Return both and a pretty string ("Foo_1")
	private function readFNameStruct(UEReader $R): array
	{
		$nameIdx = $R->i32();  // index into Name table
		$number  = $R->i32();  // instance number
		$base    = $this->getNameByIndex($nameIdx);
		$text    = ($number > 0) ? ($base . '_' . $number) : $base;
		return ['index'=>$nameIdx, 'number'=>$number, 'base'=>$base, 'text'=>$text];
	}
		private function resolveObjectRef(int $idx): array
		{
			if ($idx === 0) 
				return ['type'=>'none','index'=>-1];
			
			if ($idx > 0)   
				return ['type'=>'export','index'=>$idx - 1];
			
			return ['type'=>'import','index'=>(-$idx) - 1];
		}	
		
	private function refKind(int $i): string {
		if ($i === 0) 
			return 'none';
		
		return ($i > 0) ? 'export' : 'import';
	}

	private function refIndex(int $i): int {
		if ($i === 0) 
			return -1;
		
		return ($i > 0) ? ($i - 1) : ((-$i) - 1);
	}







	/** Fast-forward a reader past the serialized Properties list (ends at Name 'None'). */
	private function skipProperties(UEReader $R): void {
		// Stop if we hit end or the NONE property
		while ($R->remaining() > 0) {
			$nameIdx = $R->index();
			$nameStr = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') {
				return;
			}
			
			$info      = $R->u8();
			$typeCode  = $info & 0x0F;
			$sizeCode  = ($info >> 4) & 0x07;
			$isArrayIx = (($info & 0x80) !== 0) && ($typeCode !== 0x03); // bool has no payload

			// Struct name if struct
			if ($typeCode === 0x0A) { 
				$R->index(); 
			}

			$payloadLen = match ($sizeCode) {
				0 => 1, 1 => 2, 2 => 4, 3 => 12, 4 => 16,
				5 => $R->u8(),
				6 => $R->u16(),
				7 => $R->u32(),
			};

			if ($isArrayIx) {
				// packed array index (1/2/4 bytes with tag in MS bits)
				// fixed UE1/UE2 array-index packing
				$b = $R->u8();
				
				if ($b === 0x80) {
					// index follows as 16-bit
					$R->u16();
				} elseif ($b === 0xC0) {
					// index follows as 32-bit
					$R->u32();
				}
				// else: $b itself is the index; nothing further to read
			}

			if ($typeCode !== 0x03 && $payloadLen > 0) {
				$R->skip($payloadLen);
			}
		}
	}

	/** Decode flags helpers (use the maps you already added). */
	/*
	private function namesFromFlags(int $v, array $map): array {
		$out = [];
		
		foreach ($map as $bit => $name) 
			if (($v & $bit) === $bit) 
				$out[] = $name;
		
		return $out;
	}
	*/
	

	/*
	public function readSkeletalMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$out = ['Bones'=>[], 'Vertices'=>[], 'Weights'=>[], 'BoundingBox'=>null];

		// BoundingBox first
		if ($R->remaining() >= 25) {
			$min   = [$R->f32(), $R->f32(), $R->f32()];
			$max   = [$R->f32(), $R->f32(), $R->f32()];
			$valid = (bool)$R->u8();
			$out['BoundingBox'] = ['Min'=>$min, 'Max'=>$max, 'Valid'=>$valid];
		}

		// Bones
		if ($R->remaining() >= 4) {
			$boneCount = $R->index();
			$bones     = [];
			
			for ($i=0; $i<$boneCount && $R->remaining() >= 40; $i++) {
				$nameIdx = $R->index();
				$parent  = $R->i32();
				$pos     = [$R->f32(), $R->f32(), $R->f32()];
				$rot     = [$R->f32(), $R->f32(), $R->f32(), $R->f32()];
				$scale   = [$R->f32(), $R->f32(), $R->f32()];
				$bones[] = ['Name'=>$this->nameByIndex($nameIdx), 'Parent'=>$parent, 'Pos'=>$pos, 'Rot'=>$rot, 'Scale'=>$scale,];
			}
			
			$out['Bones'] = $bones;
		}

		// Preview first few vertex weights (variable layout)
		if ($R->remaining() >= 4) {
			$vcount             = $R->index();
			$out['VertexCount'] = $vcount;
			
			for ($i=0; $i<min($vcount, 5) && $R->remaining() >= 16; $i++) {
				$x=$R->f32(); 
				$y=$R->f32(); 
				$z=$R->f32();
				$boneIdx=$R->u8(); 
				$weight=$R->f32();
				$out['Vertices'][]=['Pos'=>[$x,$y,$z],'Bone'=>$boneIdx,'Weight'=>$weight];
			}
		}

		return $out;
	}
	*/
	/*
	public function readAnimation(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		
		if (!$R) 
			return null;
		
		$this->skipProperties($R);
		$out = ['Sequences'=>[]];

		if ($R->remaining() < 4) 
			return $out;
		
		$seqCount = $R->index();
		
		for ($i=0; $i<$seqCount && $R->remaining() >= 16; $i++) {
			$nameIdx    = $R->index();
			$groupIdx   = $R->index();
			$startFrame = $R->u16();
			$numFrames  = $R->u16();
			$rate       = $R->f32();
			$out['Sequences'][] = ['Name'=>$this->nameByIndex($nameIdx),'Group'=>$this->nameByIndex($groupIdx),'Start'=>$startFrame,'Frames'=>$numFrames,'Rate'=>$rate,];
		}
		
		return $out;
	}
	*/
	/*
	switch ($pkg->exportClassName($i)) {
		case 'Mesh':          $info = $pkg->readMesh($i); break;
		case 'LodMesh':       $info = $pkg->readLodMesh($i); break;
		case 'SkeletalMesh':  $info = $pkg->readSkeletalMesh($i); break;
		case 'Animation':     $info = $pkg->readAnimation($i); break;
	}
	*/
	
	
	/*
	if ($pkg->exportClassName($i) === 'LodMesh') {
		$geo = $pkg->readLodMeshGeometry($i);
		if ($geo) {
			$norms = $pkg->computeLodMeshNormals($geo);

			echo "<b>Points:</b> {$geo['Counts']['Points']}, ";
			echo "<b>Wedges:</b> {$geo['Counts']['Wedges']}, ";
			echo "<b>Faces:</b> {$geo['Counts']['Faces']}<br>";

			// Show first 3 wedges with UVs + normals
			for ($k=0; $k<min(3, count($geo['Wedges'])); $k++) {
				$w = $geo['Wedges'][$k];
				$n = $norms['wedgeNormals'][$k] ?? [0,0,1];
				echo "Wedge {$k}: v={$w['vertexIndex']}  uv=(".
					 number_format($w['u'],3).",".
					 number_format($w['v'],3).")  n=(".
					 number_format($n[0],3).",".
					 number_format($n[1],3).",".
					 number_format($n[2],3).")<br>";
			}
		}
	}

	/**
	 * Summarize LodMesh sections:
	 * - material name
	 * - triangle count
	 * - UV ranges (min/max U,V over used wedges)
	 * - average face normal (unit vector)
	 *
	 * Requires:
	 *   - readLodMeshFull($exportIndex) for Sections (FirstTri/NumTris + Material)
	 *   - readLodMeshGeometry($exportIndex) for Points/Wedges/Faces
	 *   - computeLodMeshNormals($geo)       for per-wedge/vertex normals (we also compute per-face below)
	 */
	public function summarizeLodMeshSections(int $exportIndex): ?array {
		$full = $this->readLodMeshFull($exportIndex);
		$geo  = $this->readLodMeshGeometry($exportIndex);
		
		if (!$full || !$geo) 
			return null;

		$sections = $full['LODs'][0]['Sections'] ?? []; // choose LOD 0 by default
		$faces    = $geo['Faces']  ?? [];
		$wedges   = $geo['Wedges'] ?? [];
		$points   = $geo['Points'] ?? [];

		// helper math
		$sub   = fn(array $a, array $b) => [$a[0]-$b[0], $a[1]-$b[1], $a[2]-$b[2]];
		$cross = fn(array $a, array $b) => [
			$a[1]*$b[2]-$a[2]*$b[1],
			$a[2]*$b[0]-$a[0]*$b[2],
			$a[0]*$b[1]-$a[1]*$b[0]
		];
		$norm = function(array $v): array {
			$l = sqrt($v[0]*$v[0]+$v[1]*$v[1]+$v[2]*$v[2]);
			
			return $l>1e-12 ? [$v[0]/$l,$v[1]/$l,$v[2]/$l] : [0,0,1];
		};

		$out = [];
		
		foreach ($sections as $s) {
			$first = (int)($s['FirstTri'] ?? 0);
			$count = (int)($s['NumTris']  ?? 0);
			$last  = $first + max(0,$count) - 1;

			$umin       =  INF; 
			$vmin       =  INF; 
			$umax       =- INF; 
			$vmax       =- INF;
			$fnSum      = [0.0,0.0,0.0]; 
			$validFaces = 0;

			for ($t=$first; $t<=$last; $t++) {
				if (!isset($faces[$t])) 
					break;
				
				$f = $faces[$t];
				$w1=$f['w1']??null; 
				$w2=$f['w2']??null; 
				$w3=$f['w3']??null;
				
				if (!isset($wedges[$w1],$wedges[$w2],$wedges[$w3])) 
					continue;

				// UV range
				foreach ([$wedges[$w1],$wedges[$w2],$wedges[$w3]] as $w) {
					$u = $w['u']; 
					$v = $w['v'];
					
					if ($u<$umin) 
						$umin = $u; 
					
					if ($u>$umax) 
						$umax = $u;
					
					if ($v<$vmin) 
						$vmin = $v; 
					
					if ($v>$vmax) 
						$vmax = $v;
				}

				// Face normal (from vertex positions)
				$i1 = $wedges[$w1]['vertexIndex']; 
				$i2 = $wedges[$w2]['vertexIndex']; 
				$i3 = $wedges[$w3]['vertexIndex'];
				
				if (!isset($points[$i1],$points[$i2],$points[$i3])) 
					continue;
				
				$p1       = $points[$i1]; 
				$p2       = $points[$i2]; 
				$p3       = $points[$i3];
				$fn       = $norm($cross($sub($p2,$p1), $sub($p3,$p1)));
				$fnSum[0]+=$fn[0]; 
				$fnSum[1]+=$fn[1]; 
				$fnSum[2]+=$fn[2];
				$validFaces++;
			}

			$avgFn = $validFaces>0 ? $norm([$fnSum[0]/$validFaces,$fnSum[1]/$validFaces,$fnSum[2]/$validFaces]) : [0,0,1];
			$out[] = [
				'Material'  => $s['Material'] ?? '',
				'TriCount'  => max(0,$count),
				'UVMin'     => is_finite($umin) ? [$umin,$vmin] : [0,0],
				'UVMax'     => is_finite($umax) ? [$umax,$vmax] : [0,0],
				'AvgNormal' => $avgFn,
				'FirstTri'  => $first,
			];
		}
		
		return ['LOD'=>0, 'Sections'=>$out];
	}
	
	/*
	$sum = $pkg->summarizeLodMeshSections($i);
	foreach ($sum['Sections'] as $s) {
	  echo "<b>{$s['Material']}</b> – {$s['TriCount']} tris ".
		   "UV[".number_format($s['UVMin'][0],3).",".number_format($s['UVMin'][1],3)."]–".
				  "[".number_format($s['UVMax'][0],3).",".number_format($s['UVMax'][1],3)."] ".
		   "AvgN(".number_format($s['AvgNormal'][0],2).",".
					 number_format($s['AvgNormal'][1],2).",".
					 number_format($s['AvgNormal'][2],2).")<br>";
	}

	*/

	/**
	 * Render a LodMesh preview to a PNG file.
	 * Options:
	 *  - 'lod'      => which LOD index (default 0)
	 *  - 'mode'     => 'wire' | 'flat' (default 'wire')
	 *  - 'size'     => [width, height] (default [640, 640])
	 *  - 'bg'       => [r,g,b] background (default [20,20,24])
	 *  - 'fg'       => [r,g,b] wire color (default [220,220,220])
	 *  - 'lightDir' => [x,y,z] for flat shading (default [0.4,0.5,0.75])
	 *  - 'rotate'   => [yawDeg, pitchDeg, rollDeg] (default [30,15,0])
	 *//*
	public function renderLodMeshPNG(int $exportIndex, string $outPath, array $opt = []): bool {
		if (!function_exists('imagecreatetruecolor')) {
			throw new \RuntimeException("GD not available. Install/enable php-gd.");
		}

		$lodIndex = (int)($opt['lod'] ?? 0);
		$mode     = (string)($opt['mode'] ?? 'wire');
		[$W,$H]   = $opt['size'] ?? [640,640];
		$bgc      = $opt['bg']   ?? [20,20,24];
		$fgc      = $opt['fg']   ?? [220,220,220];
		$light    = $opt['lightDir'] ?? [0.4,0.5,0.75];
		$rot      = $opt['rotate']   ?? [30,15,0];

		$full = $this->readLodMeshFull($exportIndex);
		$geo  = $this->readLodMeshGeometry($exportIndex);
		if (!$full || !$geo) return false;

		$faces  = $geo['Faces'];  $wedges = $geo['Wedges'];  $points = $geo['Points'];
		$lods   = $full['LODs'] ?? [];
		if (!isset($lods[$lodIndex])) $lodIndex = 0;
		$secs   = $lods[$lodIndex]['Sections'] ?? [];

		// Build per-section face index ranges for colorization
		$ranges = []; $palette = [];
		foreach ($secs as $si => $s) {
			$first = (int)$s['FirstTri']; $num = (int)$s['NumTris'];
			$ranges[] = [$first, $first+$num-1];
			// soft distinct-ish colors per section
			$palette[$si] = [
				128 + (37 * $si) % 127,
				100 + (83 * $si) % 127,
				120 + (59 * $si) % 127
			];
		}

		// Compute normals when flat shading
		$wNormals = null;
		if ($mode === 'flat') {
			$norms = $this->computeLodMeshNormals($geo);
			$wNormals = $norms['wedgeNormals'] ?? null;
		}

		// Compute transform to canvas (orthographic + rotate + fit)
		$rad = fn($d)=>$d*M_PI/180.0;
		[$yaw,$pitch,$roll] = [ $rad($rot[0]), $rad($rot[1]), $rad($rot[2]) ];

		$Ry = function($a){ $c=cos($a); $s=sin($a); return [[ $c,0,$s],[0,1,0],[-$s,0,$c]]; };
		$Rx = function($a){ $c=cos($a); $s=sin($a); return [[1,0,0],[0,$c,-$s],[0,$s,$c]]; };
		$Rz = function($a){ $c=cos($a); $s=sin($a); return [[$c,-$s,0],[$s,$c,0],[0,0,1]]; };

		$matMul = function(array $m, array $v){
			return [
				$m[0][0]*$v[0]+$m[0][1]*$v[1]+$m[0][2]*$v[2],
				$m[1][0]*$v[0]+$m[1][1]*$v[1]+$m[1][2]*$v[2],
				$m[2][0]*$v[0]+$m[2][1]*$v[1]+$m[2][2]*$v[2],
			];
		};
		$mul3 = function(array $A, array $B) use ($matMul) {
			// matrix multiply A*B
			$res = [[0,0,0],[0,0,0],[0,0,0]];
			for($r=0;$r<3;$r++) for($c=0;$c<3;$c++) {
				$res[$r][$c] = $A[$r][0]*$B[0][$c] + $A[$r][1]*$B[1][$c] + $A[$r][2]*$B[2][$c];
			}
			return $res;
		};

		$R = $mul3($Rz($roll), $mul3($Rx($pitch), $Ry($yaw)));

		// Transform points and compute fit to screen
		$tp = [];
		$min=[INF,INF,INF]; $max=[-INF,-INF,-INF];
		foreach ($points as $p) {
			$v = $matMul($R, $p);
			$tp[] = $v;
			for($k=0;$k<3;$k++){ if($v[$k]<$min[$k])$min[$k]=$v[$k]; if($v[$k]>$max[$k])$max[$k]=$v[$k]; }
		}
		$sx = $max[0]-$min[0]; $sy = $max[1]-$min[1];
		$scale = 0.9 * min($W/($sx>0?$sx:1), $H/($sy>0?$sy:1));
		$ox = $W/2 - $scale*($min[0]+$max[0])/2;
		$oy = $H/2 + $scale*($min[1]+$max[1])/2; // + because y screen downwards

		$proj2 = fn(array $v)=> [ (int)($ox + $scale*$v[0]), (int)($oy - $scale*$v[1]) ];

		// Create image
		$im = imagecreatetruecolor($W,$H);
		$bg = imagecolorallocate($im, $bgc[0],$bgc[1],$bgc[2]);
		imagefilledrectangle($im, 0,0, $W,$H, $bg);
		$fg = imagecolorallocate($im, $fgc[0],$fgc[1],$fgc[2]);

		// Per-section buffered draw (flat: z-sort by average depth -> simple painter’s algo)
		$tris = [];
		foreach ($faces as $fi => $f) {
			$w1=$f['w1']; $w2=$f['w2']; $w3=$f['w3'];
			if (!isset($wedges[$w1],$wedges[$w2],$wedges[$w3])) continue;
			$i1=$wedges[$w1]['vertexIndex']; $i2=$wedges[$w2]['vertexIndex']; $i3=$wedges[$w3]['vertexIndex'];
			if (!isset($tp[$i1],$tp[$i2],$tp[$i3])) continue;

			$p1=$tp[$i1]; $p2=$tp[$i2]; $p3=$tp[$i3];
			$P1=$proj2($p1); $P2=$proj2($p2); $P3=$proj2($p3);
			$zAvg = ($p1[2]+$p2[2]+$p3[2])/3;

			// find section index for coloring
			$secIdx = null;
			foreach ($ranges as $si => [$a,$b]) { if ($fi >= $a && $fi <= $b) { $secIdx = $si; break; } }
			$col = $fg;
			if ($secIdx !== null) {
				[$r,$g,$b] = $palette[$secIdx];
				$col = imagecolorallocate($im, $r,$g,$b);
			}

			if ($mode === 'wire') {
				imageline($im, $P1[0],$P1[1], $P2[0],$P2[1], $col);
				imageline($im, $P2[0],$P2[1], $P3[0],$P3[1], $col);
				imageline($im, $P3[0],$P3[1], $P1[0],$P1[1], $col);
			} else {
				// flat shade by face normal vs light dir
				$e1 = [$p2[0]-$p1[0], $p2[1]-$p1[1], $p2[2]-$p1[2]];
				$e2 = [$p3[0]-$p1[0], $p3[1]-$p1[1], $p3[2]-$p1[2]];
				$n  = [
					$e1[1]*$e2[2]-$e1[2]*$e2[1],
					$e1[2]*$e2[0]-$e1[0]*$e2[2],
					$e1[0]*$e2[1]-$e1[1]*$e2[0],
				];
				$ln = sqrt($n[0]*$n[0]+$n[1]*$n[1]+$n[2]*$n[2]); if($ln>1e-9){ $n=[ $n[0]/$ln,$n[1]/$ln,$n[2]/$ln ]; }
				$ld = $light; $ll = sqrt($ld[0]*$ld[0]+$ld[1]*$ld[1]+$ld[2]*$ld[2]); if($ll>1e-9){ $ld=[ $ld[0]/$ll,$ld[1]/$ll,$ld[2]/$ll ]; }
				$dp = max(0.0, min(1.0, $n[0]*$ld[0]+$n[1]*$ld[1]+$n[2]*$ld[2]));
				$shade = function(int $c, float $f) {
					$r=($c>>16)&255; $g=($c>>8)&255; $b=$c&255;
					return [max(0,min(255,(int)($r*$f))), max(0,min(255,(int)($g*$f))), max(0,min(255,(int)($b*$f)))];
				};
				$baseRGB = ($secIdx!==null)?$palette[$secIdx]:$fgc;
				[$r,$g,$b] = $shade(($baseRGB[0]<<16)|($baseRGB[1]<<8)|$baseRGB[2], 0.35 + 0.65*$dp);
				$fill = imagecolorallocate($im, $r,$g,$b);

				imagefilledpolygon($im, [$P1[0],$P1[1], $P2[0],$P2[1], $P3[0],$P3[1]], 3, $fill);
				// light outline
				imageline($im, $P1[0],$P1[1], $P2[0],$P2[1], $fg);
				imageline($im, $P2[0],$P2[1], $P3[0],$P3[1], $fg);
				imageline($im, $P3[0],$P3[1], $P1[0],$P1[1], $fg);
			}
			// stash for painter’s algo if you want depth sorting: push to $tris, sort by $zAvg, then draw
		}

		$ok = imagepng($im, $outPath);
		imagedestroy($im);
		return (bool)$ok;
	}
	*/
	/*
	$ok = $pkg->renderLodMeshPNG($i, __DIR__."/lodmesh_preview.png", [
	  'mode' => 'flat',
	  'rotate' => [35, 20, 0],
	  'size' => [800, 800]
	]);
	*/
	/*
	public function renderLodMeshSVG(int $exportIndex, array $opt=[]): string {
		$opt['mode'] = 'wire';
		$opt['size'] = $opt['size'] ?? [640,640];
		$svgW = $opt['size'][0]; $svgH = $opt['size'][1];

		// Reuse the PNG pipeline up to projected 2D points, but instead of drawing, build SVG lines.
		// For brevity here, call renderLodMeshPNG’s math path and adapt to SVG if you want a no-deps path.
		return "<svg width='{$svgW}' height='{$svgH}' xmlns='http://www.w3.org/2000/svg'><rect width='100%' height='100%' fill='#141418'/><!-- TODO: lines --></svg>";
	}
	*/
	private function totalUncompressedSize(): int {
		if (!$this->isCompressed || empty($this->chunks)) 
			return $this->R->length();
		
		$last = $this->chunks[count($this->chunks)-1];
		
		return (int)($last['uOff'] + $last['uLen']);
	}
	


	// ------- end fallback -------

		







	/** Common alignment candidates seen in UE3 cooks. */
	private static array $UE3_ALIGN_CANDIDATES = [1, 16, 128, 256, 512, 1024, 4096];
	/*
	private function tryChunkTableT1(
		string $cData, int $len, int $p, int $blockSize, int $uncompTotal, int $flags, ?int $hint
	) {
		$LE32 = function (int $off) use ($cData, $len): int {
			if ($off < 0 || $off + 4 > $len) return -1;
			return unpack('V', substr($cData, $off, 4))[1];
		};

		$maxN   = 4096;
		$startN = $hint ?? 1;

		for ($n = $startN; $n <= $maxN; $n++) {
			$tableBytes = $n * 4;
			if ($p + $tableBytes > $len) break;

			// Read compSizes
			$compSizes = [];
			$q = $p; $ok = true;
			for ($i = 0; $i < $n; $i++) {
				$cSz = $LE32($q); $q += 4;
				if ($cSz <= 0) { $ok = false; break; }
				$compSizes[] = $cSz;
			}
			if (!$ok) continue;

			// Try with several plausible alignments on the payload
			foreach (self::$UE3_ALIGN_CANDIDATES as $A) {
				$sumCompAligned = 0;
				foreach ($compSizes as $cSz) {
					$sumCompAligned += self::alignUp($cSz, $A);
				}
				$dataStart = $p + $tableBytes;
				if ($dataStart + $sumCompAligned !== $len) {
					continue; // alignment A doesn't balance this layout
				}

				// Balanced: decompress all blocks using this alignment
				$out    = '';
				$remain = ($uncompTotal > 0) ? $uncompTotal : PHP_INT_MAX;
				$r      = $dataStart;

				for ($i = 0; $i < $n; $i++) {
					$cSz     = $compSizes[$i];
					$cSzRead = $cSz;
					$uTarget = ($remain >= $blockSize) ? $blockSize : $remain;

					if ($r + $cSzRead > $len) { $ok = false; break; }
					$payload = substr($cData, $r, $cSzRead);
					$r      += self::alignUp($cSzRead, $A); // advance by ALIGNED size

					// Decompress (or copy if clearly uncompressed)
					$u = ($cSzRead === $uTarget) ? $payload : $this->decompressBySniff($payload, $flags);
					if ($u === false) { $ok = false; break; }

					// If total is known, enforce per-block size
					if ($uncompTotal > 0 && strlen($u) !== $uTarget) { $ok = false; break; }

					$out .= $u;
					if ($uncompTotal > 0) $remain -= $uTarget;
				}
				if ($ok && ($uncompTotal === 0 || $remain === 0)) {
					return $out;
				}
			}
		}
		return false;
	}
	*/
	/*
	private function tryChunkTableT2(
		string $cData, int $len, int $p, int $flags, ?int $hint
	) {
		$LE32 = function (int $off) use ($cData, $len): int {
			if ($off < 0 || $off + 4 > $len) return -1;
			return unpack('V', substr($cData, $off, 4))[1];
		};

		$maxN   = 4096;
		$startN = $hint ?? 1;

		for ($n = $startN; $n <= $maxN; $n++) {
			$tableBytes = $n * 8;
			if ($p + $tableBytes > $len) break;

			// Read (cSz, uSz) pairs
			$pairs = [];
			$q = $p; $ok = true;
			for ($i = 0; $i < $n; $i++) {
				$cSz = $LE32($q); $q += 4;
				$uSz = $LE32($q); $q += 4;
				if ($cSz <= 0 || $uSz <= 0) { $ok = false; break; }
				$pairs[] = [$cSz, $uSz];
			}
			if (!$ok) continue;

			foreach (self::$UE3_ALIGN_CANDIDATES as $A) {
				$sumCompAligned = 0;
				foreach ($pairs as [$cSz, /*$uSz*/ /*]) {
					$sumCompAligned += self::alignUp($cSz, $A);
				}
				$dataStart = $p + $tableBytes;
				if ($dataStart + $sumCompAligned !== $len) {
					continue;
				}

				// Balanced: decompress with alignment A
				$out = '';
				$r   = $dataStart;
				foreach ($pairs as $i => [$cSz, $uSz]) {
					if ($r + $cSz > $len) { $ok = false; break; }
					$payload = substr($cData, $r, $cSz);
					$r      += self::alignUp($cSz, $A);

					$u = ($cSz === $uSz) ? $payload : $this->decompressBySniff($payload, $flags);
					if ($u === false || strlen($u) !== $uSz) { $ok = false; break; }

					$out .= $u;
				}
				if ($ok) return $out;
			}
		}
		return false;
	}
	*/





	










		
	/**
	 * Build "Package.Group.Object" from a RAW object ref (0 / +N / −N).
	 */
	 /*
	public function exportGroupPath(int $ref): string {
		$path = [];
		$k  = $this->refKind($ref);
		$ix = $this->refIndex($ref);

		while ($k !== 'none') {
			if ($k === 'export') {
				$ex = $this->exports[$ix] ?? null; if (!$ex) break;
				$path[] = $this->nameStr($ex['objectName']);
				$k  = $this->refKind($ex['packageIndex']);
				$ix = $this->refIndex($ex['packageIndex']);
			} else { // import
				$im = $this->imports[$ix] ?? null; if (!$im) break;
				$path[] = $this->nameStr($im['objectName']);
				$k  = $this->refKind($im['outerIndex']);
				$ix = $this->refIndex($im['outerIndex']);
			}
		}
		
		return implode('.', array_reverse($path));
	}
	*/



	

	

	private function u32_from(string $b, int $o = 0): int { 
		return unpack('V', substr($b, $o, 4))[1]; 
	}




	// Resolve a PackageIndex (0 / negative import / positive export) to a human string path.
	// Does not throw; always returns a string (possibly empty) for UI safety.
	/*
	private function resolvePackageIndexPath(int $pkgIndex): string
	{
		if ($pkgIndex === 0) {
			return '';
		}
		// Chain up through imports to build "Package.SubPackage.Object" style names
		$seen = 0;
		$parts = [];

		while ($pkgIndex !== 0 && $seen < 1024) {
			$seen++;

			if ($pkgIndex < 0) {
				$i = -$pkgIndex - 1;
				if (!isset($this->imports) || $i < 0 || $i >= count($this->imports)) {
					$parts[] = "__BADIMPORT[$i]__";
					break;
				}
				$imp = $this->imports[$i];
				$parts[] = (string)($imp['ObjectName']['text'] ?? '');
				$pkgIndex = (int)($imp['OuterIndex'] ?? 0);
				continue;
			}

			if ($pkgIndex > 0) {
				$e = $pkgIndex - 1;
				if (!isset($this->exports) || $e < 0 || $e >= count($this->exports)) {
					$parts[] = "__BADEXPORT[$e]__";
					break;
				}
				$exp = $this->exports[$e];
				// Expect your Export to have ObjectName FName stored similarly; if not, adjust:
				$parts[] = (string)($exp['ObjectName']['text'] ?? ($exp['ObjectNameText'] ?? ''));
				$pkgIndex = (int)($exp['OuterIndex'] ?? 0);
				continue;
			}
		}

		if (empty($parts)) return '';
		return implode('.', array_reverse($parts));
	}
	*/
    /** Lightweight structural validation; returns a list of warnings (empty if none). */
    public function validatePackage(): array {
        $issues = [];
        $len = $this->R->length();
        $hdr = $this->header;

        // Header sanity
        foreach (['nameOffset','exportOffset','importOffset'] as $k) {
            if (isset($hdr[$k]) && ($hdr[$k] < 0 || $hdr[$k] > $len)) {
                $issues[] = "Header: {$k} out of bounds ({$hdr[$k]} of {$len}).";
            }
        }

        // Name table bounds
        if (($hdr['nameOffset'] ?? 0) > 0 && ($hdr['nameCount'] ?? 0) >= 0) {
            // Can't precisely bound variable-length names; just check offset < len
            if ($hdr['nameOffset'] >= $len) {
                $issues.append("Names offset beyond EOF.");
            }
        }

        // Import/Export table basic bounds
        if (($hdr['exportOffset'] ?? 0) >= $len) $issues[] = "Export table offset beyond EOF.";
        if (($hdr['importOffset'] ?? 0) >= $len) $issues[] = "Import table offset beyond EOF.";

        // Export serial ranges
        foreach ($this->exports as $i => $e) {
            $sz = (int)($e['serialSize'] ?? 0);
            $off= (int)($e['serialOffset'] ?? 0);
            if ($sz < 0) $issues[] = "Export #$i has negative serialSize.";
            if ($off < 0) $issues[] = "Export #$i has negative serialOffset.";
            if ($sz > 0 && ($off + $sz) > $len) {
                $issues[] = "Export #$i body extends beyond EOF ({$off}+{$sz} > {$len}).";
            }
        }

        // Chunk table sanity if compressed
        if (!empty($this->chunks)) {
            foreach ($this->chunks as $ci => $c) {
                $cOff=(int)$c['cOff']; $cLen=(int)$c['cLen'];
                if ($cOff < 0 || $cLen < 0 || ($cOff + $cLen) > $len) {
                    $issues[] = "Chunk #{$ci} out of bounds ({$cOff}+{$cLen} > {$len}).";
                }
            }
        }

        return $issues;
    }
	
/** Read a 32-bit little-endian signed int from $R, regardless of its API. */
/** Strict little-endian signed int32: never delegates to UEReader i32/u32. */
private function readI32LE($R): int
{
    // Always read exactly 4 bytes from the current stream
    $b = $R->bytes(4);                 // UEReader has bytes(); this will throw on overrun
    $u = unpack('V', $b)[1];           // little-endian unsigned 32
    // convert to signed
    return ($u & 0x80000000) ? ($u - 0x100000000) : $u;
}


/** Read a 32-bit little-endian unsigned int from $R. */
private function readU32LE($R): int
{
    if (method_exists($R, 'u32')) {
        return (int)$R->u32();            // assume correct LE here if provided
    }
    $b = method_exists($R, 'bytes') ? $R->bytes(4) : (
         (method_exists($R, 'readBytes') ? $R->readBytes(4) : null));
    if ($b === null || strlen($b) !== 4) {
        throw new \OutOfBoundsException('Unable to read 4 bytes for u32.');
    }
    return unpack('V', $b)[1];            // little-endian unsigned 32
}


}
//---------------------------------------------------------------------------------
final class UEReader
{
    private string $buf;
    private int $pos     = 0;
    private int $len     = 0;
    private int $version = 0;
	/** Absolute [minPos, maxPos) limits for bounded reading (exclusive max). */
	private int $minPos = 0;
	private int $maxPos = 0;   // set in constructor to full length

    public function __construct(string $bytes){
        $this->buf    = $bytes;
        $this->len    = strlen($bytes);
		$this->pos    = 0;
		$this->minPos = 0;
		$this->maxPos = $this->len;  // <-- allow full file by default
    }
	
	public function setBounds(int $start, int $end): void {
		if ($start < 0) 
			$start = 0;
		
		if ($end > $this->len) 
			$end = $this->len;
		
		if ($end < $start) 
			$end = $start;

		$this->minPos = $start;
		$this->maxPos = $end;

		// If current pos is outside, clamp it inside the new bounds.
		if ($this->pos < $this->minPos) 
			$this->pos = $this->minPos;
		
		if ($this->pos > $this->maxPos) 
			$this->pos = $this->maxPos;
	}

	/** Remove bounds: allow reading the entire buffer again. */
	public function clearBounds(): void {
		$this->minPos = 0;
		$this->maxPos = $this->len;
	}
	
    public function setVersion(int $v): void { 
		$this->version = $v; 
	}
	
    public function getVersion(): int { 
		return $this->version; 
	}	

	// Inside class UEReader (only if you don't already have them)
	public function length(): int { 
		return strlen($this->buf); 
	}
	
	public function tell(): int { 
		return $this->pos; 
	}
	
	/** Bytes remaining within the current bounds. */
	public function remaining(): int {
		$rem = $this->maxPos - $this->pos;
		
		return max(0, $this->maxPos - $this->pos);
	}
	
	
	public function canRead(int $n): bool { 
		return $n >= 0 && ($this->tell() + $n) <= $this->maxPos; 
	}

	/**
	 * Seek to an absolute offset, but clamp to [minPos, maxPos].
	 * Throws if you try to seek outside bounds (strict mode).
	 */
	public function seek(int $absolute): void {
		if ($absolute < $this->minPos || $absolute > $this->maxPos) {
			throw new \OutOfBoundsException(sprintf("UEReader::seek out of bounds: %d not in [%d, %d)", $absolute, $this->minPos, $this->maxPos
			));
		}
		
		$this->pos = $absolute;
	}
	
	/** Skip forward N bytes within bounds. */
	public function skip(int $n): void {
		if ($n < 0) {
			// If you need backward skip, convert to seek() with clamp semantics
			$target = $this->pos + $n;
			$this->seek($target);
			return;
		}
		if ($n > $this->remaining()) {
			throw new \OutOfBoundsException(sprintf(
				"UEReader::skip overrun: want %d, remaining %d",
				$n, $this->remaining()
			));
		}
		
		$this->pos += $n;
	}
	
	/**
	 * Read exactly N bytes within bounds.
	 * Throws if not enough bytes remain inside the active window.
	 */
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
				$n, $rem, $this->minPos, $this->maxPos, $this->pos
			));
		}

		// Assuming your buf is stored in $this->buf (string). Adjust if you use a stream.
		$chunk      = substr($this->buf, $this->pos, $n);
		$this->pos += $n;
		
		return $chunk;
	}

	public function f32(): float {
		$b = $this->bytes(4);
		return unpack('g', $b)[1]; // little-endian float
	}

    public function u8():  int { 
		return ord($this->bytes(1)); 
	}
	
    public function u16(): int {
        $b = $this->bytes(2);
		
        return (ord($b[0]) | (ord($b[1]) << 8)) & 0xFFFF;
    }
	
    public function u32(): int {
        $b = $this->bytes(4);
		
        return (ord($b[0]) | (ord($b[1]) << 8) | (ord($b[2]) << 16) | (ord($b[3]) << 24)) & 0xFFFFFFFF;
    }
	
    public function i32(): int {
        $u = $this->u32();
		
        if ($u & 0x80000000) 
			return -((~$u & 0xFFFFFFFF) + 1);
		
        return $u;
    }

    public function guid(): string {
        $b  = $this->bytes(16);
        $d1 = unpack('V', substr($b, 0, 4))[1];
        $d2 = unpack('v', substr($b, 4, 2))[1];
        $d3 = unpack('v', substr($b, 6, 2))[1];
        $d4 = bin2hex(substr($b, 8, 2));
        $d5 = bin2hex(substr($b,10, 6));
		
        return sprintf('%08X-%04X-%04X-%s-%s', $d1, $d2, $d3, strtoupper($d4), strtoupper($d5));
    }
	
    public function index(): int {
		//file version 178 changed from CompactIndex to Int32
        if ($this->version > 178) {			
            return $this->i32();
        }
		
        $b0  = $this->u8();
        $neg = (($b0 & 0x80) !== 0);
        $con = (($b0 & 0x40) !== 0);
        $val = 0;

        if ($con) {
            $b1 = $this->u8(); $val = ($val << 7) + ($b1 & 0x7F);
            if ($b1 & 0x80) {
                $b2 = $this->u8(); $val = ($val << 7) + ($b2 & 0x7F);
                if ($b2 & 0x80) {
                    $b3 = $this->u8(); $val = ($val << 7) + ($b3 & 0x7F);
                    if ($b3 & 0x80) {
                        $b4 = $this->u8(); $val = $b4;
                    }
                }
            }
        }
		
        $val = ($val << 6) + ($b0 & 0x3F);
		
        return $neg ? -$val : $val;
    }
	
	/*
    public function index(): int {
        if ($this->version > 178) {			
            return $this->i32();
        }
		
        $b0  = $this->u8();
        $neg = (($b0 & 0x80) !== 0);
        $con = (($b0 & 0x40) !== 0);
        $val = 0;

        if ($con) {
            $b1 = $this->u8(); $val = ($val << 7) + ($b1 & 0x7F);
            if ($b1 & 0x80) {
                $b2 = $this->u8(); $val = ($val << 7) + ($b2 & 0x7F);
                if ($b2 & 0x80) {
                    $b3 = $this->u8(); $val = ($val << 7) + ($b3 & 0x7F);
                    if ($b3 & 0x80) {
                        $b4 = $this->u8(); $val = $b4;
                    }
                }
            }
        }
		
        $val = ($val << 6) + ($b0 & 0x3F);
		
        return $neg ? -$val : $val;
    }
	*/
	
	public function u64(): int {
		$b   = $this->bytes(8);
		$arr = unpack('P', $b); // little-endian unsigned 64-bit (PHP 7.0+)
		
		return $arr[1];
	}
	
	public function cstr(): string
	{
		$out = ''; // Read a null-terminated (ASCIIZ) string
		
		while (true) { // bytes(1) will throw if we hit EOF unexpectedly
			$b = $this->bytes(1);
			
			if ($b === "\x00") {
				break; // consume terminator and stop
			}
			
			$out .= $b;
		}
		
		return $out;
	}
}
?>