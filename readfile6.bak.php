<?php
/**
 * UnrealPackageReader.php — comprehensive UE1/UE2 package reader (web + CLI safe)
 *
 * - Header (magic, version/licensee, package flags decoded, counts/offsets, GUID)
 * - Generations (and best-effort Heritage)
 * - Name table (names + per-name RF flags decoded)
 * - Import table (INDEX, object refs resolved)
 * - Export table (INDEX, object refs resolved, object flags decoded)
 * - Per-export properties (complete walk; decodes Byte/Int/Float/Name/Object/String)
 * - Payload summaries for common native classes (Texture/Palette/Font/Sound/Music/TextBuffer)
 * - Script byte preview
 *
 * Works under CLI and Apache: no STDERR dependency.
 */

class UnrealPackageReader
{
    /* ===================== Public API ===================== */

    public function __construct(string $filePath) { $this->filePath = $filePath; }

    public function parse(): void {
        $this->open();
        $this->readHeader();
        $this->readGenerationsOrHeritage();
        $this->readNameTable();
        $this->readExportTable();
        $this->readImportTable();
        $this->close();
    }

    /** Exhaustive verification dump to STDOUT (CLI) */
    public function dumpAll(): void {
        if (!$this->isCli()) {
            echo $this->dumpAllToString();
            return;
        }
        echo $this->dumpAllToString();
    }
	
	// Read a UE FName: (index, number) — both are UE compact INDEX ints
	private function readFNameINDEX(): array
	{
		$index  = $this->readINDEX();
		$number = $this->readINDEX();
		return [$index, $number];
	}

	// Resolve a (index, number) pair to printable text
	private function resolveFNamePair(int $index, int $number): string
	{
		$base = $this->resolveName($index);
		if ($base === null || $base === '') return "#$index";
		// UE prints "Name_#Number" only when Number != 0
		return $number ? ($base . '_' . $number) : $base;
	}

	

    /** Exhaustive verification dump as a string (safe for web echo) */
    public function dumpAllToString(): string {
        $h = $this->getHeader();
        $out  = '';
        $out .= sprintf("Unreal File found. (0x%08X)\n\n", $h['magic']);
        $out .= "Version:          {$h['version']}\n";
        $out .= "License mode:     {$h['licensee']}\n";
        $out .= "Package flags:    0x".dechex($h['packageFlags']).$this->fmtList($h['packageFlagsDecoded'])."\n";
        $out .= "Name count:       {$h['nameCount']}\n";
        $out .= "Name offset:      {$h['nameOffset']}\n";
        $out .= "Export count:     {$h['exportCount']}\n";
        $out .= "Export offset:    {$h['exportOffset']}\n";
        $out .= "Import count:     {$h['importCount']}\n";
        $out .= "Import offset:    {$h['importOffset']}\n\n";
        $out .= "GUID:             {$h['guid']}\n\n";

        if (!empty($this->generations)) {
            $out .= "Generation count: " . count($this->generations) . "\n\n";
            foreach ($this->generations as $i=>$g) {
                $out .= "Generation[$i] Exports={$g['exports']} Names={$g['names']}";
                if (isset($g['netObjects'])) $out .= " NetObjects={$g['netObjects']}";
                $out .= "\n";
            }
            $out .= "\n";
        }
        if (!empty($this->heritage)) {
            $out .= "Heritage count: " . count($this->heritage) . "\n";
            foreach ($this->heritage as $i=>$guid) $out .= "Heritage[$i] $guid\n";
            $out .= "\n";
        }

        $names = $this->getNames();
        $out .= "*******Name Table (".count($names).":{$h['nameOffset']})\n";
        foreach ($names as $i=>$row) {
            $hex = "0x".dechex($row['flags']);
            $len = strlen($row['name']);
            $out .= ($i+1)." Name Text: ".($row['name']===''?'None':$row['name'])." ($len) - Flags={$hex}".$this->fmtList($row['flags_decoded'])."\n";
        }

		$exports = $this->getExports();
		$out .= "\n*******Export Table (".count($exports).":{$h['exportOffset']})\n";
		foreach ($exports as $i=>$ex) {
			// Pick a valid serial window (or get a reason if none valid)
			[$winOff, $winSize, $why] = $this->chooseSerialWindow($ex);
			$offHex = $winOff ? sprintf("0x%08X", $winOff) : "0x00000000";
			$out .= sprintf(
			  "Export[%d] ... Size=%d Off=%s%s (rawIdx=%d)\n",
			  $i, (int)$ex['serialSize'], $offHex, $winOff? '' : " ($why)", (int)$ex['serialOffsetRaw']
			);
}


		$imports = $this->getImports();
		$out .= "\n\n*******Import Table (".count($imports).":{$h['importOffset']})\n";
		foreach ($imports as $i=>$im) {
			$cpIdx = (int)$im['classPkgIdx'];
			$cnIdx = (int)$im['classNameIdx'];
			$pr    = $im['packageRef'];       // Import[x]/Export[y]/None
			$onIdx = (int)$im['objectNameIdx'];

			$out .= sprintf(
				"Import[%d] ClassPkgIdx=%d(\"%s\") ClassNameIdx=%d(\"%s\") PkgRef=%s ObjNameIdx=%d(\"%s\")\n",
				$i,
				$cpIdx, $this->resolveName($cpIdx),
				$cnIdx, $this->resolveName($cnIdx),
				$this->renderObjectRef($pr),
				$onIdx, $this->resolveName($onIdx)
			);
		}

        $out .= "\n\n*******Object Data (Properties + Payloads)*******\n";
		foreach ($exports as $i=>$ex) {
			$className = $this->resolveClassName($ex['classRef']);
			$out .= "Export[$i] \"{$ex['name_resolved']}\" (Class=$className):\n";

			[$winOff, $winSize, $why] = $this->chooseSerialWindow($ex);
			if ($winOff <= 0 || $winSize <= 0) {
				$out .= "(skipped properties: $why)\n";
				$out .= "(No serialized data)\n\n";
				continue;
			}

			$props = $this->getExportProperties($i);
			$out .= "  Properties (".count($props)."):\n";
			foreach ($props as $p) {
				$line = "    - {$p['name']} ({$p['typeName']}, size={$p['size']}";
				if (!empty($p['structName'])) $line .= ", struct={$p['structName']}";
				if ($p['arrayIdx'] !== null)  $line .= ", arrIdx={$p['arrayIdx']}";
				$line .= ")";
				if (array_key_exists('value',$p) && $p['value'] !== null) {
					$val = is_array($p['value']) ? json_encode($p['value']) : (string)$p['value'];
					$line .= " = {$val}";
				}
				$out .= $line."\n";
			}

			$payload = $this->inspectExportPayload($i);
			if (!empty($payload)) {
				$out .= "  Payload:\n";
				foreach ($payload as $k=>$v) {
					$out .= "    $k: ".(is_array($v)?json_encode($v):$v)."\n";
				}
			}

			// Previews use chosen window
			$preview = $this->readSerialPreview($winOff, $winSize, 32);
			if ($preview !== '') {
				$out .= "  Remainder preview (unparsed, first 32): ".strtoupper(bin2hex($preview))."\n";
			}

			$out .= "\n";
		}



        return $out;
    }

    /* ===================== Getters ===================== */
    public function getHeader(): array { return $this->header; }
    public function getGenerations(): array { return $this->generations; }
    public function getHeritage(): array { return $this->heritage; }
    public function getNames(): array { return $this->names; }
    public function getExports(): array { return $this->exports; }
    public function getImports(): array { return $this->imports; }



/* ===================== getExportProperties ===================== */
private array $exportProps = [];
private array $exportPropNotes = [];

private function getExportProperties(int $i): array
{
    if (isset($this->exportProps[$i])) return $this->exportProps[$i];

    $ex = $this->exports[$i];
    [$off,$size,$note] = $this->chooseSerialWindow($ex);
    if ($off <= 0 || $size <= 0) {
        $this->exportPropNotes[$i] = $note;
        return $this->exportProps[$i] = [];
    }

    $fp = $this->openTemp();
    $props = $this->readPropertiesWindowed($fp, $off, $size);
    fclose($fp);

    $this->exportProps[$i] = $props;
    return $props;
}



    /* ===================== State ===================== */
    private string $filePath;
    private $fp = null;

    private array $header = [];
    private array $generations = [];
    private array $heritage = [];
    private array $names = [];
    private array $exports = [];
    private array $imports = [];
    //private array $exportProps = [];

    /* ===================== Flag Maps ===================== */
	private array $PACKAGE_FLAG_MAP = [
		0x00000001 => 'PKG_AllowDownload',
		0x00000002 => 'PKG_ClientOptional',
		0x00000004 => 'PKG_ServerSideOnly',
		0x00000008 => 'PKG_BrokenLinks',
		0x00000010 => 'PKG_Unsecure',
		0x00000020 => 'PKG_Encrypted',   // seen in some builds/viewers
		0x00008000 => 'PKG_Need',
	];
	
    private array $NAME_FLAG_MAP = [
        0x00000010 => 'RF_TagExp', 0x00000020 => 'RF_TagImp', 0x00000040 => 'RF_TagGarbage',
        0x00010000 => 'RF_LoadForClient', 0x00020000 => 'RF_LoadForServer', 0x00040000 => 'RF_LoadForEdit',
        0x00080000 => 'RF_Standalone', 0x00100000 => 'RF_NotForClient', 0x00200000 => 'RF_NotForServer',
        0x00400000 => 'RF_HighlightedName', 0x00800000 => 'RF_InSingularFunc',
        0x01000000 => 'RF_SourceModified', 0x02000000 => 'RF_HasStack', 0x04000000 => 'RF_Native', 0x08000000 => 'RF_Transactional',
    ];
    private array $OBJ_FLAG_MAP = [
        0x00000001 => 'RF_Transactional', 0x00000002 => 'RF_NotForEdit', 0x00000004 => 'RF_Public', 0x00000008 => 'RF_Standalone',
        0x00000020 => 'RF_NeedPostLoad', 0x00000040 => 'RF_NeedLoad', 0x00000080 => 'RF_Unreachable',
        0x00000100 => 'RF_ErrorShutdown', 0x00000200 => 'RF_DebugDestroy', 0x00000400 => 'RF_DebugPostLoad', 0x00000800 => 'RF_DebugSerialize',
        0x00001000 => 'RF_PreLoading', 0x00002000 => 'RF_InEndState', 0x00004000 => 'RF_Transient',
        0x00010000 => 'RF_LoadForClient', 0x00020000 => 'RF_LoadForServer', 0x00040000 => 'RF_LoadForEdit', 0x00080000 => 'RF_Standalone',
        0x00100000 => 'RF_NotForClient', 0x00200000 => 'RF_NotForServer', 0x00400000 => 'RF_HighlightedName', 0x00800000 => 'RF_InSingularFunc',
        0x01000000 => 'RF_SourceModified', 0x02000000 => 'RF_HasStack', 0x04000000 => 'RF_Native',
        0x08000000 => 'RF_TagExp', 0x10000000 => 'RF_TagImp', 0x20000000 => 'RF_TagGarbage',
    ];

    /* ===================== Core parsing ===================== */
    private function open(): void {
        $this->fp = @fopen($this->filePath, 'rb');
        if (!$this->fp) throw new \RuntimeException("Failed to open file: {$this->filePath}");
    }
    private function openTemp() {
        $fp = @fopen($this->filePath, 'rb');
        if (!$fp) throw new \RuntimeException("Failed to reopen file: {$this->filePath}");
        return $fp;
    }
    private function close(): void {
        if (is_resource($this->fp)) fclose($this->fp);
        $this->fp = null;
    }
	
	/* ===================== REPLACEMENT: print Export Table ===================== */
	private function printExportTable(): void
	{
		$count = (int)$this->header['exportCount'];
		$off   = (int)$this->header['exportOffset'];
		echo "*******Export Table ($count:$off)\n";
		foreach ($this->exports as $ex) {
			// Prefer a valid window; falls back with a reason if none works
			[$winOff, $winSize, $note] = $this->chooseSerialWindow($ex);
			$offStr  = ($winOff > 0) ? (string)$winOff : '0';
			$noteStr = ($winOff > 0) ? "" : " ($note)";

			$cls = $ex['classRef'];
			$sup = $ex['superRef'];
			$pkg = $ex['pkgRef'];
			$nmI = $ex['objectNameIdx'];
			$nmT = $this->resolveName($nmI);

			$flags = $ex['objectFlags'];
			$flagsDecoded = $this->decodeFlags($flags, $this->OBJ_FLAG_MAP);

			printf(
				"Export[%d] Class=%s Super=%s PkgRef=%s NameIdx=%d(\"%s\") Flags=0x%x [%s] Size=%d Off=%s%s\n",
				$ex['index'],
				$cls ?: 'None',
				$sup ?: 'None',
				$pkg ?: 'None',
				$nmI, $nmT,
				$flags, $flagsDecoded ?: '',
				(int)$ex['serialSize'],
				$offStr,
				$noteStr
			);
		}
		echo "\n\n";
	}
	/* ===================== END REPLACEMENT ===================== */

	/* ===================== REPLACEMENT: print Import Table ===================== */
	private function printImportTable(): void
	{
		$count = (int)$this->header['importCount'];
		$off   = (int)$this->header['importOffset'];
		echo "*******Import Table ($count:$off)\n";
		foreach ($this->imports as $i => $im) {
			$cpIdx = (int)$im['classPkgIdx'];
			$cnIdx = (int)$im['classNameIdx'];
			$pr    = $im['packageRef'];  // object ref (Import[x]/Export[y]/None)
			$onIdx = (int)$im['objectNameIdx'];

			$cpTxt = $this->resolveName($cpIdx);
			$cnTxt = $this->resolveName($cnIdx);
			$onTxt = $this->resolveName($onIdx);

			printf(
				"Import[%d] ClassPkgIdx=%d(\"%s\") ClassNameIdx=%d(\"%s\") PkgRef=%s ObjNameIdx=%d(\"%s\")\n",
				$i,
				$cpIdx, $cpTxt,
				$cnIdx, $cnTxt,
				$this->renderObjectRef($pr),
				$onIdx, $onTxt
			);
		}
		echo "\n\n";
	}
	/* ===================== END REPLACEMENT ===================== */

	/* ===================== REPLACEMENT: print Object Data ===================== */
	private function printObjectData(): void
	{
		echo "*******Object Data (Properties + Payloads)*******\n";
		foreach ($this->exports as $ex) {
			$nm = $this->resolveName($ex['objectNameIdx']);
			$clsPretty = $ex['classRef'] ?: 'Import?';
			printf("Export[%d] \"%s\" (Class=%s):\n", $ex['index'], $nm, $clsPretty);

			$props = $this->getExportProperties($ex['index']);

			// Optional “why” when properties are skipped or empty due to unusable window
			if (empty($props)) {
				$note = $this->exportPropNotes[$ex['index']] ?? null;
				if ($note) {
					echo "(skipped properties: $note)\n";
				}
			}

			printf("Properties (%d):\n", count($props));
			foreach ($props as $p) {
				// name, (typeName, size=, arrIdx=)
				$arr = ($p['arrayIdx'] !== null) ? (", arrIdx=".$p['arrayIdx']) : "";
				$extra = ($p['structName']) ? (" <".$p['structName'].">") : "";
				printf("- %s (%s, size=%d%s)%s\n",
					$p['name'], $p['typeName'], (int)$p['size'], $arr, $extra
				);
			}

			// Minimal payload summary you already had:
			$payload = $this->summarizePayloadByClass($ex); // keep your implementation
			if ($payload) {
				echo "Payload:\n";
				foreach ($payload as $k => $v) {
					if (is_array($v)) $v = json_encode($v);
					echo "$k: $v\n";
				}
			} else {
				echo "(No serialized data)\n";
			}

			// Remainder preview using the *chosen* window
			[$winOff, $winSize] = $this->chooseSerialWindow($ex);
			if ($winOff > 0 && $winSize > 0) {
				$preview = $this->readSerialPreview($winOff, $winSize, 32);
				if ($preview !== '') {
					$hex = strtoupper(bin2hex($preview));
					echo "previewHex: $hex\n";
				}
			}

			echo "\n";
		}
	}
	/* ===================== END REPLACEMENT ===================== */

    private function readHeader(): void {
        $magic = $this->readDWORD();
        $pkgVersionDW = $this->readDWORD();
        $version  =  $pkgVersionDW        & 0xFFFF;
        $licensee = ($pkgVersionDW >> 16) & 0xFFFF;

        $packageFlags = $this->readDWORD();

        $nameCount   = $this->readDWORD();
        $nameOffset  = $this->readDWORD();
        $exportCount = $this->readDWORD();
        $exportOffset= $this->readDWORD();
        $importCount = $this->readDWORD();
        $importOffset= $this->readDWORD();

        $guid = $this->readGUID();

        $this->header = [
            'magic'=>$magic,'pkgVersionDW'=>$pkgVersionDW,'version'=>$version,'licensee'=>$licensee,
            'packageFlags'=>$packageFlags,'packageFlagsDecoded'=>$this->decodeFlags($packageFlags,$this->PACKAGE_FLAG_MAP),
            'nameCount'=>$nameCount,'nameOffset'=>$nameOffset,'exportCount'=>$exportCount,'exportOffset'=>$exportOffset,
            'importCount'=>$importCount,'importOffset'=>$importOffset,'guid'=>$guid,
        ];
    }

    private function readGenerationsOrHeritage(): void {
        $start = ftell($this->fp);
        $genCount = $this->readDWORD();
        if ($genCount < 0 || $genCount > 100000) {
            fseek($this->fp,$start,SEEK_SET);
            $this->generations = [];
            $this->heritage = $this->tryReadHeritage();
            return;
        }
        $gens = [];
        for ($i=0;$i<$genCount;$i++){
            $exp=$this->readDWORD(); $nam=$this->readDWORD();
            $entry=['exports'=>$exp,'names'=>$nam];
            $gens[]=$entry;
        }
        $this->generations=$gens; $this->heritage=[];
    }

    private function tryReadHeritage(): array {
        $list=[]; $count=$this->readDWORD();
        if ($count<0 || $count>1024) return [];
        for($i=0;$i<$count;$i++) $list[]=$this->readGUID();
        return $list;
    }

	private int $nameTableStart = 0;
	private int $nameTableEnd   = 0;

	private function readNameTable(): void {
		$ver   = (int)$this->header['version'];
		$count = (int)$this->header['nameCount'];
		$off   = (int)$this->header['nameOffset'];

		fseek($this->fp, $off, SEEK_SET);
		$this->nameTableStart = $off;

		$names = [];
		for ($i = 0; $i < $count; $i++) {
			$nm    = $this->readNAME($ver);
			$flags = $this->readDWORD();
			$names[$i] = [
				'name' => $nm,
				'flags' => $flags,
				'flags_decoded' => $this->decodeFlags($flags, $this->NAME_FLAG_MAP),
			];
		}
		$this->names = $names;
		$this->nameTableEnd = ftell($this->fp);
	}

	// -------------------------------------------------------------------------
	// Correct Unreal Tournament 2004 (v127) Export Table reader
	// -------------------------------------------------------------------------
	private int $exportTableStart = 0;
	private int $exportTableEnd   = 0;
	// Keep your PACKAGE_FLAG_MAP etc. exactly as you already have them.

	/* ===================== readExportTable ===================== */
private function readExportTable(): void
{
    $count = (int)$this->header['exportCount'];
    $off   = (int)$this->header['exportOffset'];

    $fsz = $this->fileSize();
    if ($off < 0 || $off >= $fsz) {
        throw new \RuntimeException("Export offset out of file: off=$off fsize=$fsz");
    }

    fseek($this->fp, $off, SEEK_SET);

    $exps = [];
    for ($i=0; $i<$count; $i++) {
        $classIdx   = $this->readINDEX();
        $superIdx   = $this->readINDEX();
        $outerDword = $this->readDWORD();    // UT2003/4: Outer as DWORD
        $nameIdx    = $this->readINDEX();
        $objFlags   = $this->readDWORD();
        $serialSize = $this->readINDEX();

        $serialOffIdx = 0;
        if ($serialSize > 0) {
            $serialOffIdx = $this->readINDEX();
        }

        $candRaw = ($serialSize>0) ? $serialOffIdx         : 0;
        $cand28  = ($serialSize>0) ? ($serialOffIdx + 28)  : 0;

        $exps[$i] = [
            'index'              => $i,
            'classIdx'           => $classIdx,
            'superIdx'           => $superIdx,
            'packageRef'         => $outerDword,
            'objectNameIdx'      => $nameIdx,
            'objectFlags'        => $objFlags,
            'serialSize'         => $serialSize,
            'serialOffsetRaw'    => $candRaw,
            'serialOffsetPlus28' => $cand28,

            'objectFlagsDecoded' => $this->decodeFlags($objFlags, $this->OBJ_FLAG_MAP),
            'classRef'           => $this->renderObjectRef($classIdx),
            'superRef'           => $this->renderObjectRef($superIdx),
            'pkgRef'             => $this->renderObjectRef($outerDword),
            'name_resolved'      => $this->resolveName($nameIdx),
        ];
    }

    $this->exports = $exps;
}



	private int $importTableStart = 0;
	private int $importTableEnd   = 0;

	/* ===================== REPLACEMENT: readImportTable ===================== */
	private function readImportTable(): void
	{
		$count = (int)$this->header['importCount'];
		$off   = (int)$this->header['importOffset'];

		fseek($this->fp, $off, SEEK_SET);
		$this->importTableStart = $off;

		$imps = [];
		for ($i = 0; $i < $count; $i++) {
			// Import format (UE2): ClassPackage (INDEX Name), ClassName (INDEX Name),
			// PackageRef (INDEX Obj), ObjectName (INDEX Name)
			$classPkgIdx = $this->readINDEX();
			$classNameIdx = $this->readINDEX();
			$pkgRefIdx = $this->readINDEX();
			$objNameIdx = $this->readINDEX();

			$imps[$i] = [
				'index'        => $i,
				'classPkgIdx'  => $classPkgIdx,
				'classNameIdx' => $classNameIdx,
				'packageRef'   => $pkgRefIdx,
				'objectNameIdx'=> $objNameIdx,

				// pretty
				'classPkg'     => $this->resolveName($classPkgIdx),
				'className'    => $this->resolveName($classNameIdx),
				'pkgRef'       => $this->renderObjectRef($pkgRefIdx),
				'objName'      => $this->resolveName($objNameIdx),
			];
		}

		$this->imports = $imps;
		$this->importTableEnd = ftell($this->fp);
	}
	/* ===================== END REPLACEMENT: readImportTable ===================== */

    /* ===================== Properties ===================== */
    // Window-bounded property reader: parses UE1/UE2 serial property stream
	// starting at current $fp position, for exactly $serialSize bytes.
	// Never throws on short reads inside the window; it stops gracefully.
	// Read a UE1/UE2 property stream that is windowed to [serialOffset, serialOffset+serialSize).
	// Will stop cleanly if it hits the window boundary; never overruns.
	/* ===================== readPropertiesWindowed ===================== */
private function readPropertiesWindowed($fp, int $serialOffset, int $serialSize): array
{
    $props = [];
    fseek($fp, $serialOffset, SEEK_SET);
    $windowEnd = $serialOffset + max(0,$serialSize);

    $remaining = function() use ($fp,$windowEnd): int {
        return max(0, $windowEnd - ftell($fp));
    };

    while (ftell($fp) < $windowEnd) {
        $propStart = ftell($fp);

        // Name index
        if ($remaining() <= 0) break;
        $nameIdx = $this->readINDEX_stream($fp);
        if ($nameIdx === 0) break; // None => end

        $nameText = $this->resolveName($nameIdx);

        // Info byte
        if ($remaining() <= 0) break;
        $info = ord(fread($fp,1));
        $type     =  $info & 0x0F;          // 0-based type
        $sizeCode = ($info >> 4) & 0x07;    // 0..7
        $topBit   =  ($info & 0x80) !== 0;  // Bool value OR array flag

        // Array index (for non-bool when topBit=1)
        $arrayIdx = null;
        if ($type !== 0x02 && $topBit) { // not BoolProperty
            if ($remaining() <= 0) break;
            $arrayIdx = $this->readArrayIndex_stream($fp);
        }

        // Struct name (if StructProperty)
        $structName = null;
        if ($type === 0x09) { // StructProperty
            if ($remaining() <= 0) break;
            $sIdx = $this->readINDEX_stream($fp);
            $structName = $this->resolveName($sIdx);
        }

        // Payload size
        $payloadSize = 0;
        if ($type === 0x02) {
            $payloadSize = 0; // Bool has no payload; value in $topBit
        } else {
            // sizeCode: 0->1, 1->2, 2->4, 3->12, 4->16, 5->??, 6->??, 7->INDEX (read size)
            if ($remaining() <= 0) break;
            $payloadSize = $this->sizeFromSizeCode_stream($fp, $sizeCode);
        }

        // Payload
        $payload = '';
        if ($payloadSize > 0) {
            $take = min($payloadSize, $remaining());
            if ($take <= 0) break;
            $payload = fread($fp, $take) ?: '';
        }

        // Decode value
        $decoded = ($type === 0x02) ? (bool)$topBit
                                    : $this->decodePropertyValue($type, $payload, $structName);

        $props[] = [
            'nameIdx'    => $nameIdx,
            'name'       => $nameText,
            'type'       => $type,
            'typeName'   => $this->propertyTypeName($type),
            'sizeCode'   => $sizeCode,
            'bool'       => ($type === 0x02) ? (bool)$topBit : null,
            'arrayIdx'   => $arrayIdx,
            'size'       => $payloadSize,
            'value'      => $decoded,
            'structName' => $structName,
            'pos'        => $propStart,
        ];

        // Bail if truncated
        if ($payloadSize > 0 && strlen($payload) < $payloadSize) break;
    }

    return $props;
}




/* ===================== propertyTypeName (0-based UE IDs) ===================== */
private function propertyTypeName(int $t): string {
    static $MAP = [
        0x00=>'ByteProperty',   0x01=>'IntProperty',     0x02=>'BoolProperty',  0x03=>'FloatProperty',
        0x04=>'ObjectProperty', 0x05=>'NameProperty',    0x06=>'StringProperty',0x07=>'ClassProperty',
        0x08=>'ArrayProperty',  0x09=>'StructProperty',  0x0A=>'VectorProperty',0x0B=>'RotatorProperty',
        0x0C=>'StrProperty',    0x0D=>'MapProperty',     0x0E=>'FixedArrayProperty', 0x0F=>'DelegateProperty'
    ];
    return $MAP[$t] ?? ("Type0x".dechex($t));
}


    private function isStructTypeAlias(int $t): bool { return ($t===0x09||$t===0x0A||$t===0x0B); }

    /* ===================== decodePropertyValue ===================== */
private function decodePropertyValue(int $type, string $raw, ?string $structName=null) {
    $len = strlen($raw);

    if ($type === 0x09) { // StructProperty
        return $this->decodeStructValue($structName, $raw);
    }

    switch ($type) {
        case 0x00: // ByteProperty
            return ord($raw[0] ?? "\x00");

        case 0x01: // IntProperty
            if ($len < 4) return null;
            $v = unpack('Vval', $raw)['val'];
            return ($v & 0x80000000) ? -((~$v & 0xFFFFFFFF) + 1) : $v;

        case 0x02: // BoolProperty (value handled in header)
            return null;

        case 0x03: // FloatProperty
            if ($len < 4) return null;
            return unpack('gval', $raw)['val'];

        case 0x04: // ObjectProperty
        {
            $idx = $this->indexFromRaw($raw);
            return ['objectRef'=>$this->renderObjectRef($idx), 'indexRaw'=>$idx];
        }

        case 0x05: // NameProperty
        {
            $idx = $this->indexFromRaw($raw);
            return ['nameIndex'=>$idx, 'name'=>$this->resolveName($idx)];
        }

        case 0x06: // StringProperty (legacy) / 0x0C StrProperty (handled below too)
            return $this->safeAscii($raw);

        case 0x07: // ClassProperty
        {
            $idx = $this->indexFromRaw($raw);
            return ['classRef'=>$this->renderObjectRef($idx), 'indexRaw'=>$idx];
        }

        case 0x08: // ArrayProperty (opaque blob)
            return ['len'=>$len, 'hex'=>strtoupper(bin2hex(substr($raw,0,32)))];

        case 0x0A: // VectorProperty
            if ($len < 12) return null;
            $a = unpack('gX/gY/gZ', $raw);
            return ['X'=>$a['X'], 'Y'=>$a['Y'], 'Z'=>$a['Z']];

        case 0x0B: // RotatorProperty
            if ($len < 12) return null;
            $a = unpack('VPitch/VYaw/VRoll', $raw);
            foreach ($a as $k=>$v) if ($v & 0x80000000) $a[$k] = -((~$v & 0xFFFFFFFF) + 1);
            return $a;

        case 0x0C: // StrProperty
            return $this->safeAscii($raw);

        case 0x0D: // MapProperty
        case 0x0E: // FixedArrayProperty
        default:
            return ['len'=>$len, 'hex'=>strtoupper(bin2hex(substr($raw,0,32)))];
    }
}




/* ===================== indexFromRaw (decode INDEX or fixed 1/2/4 LE) ===================== */
private function indexFromRaw(string $raw): int {
    $n = strlen($raw);
    if ($n <= 0) return 0;

    // If the first byte has "continuation" bit (0x40) it's a full INDEX encoding
    if (($n > 1) && ((ord($raw[0]) & 0x40) !== 0)) {
        $fp = fopen('php://memory','rb+'); fwrite($fp,$raw); rewind($fp);
        return $this->readINDEX_stream($fp);
    }

    // Otherwise treat as little-endian signed of n bytes
    $v = 0; for ($i=0; $i<$n; $i++) $v |= (ord($raw[$i]) << (8*$i));
    $bits = 8*$n; $signbit = 1 << ($bits-1);
    if ($v & $signbit) { $mask = (1<<$bits)-1; $v = -((~$v & $mask) + 1); }
    return $v;
}

/* ===================== safeAscii (printable only) ===================== */
private function safeAscii(string $s): string {
    return preg_replace('/[^\x20-\x7E]/','.', $s);
}

/* ===================== decodeStructValue (Color/Vector/Rotator) ===================== */
private function decodeStructValue(?string $structName, string $raw) {
    if ($structName === 'Color' && strlen($raw) >= 4) {
        $a = unpack('C R/C G/C B/C A', $raw);
        return ['Color'=>$a];
    }
    if ($structName === 'Vector' && strlen($raw) >= 12) {
        $a = unpack('gX/gY/gZ', $raw);
        return ['Vector'=>$a];
    }
    if ($structName === 'Rotator' && strlen($raw) >= 12) {
        $a = unpack('VPitch/VYaw/VRoll', $raw);
        foreach ($a as $k=>$v) if ($v & 0x80000000) $a[$k] = -((~$v & 0xFFFFFFFF) + 1);
        return ['Rotator'=>$a];
    }
    return ['struct'=>$structName, 'len'=>strlen($raw), 'hex'=>strtoupper(bin2hex(substr($raw,0,32)))];
}


    private function bestEffortIndexFromRaw(string $raw): ?int {
        $b=array_values(unpack('C*',$raw)); $n=count($b); if ($n<1) return null;
        $p=0; $B0=$b[$p++]; $neg=($B0&0x80)!==0; $more=($B0&0x40)!==0; $val=($B0&0x3F);
        if ($more && $p<$n){ $B1=$b[$p++]; $val=($val<<7)|($B1&0x7F);
            if (($B1&0x80)&&$p<$n){ $B2=$b[$p++]; $val=($val<<7)|($B2&0x7F);
                if(($B2&0x80)&&$p<$n){ $B3=$b[$p++]; $val=($val<<7)|($B3&0x7F);
                    if(($B3&0x80)&&$p<$n){ $B4=$b[$p++]; $val=($val<<8)|$B4; }}}}
        return $neg?-$val:$val;
    }

    /* ===================== Payload inspectors (bounded) ===================== */
/* ===================== Payload inspectors (bounded) ===================== */
public function inspectExportPayload(int $exportIndex): array {
    if (!isset($this->exports[$exportIndex])) return [];
    $ex = $this->exports[$exportIndex];

    // Validate serial window using chooser (raw → +28)
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    if ($off <= 0 || $size <= 0) return []; // nothing to inspect

    $class = strtolower($this->resolveClassName($ex['classRef']));
    switch ($class) {
        case 'texture':      return $this->payloadTexture($ex);
        case 'palette':      return $this->payloadPalette($ex);
        case 'font':         return $this->payloadFont($ex);
        case 'sound':        return $this->payloadSound($ex);
        case 'music':        return $this->payloadMusic($ex);
        case 'textbuffer':   return $this->payloadTextBuffer($ex);
        case 'mesh':         return $this->payloadMesh($ex);
        case 'lodmesh':      return $this->payloadLodMesh($ex);
        case 'skeletalmesh': return $this->payloadSkelMesh($ex);
        case 'animation':    return $this->payloadAnimation($ex);
        default:             return [];
    }
}


    private function payloadTexture(array $ex): array
{
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $props = $this->getExportProperties($ex['index']);

    $out = [
        'type'   => 'Texture',
        'name'   => $ex['name_resolved'],
        'serial' => ['offset' => $off, 'size' => $size, 'note' => $why],
        'props'  => [],
    ];

    // Pull common texture props straight from the property map
    $want = ['USize','VSize','Format','UBits','VBits','UClamp','VClamp','TexCoordSource',
             'Material','Diffuse','Specular','Shader','InternalTime','Color'];
    foreach ($props as $p) {
        if (in_array($p['name'], $want, true)) {
            $out['props'][$p['name']] = $p['value'] ?? ($p['raw'] ?? null);
        }
    }

    // Derive mip levels from USize/VSize
    $u = $this->propInt($props,'USize');
    $v = $this->propInt($props,'VSize');
    if ($u && $v) {
        $mips = [];
        $w = $u; $h = $v;
        for ($l = 0; $l < 12; $l++) {
            $mips[] = ['level'=>$l, 'width'=>$w, 'height'=>$h];
            if ($w === 1 && $h === 1) break;
            $w = max(1, $w >> 1);
            $h = max(1, $h >> 1);
        }
        $out['mips'] = $mips;
    }

    // Quick preview from start of serial
    $preview = $this->readSerialPreview($off, $size, 32);
    if ($preview !== '') $out['preview'] = strtoupper(bin2hex($preview));

    return $out;
}


    private function scanMips(array $ex, array $props): array {
        $mips=[]; $uSize=$this->propInt($props,'USize'); $vSize=$this->propInt($props,'VSize');
        if ($uSize && $vSize) {
            $mips[]=['width'=>$uSize,'height'=>$vSize,'note'=>'from properties'];
            $w=$uSize; $h=$vSize;
            for($l=1;$l<12;$l++){ $w=max(1,$w>>1); $h=max(1,$h>>1); $mips[]=['width'=>$w,'height'=>$h,'note'=>'derived']; if($w===1&&$h===1)break; }
        }
        return $mips;
    }
    private function propInt(array $props,string $name): ?int {
        foreach($props as $p) if($p['name']===$name && is_numeric($p['value']??null)) return (int)$p['value'];
        return null;
    }

private function payloadPalette(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,32);
    return [
        'type'   => 'Palette',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}


private function payloadFont(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,32);
    return [
        'type'   => 'Font',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}


private function payloadSound(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $props = $this->getExportProperties($ex['index']);
    $fmt   = $this->propName($props,'SoundFormat');
    $szFld = $this->guessSizeFromProps($props,['SoundSize','DataSize','Size']);
    $preview = $this->readSerialPreview($off,$size,32);
    return [
        'type'      => 'Sound',
        'format'    => $fmt,
        'sizeField' => $szFld,
        'serial'    => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'   => strtoupper(bin2hex($preview))
    ];
}


private function payloadMusic(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $props = $this->getExportProperties($ex['index']);
    $fmt = $this->propName($props,'MusicFormat') ?? $this->propName($props,'Format');
    $preview = $this->readSerialPreview($off,$size,32);
    return [
        'type'   => 'Music',
        'format' => $fmt,
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}


private function payloadTextBuffer(array $ex): array
{
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $props  = $this->getExportProperties($ex['index']);
    $pos    = $this->propInt($props, 'Pos');
    $top    = $this->propInt($props, 'Top');
    $textSz = $this->propInt($props, 'TextSize');

    $take = min($size, max(128, (int)$textSz));
    $blob = $this->readSerialPreview($off, $size, $take);

    return [
        'type'   => 'TextBuffer',
        'pos'    => $pos,
        'top'    => $top,
        'textSz' => $textSz,
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($blob))
    ];
}


private function payloadMesh(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,64);
    return [
        'type'   => 'Mesh',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}

private function payloadLodMesh(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,64);
    return [
        'type'   => 'LodMesh',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}

private function payloadSkelMesh(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,64);
    return [
        'type'   => 'SkeletalMesh',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}

private function payloadAnimation(array $ex): array {
    [$off,$size,$why] = $this->chooseSerialWindow($ex);
    $preview = $this->readSerialPreview($off,$size,64);
    return [
        'type'   => 'Animation',
        'serial' => ['offset'=>$off,'size'=>$size,'note'=>$why],
        'preview'=> strtoupper(bin2hex($preview))
    ];
}


    /* ===================== Script/Preview helpers ===================== */
    private function mightHaveScript(array $ex): bool {
        $hasStack = in_array('RF_HasStack',$ex['objectFlagsDecoded'],true);
        $cn = strtolower($this->resolveClassName($ex['classRef']));
        return $hasStack || in_array($cn,['function','state','class'],true);
    }
/* ===================== readSerialPreview ===================== */
private function readSerialPreview(int $off, int $size, int $take = 32): string
{
    if (!$this->isUsableSerialWindow($off,$size)) return '';
    $fp = $this->openTemp();
    fseek($fp, $off, SEEK_SET);
    $blob = ($take > 0) ? fread($fp, min($take, $size)) : fread($fp, $size);
    fclose($fp);
    return $blob ?: '';
}

/* ===================== previewRemainder ===================== */
private function previewRemainder(array $ex,int $n): ?string {
    $size = (int)$ex['serialSize'];
    if ($size<=0) return null;
    [$off,$size2,$why] = $this->chooseSerialWindow($ex);
    if ($off<=0 || $size2<=0) return null;
    return $this->readSerialPreview($off,$size2,$n);
}



    /* ===================== Utilities ===================== */
    private function propName(array $props,string $name): ?string {
        foreach($props as $p) if($p['name']===$name){
            if (is_array($p['value']??null) && isset($p['value']['name'])) return (string)$p['value']['name'];
            if (is_string($p['value']??null)) return $p['value'];
        }
        return null;
    }
    private function guessSizeFromProps(array $props,array $candidates): ?int {
        foreach($candidates as $n){ foreach($props as $p){ if($p['name']===$n && is_numeric($p['value']??null)) return (int)$p['value']; } }
        return null;
    }

    private function resolveName(int $idx): string {
        if ($idx<0 || $idx>=count($this->names)) return "#{$idx}";
        $n=$this->names[$idx]['name']; return ($n===''?'None':$n);
    }
    private function resolveClassName(string $ref): string {
        if ($ref==='None') return 'None';
        if (strpos($ref,'Import[')===0){ $i=(int)substr($ref,7,-1); return $this->imports[$i]['objectName']??'Import?'; }
        if (strpos($ref,'Export[')===0){ $i=(int)substr($ref,7,-1); return $this->exports[$i]['name_resolved']??'Export?'; }
        return 'Unknown';
    }
    private function renderObjectRef(int $idx): string {
        if ($idx===0) return 'None'; if ($idx<0) return 'Import['.(-$idx-1).']'; return 'Export['.($idx-1).']';
    }
    private function decodeFlags(int $flags,array $map): array {
        $out=[]; foreach($map as $mask=>$name){ if(($flags & $mask)===$mask) $out[]=$name; } return $out;
    }
    private function fmtList(array $a): string { return empty($a)?'':' ['.implode(',',$a).']'; }

    private function logErr(string $msg): void {
        if ($this->isCli() && defined('STDERR')) { fwrite(STDERR, $msg); }
        else { error_log($msg); }
    }
    private function isCli(): bool { return (PHP_SAPI === 'cli'); }

    /* ===================== Low-level readers ===================== */

    private function readBYTE(): int { $s=fread($this->fp,1); if($s===''||$s===false) throw new \RuntimeException("EOF BYTE"); return ord($s); }
    private function readWORD(): int { $s=fread($this->fp,2); if($s===''||strlen($s)!==2) throw new \RuntimeException("EOF WORD"); return unpack('vval',$s)['val']; }
    private function readDWORD(): int { $s=fread($this->fp,4); if($s===''||strlen($s)!==4) throw new \RuntimeException("EOF DWORD"); return unpack('Vval',$s)['val']; }
	// ---- SAFE string reader: bounded & chunked to avoid OOM ----
	private function readSTRING(int $len): string
	{
		if ($len <= 0) return '';
		$remaining = $this->remainingBytes();
		if ($len > $remaining) {
			throw new \RuntimeException("EOF STRING (len=$len > remaining=$remaining)");
		}
		// Hard guard: names/strings in UE packages are small; cap to avoid OOM from corrupt len.
		// You can raise this if you know you have large blobs here, but names should be tiny.
		$MAX_SAFE = 1 << 20; // 1 MB
		if ($len > $MAX_SAFE) {
			throw new \RuntimeException("Suspicious STRING length $len (> $MAX_SAFE)");
		}

		// Read in chunks to avoid a single huge allocation (defensive)
		$buf = '';
		$toRead = $len;
		while ($toRead > 0) {
			$chunk = fread($this->fp, min($toRead, 65536));
			if ($chunk === false || $chunk === '') {
				throw new \RuntimeException("EOF STRING while chunked read (wanted=$len, got=".strlen($buf).")");
			}
			$buf .= $chunk;
			$toRead -= strlen($chunk);
		}
		return $buf;
	}

    private function readNullSTRING(): string { $b=''; while(true){ $c=fread($this->fp,1); if($c===''||$c===false) throw new \RuntimeException("EOF ASCIIZ"); if($c==="\x00") break; $b.=$c; } return $b; }

/* ===================== readINDEX (from $this->fp) ===================== */
private function readINDEX(): int {
    $b0  = $this->readBYTE();
    $neg = ($b0 & 0x80) !== 0;
    $val =  ($b0 & 0x3F);      // 6 bits
    if (($b0 & 0x40) !== 0) {  // continuation
        $shift = 6;
        for ($i=0;$i<4;$i++){
            $bi = $this->readBYTE();
            $val |= (($bi & 0x7F) << $shift); // 7 bits per extra byte
            $shift += 7;
            if (($bi & 0x80) === 0) break;    // last
        }
    }
    return $neg ? -$val : $val;
}

/* ===================== readINDEX_stream (from supplied $fp) ===================== */
private function readINDEX_stream($fp): int {
    $b0  = ord(fread($fp,1));
    $neg = ($b0 & 0x80) !== 0;
    $val =  ($b0 & 0x3F);
    if (($b0 & 0x40) !== 0) {
        $shift = 6;
        for ($i=0;$i<4;$i++){
            $bi = ord(fread($fp,1));
            $val |= (($bi & 0x7F) << $shift);
            $shift += 7;
            if (($bi & 0x80) === 0) break;
        }
    }
    return $neg ? -$val : $val;
}

/* ===================== Array-index encoding used in prop headers ===================== */
private function readArrayIndex_stream($fp): int {
    $b = ord(fread($fp,1));
    if (($b & 0x80) === 0) return $b;
    if (($b & 0xC0) === 0x80) {
        $c = ord(fread($fp,1));
        return (($b & 0x3F) << 8) | $c;
    }
    $c = ord(fread($fp,1));
    $d = ord(fread($fp,1));
    $e = ord(fread($fp,1));
    return (($b & 0x3F) << 24) | ($c << 16) | ($d << 8) | $e;
}


	
	// ---- Robust name reader for UE1/UE2 ----
	// Preferred: INT32 signed length; >0 = ANSI/UTF-8 bytes (incl. trailing 0x00),
	//           <0 = UTF-16LE words (abs(len) 16-bit units incl. trailing 0x0000).
	// Fallback: some builds/files may present legacy byte-length+null. We auto-detect and switch.
	private function readNAME(int $version): string
	{
		$start = ftell($this->fp);

		// Try modern INT32-signed length first
		$len = $this->readINT32(); // may throw; if so, it’s a real EOF
		$abs = ($len < 0) ? -$len : $len;

		// Validate against remaining file bytes and a reasonable per-name cap
		$remain = $this->remainingBytes();
		// Max logical size: names are short; 8 KB is very generous
		$MAX_NAME_BYTES = 8192;

		$looksReasonable =
			($abs > 0) &&
			(
				($len > 0 && $abs <= $MAX_NAME_BYTES && $abs <= $remain) ||                // ANSI path
				($len < 0 && ($abs * 2) <= $MAX_NAME_BYTES && ($abs * 2) <= $remain)      // UTF-16LE path
			);

		if ($looksReasonable) {
			if ($len > 0) {
				// ANSI/UTF-8 with trailing 0x00
				$raw = $this->readSTRING($len);
				if ($len > 0 && substr($raw, -1) === "\x00") $raw = substr($raw, 0, -1);
				return $raw;
			} else {
				// UTF-16LE with trailing 0x0000
				$bytes = $abs * 2;
				$raw   = $this->readSTRING($bytes);
				if ($bytes >= 2 && substr($raw, -2) === "\x00\x00") $raw = substr($raw, 0, -2);
				$utf8  = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw);
				return ($utf8 !== false) ? $utf8 : $raw;
			}
		}

		// Fallback: legacy scheme (1-byte length including null, string bytes, then 0x00)
		// Rewind to before the INT32 we just read.
		fseek($this->fp, $start, SEEK_SET);

		$len1 = ord(fread($this->fp, 1) ?: "\x00");   // length including trailing null
		if ($len1 === 0) {
			// Zero-length name (rare)
			return '';
		}
		$lenStr = max(0, $len1 - 1);
		if ($lenStr > $this->remainingBytes() || $lenStr > $MAX_NAME_BYTES) {
			// Still suspicious — bail out with safest possible behavior to avoid OOM.
			// Treat as empty name, consume up to first null to resync.
			$s = '';
			while (true) {
				$c = fread($this->fp, 1);
				if ($c === false || $c === '' || $c === "\x00") break;
				if (strlen($s) >= $MAX_NAME_BYTES) break;
				$s .= $c;
			}
			return $s;
		}
		$s = ($lenStr > 0) ? $this->readSTRING($lenStr) : '';
		// consume trailing null
		$null = fread($this->fp, 1);
		return $s;
	}
	
	private function remainingBytes(): int
	{
		$pos = ftell($this->fp);
		$st  = fstat($this->fp);
		$sz  = $st['size'] ?? 0;
		return max(0, $sz - $pos);
	}

    private function readGUID(): string {
        $raw=fread($this->fp,16); if($raw===false||strlen($raw)!==16) throw new \RuntimeException("EOF GUID");
        $d1=unpack('Vval',substr($raw,0,4))['val']; $d2=unpack('vval',substr($raw,4,2))['val']; $d3=unpack('vval',substr($raw,6,2))['val'];
        $d4=strtoupper(bin2hex(substr($raw,8,2))); $d5=strtoupper(bin2hex(substr($raw,10,6)));
        return sprintf("%08X-%04X-%04X-%s-%s",$d1,$d2,$d3,$d4,$d5);
    }

    /* stream variants for property window */
    private function readSTREAM($fp,int $len): string { $s=fread($fp,$len); if($s===false||strlen($s)!==$len) throw new \RuntimeException("EOF STREAM len=$len"); return $s; }
	// Read a single byte from a temp/property stream, with bounds checks.
	// Throws only if the underlying stream itself is broken (not just end-of-window).
	private function readBYTE_stream($fp): int
	{
		// Guard: are we past the end of this stream?
		$pos = ftell($fp);
		$st  = fstat($fp);
		$sz  = (int)($st['size'] ?? 0);
		if ($pos >= $sz) {
			throw new \RuntimeException("EOF BYTE(stream)");
		}

		$s = fread($fp, 1);
		if ($s === '' || $s === false) {
			throw new \RuntimeException("EOF BYTE(stream)");
		}
		return ord($s);
	}

    private function readWORD_stream($fp): int { $s=fread($fp,2); if($s===''||strlen($s)!==2) throw new \RuntimeException("EOF WORD(stream)"); return unpack('vval',$s)['val']; }
    private function readDWORD_stream($fp): int { $s=fread($fp,4); if($s===''||strlen($s)!==4) throw new \RuntimeException("EOF DWORD(stream)"); return unpack('Vval',$s)['val']; }
	// Read a UE compact INDEX from a temp/property stream, bounded by the stream’s own size.
	



	
	// Determine property payload size from sizeCode, reading additional size fields as needed.
	// Matches UE1/UE2 property layout.
	private function sizeFromSizeCode_stream($fp, int $sizeCode): int
	{
		// Fixed sizes for common codes
		switch ($sizeCode) {
			case 0:  return 1;   // 1 byte
			case 1:  return 2;   // 2 bytes
			case 2:  return 4;   // 4 bytes
			case 3:  return 12;  // 12 bytes (e.g., Vector/Rotator legacy)
			case 4:  return 16;  // 16 bytes (e.g., Color/Plane/Quat variants in some builds)
			case 5:  // WORD following
				$lo = $this->readBYTE_stream($fp);
				$hi = $this->readBYTE_stream($fp);
				return ($hi << 8) | $lo;
			case 6:  // DWORD following (little endian)
			{
				$b0 = $this->readBYTE_stream($fp);
				$b1 = $this->readBYTE_stream($fp);
				$b2 = $this->readBYTE_stream($fp);
				$b3 = $this->readBYTE_stream($fp);
				return ($b3 << 24) | ($b2 << 16) | ($b1 << 8) | $b0;
			}
			case 7:  // compact INDEX following
				return $this->readINDEX_stream($fp);
			default:
				// Unknown size code — be defensive, treat as zero so we don't overrun window
				return 0;
		}
	}

	
	// ======= add this helper (signed 32-bit little-endian) =======
	/*
	private function readINT32(): int {
		$s = fread($this->fp, 4);
		if ($s === '' || strlen($s) !== 4) {
			throw new \RuntimeException("EOF INT32");
		}
		$u = unpack('Vval', $s)['val'];
		if ($u & 0x80000000) {
			// convert to signed
			$u = -((~$u & 0xFFFFFFFF) + 1);
		}
		return $u;
	}
	*/

	// Signed 32-bit little-endian, returns null if not enough bytes (no exception)
    private function readINT32(): int
    {
        $s = fread($this->fp, 4);
        if ($s === false || strlen($s) !== 4) {
            throw new \RuntimeException("EOF INT32");
        }
        $u = unpack('Vval', $s)['val']; // little-endian uint32
        if ($u & 0x80000000) {
            $u = -((~$u & 0xFFFFFFFF) + 1);
        }
        return $u;
    }
	
	/* ===================== fileSize (safe, handle-agnostic) ===================== */
	private ?int $fileSizeCached = null;

	private function fileSize(): int {
		if ($this->fileSizeCached !== null) return $this->fileSizeCached;

		if (is_resource($this->fp)) {
			$st = @fstat($this->fp);
			if ($st && isset($st['size'])) {
				return $this->fileSizeCached = (int)$st['size'];
			}
		}
		$fsz = @filesize($this->filePath);
		return $this->fileSizeCached = (int)($fsz === false ? 0 : $fsz);
	}


	
/* ===================== isUsableSerialWindow (bounds only) ===================== */
private function isUsableSerialWindow(int $off, int $size): bool {
    if ($off <= 0 || $size <= 0) return false;
    return ($off + $size) <= $this->fileSize();
}

/* ===================== chooseSerialWindow (prefer RAW, fallback +28) ===================== */
private function chooseSerialWindow(array $ex): array {
    $size = (int)$ex['serialSize'];
    if ($size <= 0) return [0,0,'serialSize<=0'];
    $raw = (int)($ex['serialOffsetRaw']    ?? 0);
    $p28 = (int)($ex['serialOffsetPlus28'] ?? 0);

    if ($this->isUsableSerialWindow($raw,$size)) return [$raw,$size,'raw ok'];
    if ($this->isUsableSerialWindow($p28,$size)) return [$p28,$size,'+28 ok'];

    $fsz = $this->fileSize();
    $why = 'window outside file';
    if ($raw + $size > $fsz && $p28 + $size > $fsz) $why = 'window past EOF';
    return [0,0,$why];
}






	private function remainingBytesAt(int $offset = null): int {
		$pos = ($offset === null) ? ftell($this->fp) : $offset;
		$sz  = $this->fileSize();
		return max(0, $sz - $pos);
	}

	// Try-read 32-bit signed little endian; returns null if not enough bytes
	private function tryReadINT32(): ?int {
		$s = fread($this->fp, 4);
		if ($s === false || strlen($s) !== 4) return null;
		$u = unpack('Vval', $s)['val'];
		if ($u & 0x80000000) $u = -((~$u & 0xFFFFFFFF) + 1);
		return $u;
	}
	
/*
	// Wrapper that throws (use only after we know we're in-range)
	private function readINT32(): int {
		$v = $this->tryReadINT32();
		if ($v === null) throw new \RuntimeException("EOF INT32");
		return $v;
	}
*/
}

$r = new UnrealPackageReader('test.utx');
$r->parse();
//echo "<pre>";
echo nl2br($r->dumpAllToString());

/* ===================== CLI (safe) ===================== */
/*
if (PHP_SAPI==='cli' && basename(__FILE__)===basename($_SERVER['argv'][0]??'')){
    if ($argc<2){
        // no STDERR: print to stdout
        echo "Usage: php ".basename(__FILE__)." <package-file>\n";
        exit(1);
    }
    $path=$argv[1];
    try {
        $r=new UnrealPackageReader($path);
        $r->parse();
        $r->dumpAll();
    } catch(\Throwable $e){
        // no STDERR in web; but here we are CLI, still avoid STDERR to be universal
        echo "Error: ".$e->getMessage()."\n";
        exit(1);
    }
}
*/
