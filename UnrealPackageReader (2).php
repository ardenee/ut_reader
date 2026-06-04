<?php
declare(strict_types=1);

final class UnrealPackageReader
{
    private string $path;
    private array $header      = [];
    private array $names       = [];
    private array $imports     = [];
    private array $exports     = [];
	private array $exportProps = [];  // [exportIndex => list of props]
	private array $importProps = [];  // imports don’t serialize props; keep empty lists

    private UEReader $R;
	private bool $isCompressed = false;

    // Debug
    private bool $debug = true;
    private array $debugWarnings = [];

    // Flags
	// Object Flags — EXACTLY as in the PDF
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

    private const PKG_FLAGS = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000002 => 'PKG_ClientOptional',
        0x00000004 => 'PKG_ServerSideOnly',
        0x00000008 => 'PKG_NoExportAllowed',
        0x00000010 => 'PKG_Cooked',
        0x00000020 => 'PKG_Encrypted',
    ];

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
        0x10 => 'MapProperty',
    ];

    public function __construct(string $path)
    {
        if (!is_file($path)) 
			throw new \InvalidArgumentException("File not found: $path");
		
        $this->path = $path;
        $bytes      = file_get_contents($path);
		
        if ($bytes === false) 
			throw new \RuntimeException("Failed to read file: $path");
		
        $this->R = new UEReader($bytes);
        $this->R->setDebug(true);
        $this->parseHeader();
        $this->R->setVersion($this->header['version'] ?? 0);
        $this->parseCompressionHeaderIfAny();
        $this->readNameTable();
        $this->readImportTable();
        $this->readExportTable();
		$this->parseAllExportProperties();
    }
	
	public function getExportProperties(int $i): array { return $this->exportProps[$i] ?? []; }
	public function getImportProperties(int $i): array { return $this->importProps[$i] ?? []; }
    public function getFilePath(): string { return $this->path; }
    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }
    public function getDebugWarnings(): array { return $this->debugWarnings; }	
	public function getHeaderRaw(): array {
		// filter header to only raw_* plus raw_guid_bytes, but simplest is to return $this->header and let caller use keys
		return $this->header; // contains both pretty and raw_*; caller picks raw
	}
	public function getNamesRaw(): array { return $this->names; }         // entries contain raw_* already
	public function getExportsRaw(): array { return $this->exports; }     // raw core fields
	public function getImportsRaw(): array { return $this->imports; }     // raw core fields
	public function getExportPropertiesRaw(int $i): array { return $this->exportProps[$i] ?? []; } // each entry already includes raw_* keys
    public function setDebug(bool $on): void { $this->debug = $on; $this->R->setDebug($on); }
    public function isDebug(): bool { return $this->debug; }
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
        foreach (self::PKG_FLAGS as $bit => $name) if ($flags & $bit) $out[] = $name;
        return $out;
    }
    public function propertyTypeName(int $code): string { return self::PROPERTY_TYPES[$code] ?? ('Type(0x'.strtoupper(dechex($code)).')'); }
    private function parseHeader(): void
    {
        $R              = $this->R;
        $R->seek(0);
        $signature      = $R->u32();
        $version        = $R->u16();
        $licensee       = $R->u16();
		$flags          = ($version >= 141) ? $R->u64() : $R->u32();
        $nameCount      = $R->u32();
        $nameOffset     = $R->u32();
        $exportCount    = $R->u32();
        $exportOffset   = $R->u32();
        $importCount    = $R->u32();
        $importOffset   = $R->u32();
		
		// === UE3 PATCH START: extra header fields / alignment ===
		// keep the stream aligned like later engines expect
		if ($version >= 249) {
			$this->header['raw_headerSize'] = $R->i32();
		}
		if ($version >= 269) {
			// Folder / package group name
			$decl = $R->index();
			$folderBytes = ($decl > 0) ? $R->bytes($decl) : (($decl < 0) ? $R->bytes(-$decl * 2) : '');
			$this->header['folderName']      = ($decl < 0)
				? rtrim(@iconv('UTF-16LE','UTF-8//IGNORE',$folderBytes) ?: '', "\x00")
				: rtrim($folderBytes, "\x00");
			$this->header['raw_folderDecl']  = $decl;
			$this->header['raw_folderBytes'] = $folderBytes;
		}
		if ($version >= 415) {
			$this->header['raw_dependsOffset'] = $R->i32();
		}
		if ($version >= 584) {
			// Many tools skip 16 unknown bytes; keep alignment
			$this->header['raw_unknown16'] = $R->bytes(16);
		}
		// === UE3 PATCH END: extra header fields / alignment ===

		
        $guid           = '';
        $generations    = [];
        $heritageCount  = null;
        $heritageOffset = null;

        if ($version < 68) {
            $heritageCount  = $R->u32();
            $heritageOffset = $R->u32();
            if ($heritageCount > 0 && $heritageOffset > 0) {
                $save = $R->tell();
                $R->seek($heritageOffset + ($heritageCount - 1) * 16);
                $guid = $R->guid();
                $R->seek($save);						
            }
        } else {
            $guid = $R->guid();
            $genCount = $R->u32();
            for ($i=0; $i<$genCount; $i++) {
                $e = $R->u32();
                $n = $R->u32();
                //$generations[] = ['exportCount'=>$e,'nameCount'=>$n];
				
				$this->generations[] = [
				  'nameCount' => $n,         // pretty (keep your naming)
				  'exportCount' => $e,
				  // RAW mirrors
				  'raw_nameCount' => $n,
				  'raw_exportCount' => $e,
				];
            }
        }

        $this->header = [
            'signature'      => $signature,
            'version'        => $version,
            'licensee'       => $licensee,
            'flags'          => $flags,
            'nameCount'      => $nameCount,
            'nameOffset'     => $nameOffset,
            'exportCount'    => $exportCount,
            'exportOffset'   => $exportOffset,
            'importCount'    => $importCount,
            'importOffset'   => $importOffset,
            'guid'           => $guid,
            'generations'    => $generations,
            'heritageCount'  => $heritageCount,
            'heritageOffset' => $heritageOffset,			
			  // RAW mirrors
			'raw_version'      => $version,
			'raw_licenseMode'  => $licensee,
			'raw_packageFlags' => $flags,
			'raw_nameCount'    => $nameCount,
			'raw_nameOffset'   => $nameOffset,
			'raw_exportCount'  => $exportCount,
			'raw_exportOffset' => $exportOffset,
			'raw_importCount'  => $importCount,
			'raw_importOffset' => $importOffset,
			'raw_guid_bytes'   => $guid,       // 16-byte string from file (or null if old)	
            'raw_heritageCount'  => $heritageCount,
            'raw_heritageOffset' => $heritageOffset,				  
        ];
    }

    private function parseCompressionHeaderIfAny(): void
    {
        $R = $this->R;
        $start = $R->tell();
        $version = $this->header['version'] ?? 0;
        $firstTableOff = min(array_filter([
            $this->header['nameOffset'] ?? PHP_INT_MAX,
            $this->header['importOffset'] ?? PHP_INT_MAX,
            $this->header['exportOffset'] ?? PHP_INT_MAX
        ], fn($v)=>$v>0));
        if (!$firstTableOff) { $R->seek($start); return; }

        if ($version >= 334 && ($R->tell() + 8) <= $firstTableOff) {
            $compressionFlags = $R->u32();
            $chunkCount = $R->u32();
            if ($compressionFlags !== 0 && $chunkCount > 0) {
                $need = $chunkCount * 16;
                if ($R->tell() + $need <= $firstTableOff) {
                    for ($i=0;$i<$chunkCount;$i++){
                        $R->u32(); $R->u32(); $R->u32(); $R->u32();
                    }
                }
				
				$this->debugWarnings[] = "Compressed package detected (flags=$compressionFlags, chunks=$chunkCount) — not yet supported";
				$this->isCompressed = true;
            }
        }
        $R->seek($start);
    }
	
	/** Read properties for every export whose serial block exists */
	private function parseAllExportProperties(): void
	{
		$this->exportProps = [];
		$R = $this->R;
		
		if ($this->isCompressed) {
			foreach ($this->exports as $i => $_) $this->exportProps[$i] = [];
			return;
		}

		foreach ($this->exports as $i => $ex) {
			$size   = (int)$ex['serialSize'];
			$offset = (int)$ex['serialOffset'];
			if ($size <= 0 || $offset <= 0) { $this->exportProps[$i] = []; continue; }

			// bounds guard
			$end = $offset + $size;
			if ($end > $R->length()) { $this->debugWarnings[] = "Export[$i] data truncated @{$offset}+{$size}"; $end = $R->length(); }

			$save = $R->tell();
			try {
				$R->seek($offset);
				$this->exportProps[$i] = $this->readPropertyBlock($size);
			} catch (\Throwable $e) {
				$this->exportProps[$i] = [];
				$this->debugWarnings[] = "Prop parse failed for export[$i]: ".$e->getMessage();
			} finally {
				$R->seek($save);
			}
		}
	}

	/** UE1/UE2-style property tag block: sequence of (Name,Type, [Struct], Size, ArrayIndex, Payload) ... until Name=='None' */
	private function readPropertyBlock(int $blockSize): array
	{
		if (($this->header['version'] ?? 0) > 220) {
			return $this->readPropertyBlockUE3($blockSize);
		}
		
		$R = $this->R;
		$start = $R->tell();
		$end   = $start + $blockSize;
		if ($end > $R->length()) $end = $R->length();

		$props = [];

		// helper: packed array index per PDF
		$readPackedArrayIndex = function() use ($R): int {
			$b0 = $R->u8();
			if (($b0 & 0xC0) === 0xC0) { // 4 bytes (int with MSB OR 0xC0)
				$b1 = $R->u8(); $b2 = $R->u8(); $b3 = $R->u8();
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
				$nameBytes = $R->tell() - $propStart;

				// compute how many bytes remain in this declared properties block
				$blockEnd   = min($start + $blockSize, $R->length());
				$remaining  = $blockEnd - $R->tell();
				if ($remaining < 0) $remaining = 0;

				// consume only what fits inside the block (padding/leftover)
				$pad = 0;
				if ($remaining > 0) {
					$pad = (int)$remaining;
					$R->bytes($pad);
				}

				// total row length = bytes for the name index + any in-block padding
				$totalLen = ($nameBytes + $pad);

				$props[] = [
					'offset'      => $propStart - $start,
					'length'      => $totalLen,              // e.g. 2 for 0x1E 0x00
					'name'        => 'None',
					'type'        => 'None',
					'struct'      => '',
					'isArray'     => 'No',
					'idx'         => null,
					'idxFromFile' => false,
					'value'       => '',
					// raw mirrors (handy for debugging)
					'raw_nameIndex'            => 0,
					'raw_info'                 => null,
					'raw_typeCode'             => null,
					'raw_sizeCode'             => null,
					'raw_structNameIndex'      => null,
					'raw_arrayFlag'            => false,
					'raw_idx'                  => null,
					'raw_idxFromFile'          => false,
					'raw_payload'              => '',
					'raw_boolBit'              => null,
					'raw_terminatorNameBytes'  => $nameBytes,
					'raw_terminatorPadding'    => $pad,
					'raw_offset'               => $propStart - $start,
					'raw_length'               => $totalLen,
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
				$structName = $this->nameByIndex($structNameIdx) ?? '';
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
				$this->debugWarnings[] = "Prop len clamp: {$nameStr} t=" . dechex($typeCode) . " want={$payloadLen} rem={$remaining}";
				$payloadLen = max(0, (int)$remaining);
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
						$v = ($payloadLen >= 4) ? $PR->i32() : 0;
						$valDisplay = sprintf("%d (0x%08X)", $v, ($v < 0 ? (0x100000000 + $v) : $v));
						break;
					}
					case 0x04: /* Float */ {
						$v = ($payloadLen >= 4) ? $PR->f32() : 0.0;
						$valDisplay = rtrim(sprintf('%.6f', $v), '0.');
						break;
					}
					case 0x05: /* Object */ {
						$ref = ($payloadLen > 0) ? $PR->index() : 0;
						$nm  = $this->displayNameFromRef($ref);
						$valDisplay = ($nm !== '') ? $nm : (string)$ref;
						break;
					}
					case 0x06: /* Name */ {
						$ni = ($payloadLen > 0) ? $PR->index() : 0;
						$valDisplay = $this->nameByIndex($ni) ?? (string)$ni;
						break;
					}
					case 0x07: /* StringProperty — PDF says Unknown; leave raw */ {
						$valDisplay = rtrim($payload, "\x00");
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
					case 0x0A: /* StructProperty */ {
						$sn = strtolower($structName);
						if ($sn === 'color' && $payloadLen >= 4) {
							// decode directly from bytes to avoid any endian surprises
							$r = ord($payload[0]);
							$g = ord($payload[1]);
							$b = ord($payload[2]);
							$a = ord($payload[3]);
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
						} else $valDisplay = "Vector ({$payloadLen} bytes)";
						break;
					}
					default: {
						if ($typeCode === 0x03) {
							// boolean handled earlier: no payload
							$valDisplay = $hiBit ? 'True' : 'False';
						} elseif ($typeCode === 0x00) {
							$valDisplay = '';
						} else {
							$valDisplay = "({$payloadLen} bytes)";
						}
						break;
					}
				}
			}

			$props[] = [
				// human-friendly
				'offset'      => $propStart - $start,
				'length'      => ($R->tell() - $propStart),
				'name'        => $nameStr,
				'type'        => $this->propertyTypeName($typeCode),
				'struct'      => $structName ?? '',
				'isArray'     => $isArray,
				'idx'         => $arrayIdx,                 // NULL unless stored in file
				'idxFromFile' => ($arrayIdx !== null),      // TRUE only when array flag was present
				'value'       => $valDisplay,

				// ---- RAW (exactly as stored) ----
				'raw_nameIndex'        => $nameIdx,
				'raw_info'             => $info,            // the full info byte
				'raw_typeCode'         => $typeCode,
				'raw_sizeCode'         => $sizeCode,
				'raw_structNameIndex'  => $structNameIdx,   // NULL unless StructProperty
				'raw_arrayFlag'        => $hasArrayFlag,    // TRUE if bit7 set and not boolean
				'raw_idx'              => $arrayIdx,        // same as idx; NULL means not present in file
				'raw_idxFromFile'      => ($arrayIdx !== null), // TRUE only if file stored it
				'raw_payload'          => $payload,         // the exact bytes for this value
				// For booleans, payload is empty and the value is raw_hiBit:
				'raw_boolBit'          => ($typeCode === 0x03) ? (bool)$hiBit : null,
			];

			if ($R->tell() <= $propStart) { // forward progress guard
				$this->debugWarnings[] = "Property parser stalled at ".$R->tell()." (name=$nameStr)";
				break;
			}
		}
		
		// Post-pass: if we see any element with idx>0, mark the first as array start (inferred idx=0).
		$nameToFirstIndex = [];
		
		for ($k = 0; $k < count($props); $k++) {
			$p = $props[$k];
			if (($p['name'] ?? '') === '' || $p['name'] === 'None') continue;
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
	
	private function readPropertyBlockUE3(int $blockSize): array
	{
		$R = $this->R;
		$start = $R->tell();
		$end   = min($start + $blockSize, $R->length());
		$props = [];

		while ($R->tell() < $end) {
			$propStart = $R->tell();

			// Name (Index)
			$nameIdx = $R->index();
			$nameStr = $this->nameByIndex($nameIdx) ?? '';
			
			if ($nameStr === 'None') {
				// ---- TERMINATOR ("None") ----
				// bytes consumed by the CompactIndex for the name
				$nameBytes = $R->tell() - $propStart;

				// compute remaining bytes within the declared block
				$blockEnd   = min($start + $blockSize, $R->length());
				$remaining  = $blockEnd - $R->tell();
				if ($remaining < 0) $remaining = 0;

				// consume only what fits (padding)
				$pad = 0;
				if ($remaining > 0) {
					$pad = (int)$remaining;
					$R->bytes($pad);
				}

				$totalLen = ($nameBytes + $pad);

				$props[] = [
					'offset'        => $propStart - $start,
					'length'        => $totalLen,
					'name'          => 'None',
					'type'          => 'None',
					'struct'        => '',
					'isArray'       => 'No',
					'idx'           => null,
					'idxFromFile'   => false,
					'value'         => '',
					// raw mirrors
					'raw_nameIndex'            => 0,
					'raw_typeNameIndex'        => null,
					'raw_size'                  => null,
					'raw_arrayIndex'            => null,
					'raw_structNameIndex'       => null,
					'raw_boolVal'               => null,
					'raw_payload'               => '',
					'raw_terminatorNameBytes'   => $nameBytes,
					'raw_terminatorPadding'     => $pad,
					'raw_offset'                => $propStart - $start,
					'raw_length'                => $totalLen,
				];

				break; // end of properties

			}

			// Type name (Index)
			$typeNameIdx = $R->index();
			$typeName    = $this->nameByIndex($typeNameIdx) ?? '';

			// Size + ArrayIndex (DWORDs in UE3)
			if ($R->tell()+8 > $end) break;
			$payloadLen  = $R->u32();
			$arrayIdx    = $R->u32();

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
					$guidPeekPos = $R->tell();
					$remainingToPayload = ($start+$blockSize) - $R->tell();
					if ($remainingToPayload >= ($payloadLen + 16)) {
						$this->debugWarnings[] = "UE3 struct GUID skipped for {$structName}";
						$R->bytes(16);
					} else {
						// leave as-is; some packages omit GUID
					}
				}
			}

			// BoolProperty: a single byte BoolVal comes here (header), then no payload
			$boolVal = null;
			if (strcasecmp($typeName, 'BoolProperty') === 0) {
				if ($R->tell() < $end) $boolVal = (bool)$R->u8();
				// Most UE3 bool tags have size 0; in any case, don't read payload
				$payloadLen = 0;
			}

			// Bound payload
			$remaining = $end - $R->tell();
			if ($payloadLen < 0 || $payloadLen > $remaining) {
				$this->debugWarnings[] = "UE3 prop len clamp: {$nameStr} type={$typeName} want={$payloadLen} rem={$remaining}";
				$payloadLen = max(0, min((int)$payloadLen, (int)$remaining));
			}
			$payload = ($payloadLen > 0) ? $R->bytes($payloadLen) : '';

			// Decode value (human)
			$valDisplay = '';
			$PR = new UEReader($payload); $PR->setVersion($R->getVersion());
			switch (strtolower($typeName)) {
				case 'byteproperty':
					$valDisplay = ($payloadLen >= 1) ? (string)ord($payload[0]) : '0';
					break;
				case 'intproperty':
				case 'integerproperty':
					$v = ($payloadLen >= 4) ? $PR->i32() : 0;
					$valDisplay = sprintf("%d (0x%08X)", $v, ($v<0? (0x100000000+$v):$v));
					break;
				case 'floatproperty':
					$v = ($payloadLen >= 4) ? $PR->f32() : 0.0;
					$valDisplay = rtrim(sprintf('%.6f', $v), '0.');
					break;
				case 'nameproperty': {
					$ni = ($payloadLen > 0) ? $PR->index() : 0;
					$valDisplay = $this->nameByIndex($ni) ?? (string)$ni;
					break;
				}
				case 'objectproperty': {
					$ref = ($payloadLen > 0) ? $PR->i32() : 0; // UE3 object ref usually 32-bit here
					$nm  = $this->displayNameFromRef($ref);
					$valDisplay = ($nm !== '') ? $nm : (string)$ref;
					break;
				}
				case 'strproperty':
				case 'stringproperty': {
					if ($payloadLen > 0) {
						$decl = $PR->index();
						if ($decl > 0 && ($PR->tell()+$decl)<= $payloadLen) {
							$s = $PR->bytes($decl); $valDisplay = rtrim($s, "\x00");
						} elseif ($decl < 0 && ($PR->tell()+(-$decl*2)) <= $payloadLen) {
							$bytes = $PR->bytes(-$decl*2);
							$s = @iconv('UTF-16LE','UTF-8//IGNORE',$bytes);
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
						$r=ord($payload[0]); $g=ord($payload[1]); $b=ord($payload[2]); $a=ord($payload[3]);
						$valDisplay = "Color (R={$r},G={$g},B={$b},A={$a})";
					} elseif ($sn === 'vector' && $payloadLen >= 12) {
						$x=$PR->f32(); $y=$PR->f32(); $z=$PR->f32();
						$valDisplay = sprintf("Vector (X=%.3f,Y=%.3f,Z=%.3f)", $x,$y,$z);
					} elseif ($sn === 'rotator' && $payloadLen >= 12) {
						$pitch=$PR->i32(); $yaw=$PR->i32(); $roll=$PR->i32();
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
					$valDisplay = "({$payloadLen} bytes)";
					break;
			}

			$props[] = [
				// human
				'offset'=>$propStart-$start,
				'length'=>$R->tell()-$propStart,
				'name'=>$nameStr,
				'type'=>$typeName,
				'struct'=>$structName,
				'isArray'=>($arrayIdx>0?'Yes':'No'),
				'idx'=>($arrayIdx>0?$arrayIdx:null),
				'idxFromFile'=>($arrayIdx>0),
				'value'=>$valDisplay,

				// raw mirrors
				'raw_nameIndex'=>$nameIdx,
				'raw_typeNameIndex'=>$typeNameIdx,
				'raw_size'=>$payloadLen,
				'raw_arrayIndex'=>$arrayIdx,
				'raw_structNameIndex'=>$structNameIdx,
				'raw_boolVal'=>$boolVal,
				'raw_payload'=>$payload,
				'raw_offset'=>$propStart-$start,
				'raw_length'=>$R->tell()-$propStart,
			];
		}

		// No “infer idx 0” in UE3 – arrayIndex is written explicitly there

		return $props;
	}


    private function readNameTable(): void
    {
        $R       = $this->R;
        $count   = $this->header['nameCount'];
        $offset  = $this->header['nameOffset'];
        $R->seek($offset);
        $names = [];
		
		for ($i = 0; $i < $this->header['nameCount']; $i++) {
			if ($this->header['version'] < 64) {
				// pre-64: ASCIIZ + flags (DWORD)
				$nameStr   = $this->readNAME($R, $this->header['version']); // reads ASCIIZ
				$nameBytes = $nameStr . "\x00"; // if you want to preserve raw, reconstruct or buffer-read
				$flags     = $R->u32();         // pre-141 should be 32-bit here

				$names[] = [
					'index' => $i, 'name' => $nameStr,
					'len' => strlen($nameStr), 'flags' => $flags, 'flagsDec' => $this->decodeRF($flags),
					'raw_index' => $i, 'raw_decl' => null, 'raw_name' => $nameBytes, 'raw_flags' => $flags,
				];
			} else {			
				// DECLARED length from file (CompactIndex) — raw, do not alter
				$decl = $R->index();

				$nameBytes = '';
				$nameStr   = '';
				if ($decl > 0) {
					// ANSI: read exactly $decl bytes (usually includes NUL terminator)
					$nameBytes = $R->bytes($decl);
					// pretty string: strip trailing NULs for display
					$nameStr   = rtrim($nameBytes, "\x00");
				} elseif ($decl < 0) {
					// UTF-16LE: read (-$decl) 16-bit code units
					$bytes     = $R->bytes((- $decl) * 2);
					$nameBytes = $bytes; // raw bytes as stored
					// pretty string: decode to UTF-8 and trim trailing NULs
					$nameStr   = rtrim(@iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes) ?: '', "\x00");
				} else {
					// decl == 0: empty name
					$nameBytes = '';
					$nameStr   = '';
				}

				// Name flags (DWORD)
				$flags = ($this->header['version'] ?? 0) >= 141 ? $R->u64() : $R->u32();

				// Pretty length shown in your table (‘Len’ column)
				// Use the human string length (matches your screenshots),
				// not the declared raw length which often includes terminator.
				$len = strlen($nameStr);

				$names[] = [
					// pretty (existing)
					'index'    => $i,
					'name'     => $nameStr,
					'len'      => $len,
					'flags'    => $flags,
					'flagsDec' => $this->decodeRF($flags),

					// RAW mirrors (exactly as stored)
					'raw_index' => $i,
					'raw_decl'  => $decl,        // the CompactIndex length value from file
					'raw_name'  => $nameBytes,   // raw bytes read for the name
					'raw_flags' => $flags,       // same numeric DWORD as stored
				];
			}
		}
		$this->names = $names;
    }

    private function readImportTable(): void
    {
        $R       = $this->R;
        $count   = $this->header['importCount'];
        $offset  = $this->header['importOffset'];
        $R->seek($offset);
        $imports = [];
		
        for ($i=0; $i<$count; $i++) {
            $classPackage = $R->index();
            $className    = $R->index();
            $outerIndex   = $R->i32();
            $objectName   = $R->index();
            $imports[]    = [
                'classPackage'    =>$classPackage,
                'className'       =>$className,
                'outerIndex'      =>$outerIndex,
                'objectName'      =>$objectName,
				//raw
                'raw_classPackage'=>$classPackage,
                'raw_className'   =>$className,
                'raw_outerIndex'  =>$outerIndex,
                'raw_objectName'  =>$objectName,				
            ];
        }
        $this->imports = $imports;
    }

    private function readExportTable(): void
	{
		$R      = $this->R;
		$count  = $this->header['exportCount'];
		$offset = $this->header['exportOffset'];
		$R->seek($offset);

		// pick a safe upper bound (or file end)
		$candidates = [];
		
		foreach (['nameOffset','importOffset','exportOffset'] as $k) {
			$v = $this->header[$k] ?? PHP_INT_MAX;
			
			if ($v > $offset) 
				$candidates[] = $v;
		}
		$sectionEnd = !empty($candidates) ? min($candidates) : $R->length();
		
		if ($sectionEnd > $R->length()) 
			$sectionEnd = $R->length();

		$version = $this->header['version'] ?? 0;
		
		
		if ($version >= 220) {
			$R->i32(); // skip archetype ObjectReference
		}
		
		//Components (may be UE3 only?)
		$components = [];
		
		if ($version >= 220 && $version < 543) {
			$cnt = $R->i32();
			for ($c = 0; $c < $cnt; $c++) {
				$compName = $R->index();   // name index
				$compRef  = $R->i32();     // object ref (DWORD)
				$components[] = ['nameIdx'=>$compName, 'objRef'=>$compRef];
			}
		}		
		
		$exports = [];
		
		for ($i = 0; $i < $count; $i++) {
			if ($R->tell() >= $sectionEnd) { 
				$this->debugWarnings[] = "Export table ended before entry $i"; 
				break; 
			}

			// PDF: INDEX Class, INDEX Super, DWORD Package, INDEX ObjectName, DWORD ObjectFlags, INDEX SerialSize, (INDEX SerialOffset if SerialSize>0)
			$classIndex   = $R->index();  // object ref (INDEX)
			$superIndex   = $R->index();  // object ref (INDEX)
			$packageIndex = $R->i32();    // object ref (DWORD, *not* INDEX)
			$objectName   = $R->index();  // name table index (INDEX)
			//$objectFlags  = $R->u32();    // flags (DWORD)
			$objectFlags = ($version >= 195) ? $R->u64() : $R->u32();
			$serialSize   = $R->index();  // INDEX
			$serialOffset = 0;
			
			if ($serialSize > 0 || $version >= 249) {
				$serialOffset = $R->index();
			}

			$exports[] = [
				'classIndex'       => $classIndex,
				'superIndex'       => $superIndex,
				'packageIndex'     => $packageIndex,
				'objectName'       => $objectName,
				'objectFlags'      => $objectFlags,
				'serialSize'       => $serialSize,
				'serialOffset'     => $serialOffset,
				//raw
				'raw_classIndex'   => $classIndex,
				'raw_superIndex'   => $superIndex,
				'raw_packageIndex' => $packageIndex,
				'raw_objectName'   => $objectName,
				'raw_objectFlags'  => $objectFlags,
				'raw_serialSize'   => $serialSize,
				'raw_serialOffset' => $serialOffset,
				'raw_components'   => $components,
			];
		}
		
		$this->exports = $exports;
	}

    private function readNAME(UEReader $R, int $version): string
    {
        if ($version < 64) {
            $s = '';
			
            while (true) { 
				$c = $R->u8();
				
				if ($c === 0) 
					break; 
				
				$s .= chr($c); 
			}
			
            return $s;
        }
        $len = ($version > 117) ? $R->index() : $R->u8();
		
        if ($len === 0) 
			return '';
		
        if ($len < 0) {
            $bytes = $R->bytes(-$len * 2);
            $s = @iconv('UTF-16LE', 'UTF-8//IGNORE', $bytes);
            return rtrim($s ?? '', " ");
        } else {
            $bytes = $R->bytes($len);
            return rtrim($bytes, " ");
        }
    }		
	
	/** Convenience: turn an object-ref index into a *display* name */
	public function displayNameFromRef(int $idx): string {
		$r = $this->resolveObjectRef($idx);
		return $r['name'] ?? '';
	}

	/** Convenience: walk an 'outer' chain just once for display (group/package) */
	public function displayOuterNameFromRef(int $idx): string {
		$r = $this->resolveObjectRef($idx);
		return $r['name'] ?? '';
	}

	/** Build rows for the Export table exactly like your sample */
	public function getExportDisplayRows(): array {
		$rows = [];
		
		foreach ($this->exports as $i => $ex) {
			$group  = $this->displayOuterNameFromRef($ex['packageIndex']); // “Package & Group”
			$name   = $this->nameByIndex($ex['objectName']);               // object name (from Name table)
			$classN = $this->displayNameFromRef($ex['classIndex']);        // resolve Class ref to a name
			$superN = $this->displayNameFromRef($ex['superIndex']);        // resolve Super ref to a name

			$low32 = (int)$ex['objectFlags'];
			
			$rows[] = [
				'group'             => $group ?? '',
				'name'              => $name  ?? '',
				'class'             => $classN ?: '',
				'num'               => $i,
				'super'             => $superN ?: '0',
				'serialSize'        => $ex['serialSize'],
				'serialOffset'      => $ex['serialOffset'],
				'flagsHex' => sprintf('0x%08X', $low32),
				'flagsDec' => $this->decodeRF($low32),
				// RAW mirrors (straight from the file-backed $ex array)
				'raw_classIndex'    => $ex['classIndex'],
				'raw_superIndex'    => $ex['superIndex'],
				'raw_packageIndex'  => $ex['packageIndex'],   // DWORD object ref
				'raw_objectName'    => $ex['objectName'],     // name table index
				'raw_objectFlags'   => $ex['objectFlags'],    // u32
				'raw_serialSize'    => $ex['serialSize'],
				'raw_serialOffset'  => $ex['serialOffset'],
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
			$rows[] = [
				'pkgGroup'          => $pkgGroup ?: '',
				'name'              => $name     ?: '',
				'class'             => $classN   ?: '',
				'classPkg'          => $classPkg ?: '',
				'num'               => $i,
				'raw_classPackage'  => $im['classPackage'],
				'raw_className'     => $im['className'],
				'raw_outerIndex'    => $im['outerIndex'],   // DWORD object ref (“Package” in PDF)
				'raw_objectName'    => $im['objectName'],
			];
		}
		return $rows;
	}

	/** Try to resolve an import into an external package and read its properties.
	 * Best-effort: looks for a sibling file named after the top-level package (any known Unreal extension).
	 * Returns ['status'=>'ok','path'=>..., 'exportIndex'=>int, 'props'=>array] on success, or ['status'=>'error','reason'=>...] */
	public function resolveImportProperties(int $importIndex, ?string $searchDir=null, array $exts=['.u','.utx','.uax','.umx','.ukx','.usx','.unr']): array {
		if (!isset($this->imports[$importIndex])) return ['status'=>'error','reason'=>'invalid-import-index'];
		$im = $this->imports[$importIndex];
		// Follow outer chain to find the root package name
		$outer = $im['outerIndex'] ?? 0; // DWORD object ref
		$seen = 0;
		$rootName = null;
		while ($outer < 0 && $seen < 16) {
			$seen++;
			$j = -$outer - 1;
			if (!isset($this->imports[$j])) break;
			$rootName = $this->nameByIndex($this->imports[$j]['objectName']) ?? $rootName;
			$outer = $this->imports[$j]['outerIndex'] ?? 0;
		}
		if (!$rootName) {
			$rootName = $this->nameByIndex($im['objectName']) ?? null;
		}
		if (!$rootName) return ['status'=>'error','reason'=>'no-root-name'];
		$baseDir = $searchDir ?: dirname($this->path);
		$candidates = [];
		foreach ($exts as $ext) {
			$candidates[] = $baseDir . DIRECTORY_SEPARATOR . $rootName . $ext;
		}
		$targetPath = null;
		foreach ($candidates as $cand) {
			if (@is_file($cand)) { $targetPath = $cand; break; }
		}
		if (!$targetPath) return ['status'=>'error','reason'=>'package-not-found','package'=>$rootName,'candidates'=>$candidates];
		try {
			$other = new self($targetPath);
			$other->setDebug($this->debug);
		} catch (\Throwable $e) {
			return ['status'=>'error','reason'=>'open-failed','message'=>$e->getMessage(),'path'=>$targetPath];
		}
		$wantName = $this->nameByIndex($im['objectName']) ?? null;
		if (!$wantName) return ['status'=>'error','reason'=>'no-object-name'];
		$matchIndex = null;
		foreach ($other->getExportsRaw() as $k=>$ex) {
			$nm = $other->nameByIndex($ex['objectName'] ?? null);
			if ($nm === $wantName) { $matchIndex = $k; break; }
		}
		if ($matchIndex === null) {
			return ['status'=>'error','reason'=>'export-not-found-in-external','path'=>$targetPath,'name'=>$wantName];
		}
		$props = $other->getExportProperties($matchIndex);
		return ['status'=>'ok','path'=>$targetPath,'exportIndex'=>$matchIndex,'props'=>$props];
	}
	
	/** Name table helper */
	public function nameByIndex(?int $idx): ?string {
		if ($idx === null) return null;
		return $this->names[$idx]['name'] ?? null;
	}

	/** Resolve an object reference index per PDF:
	 *  0=null; <0 => import[ -idx-1 ]; >0 => export[ idx-1 ] */
	public function resolveObjectRef(int $idx): ?array {
		if ($idx === 0) 
			return null;
		
		if ($idx < 0) {
			$i = -$idx - 1;
			
			if (!isset($this->imports[$i])) 
				return null;
			
			$im = $this->imports[$i];
			
			return [
				'kind'   => 'import',
				'index'  => $i,
				'name'   => $this->nameByIndex($im['objectName']),
				'class'  => $this->nameByIndex($im['className']),
				'pkg'    => $this->nameByIndex($im['classPackage']),
				'outer'  => $im['outerIndex'], // still an object ref (may chain)
			];
		} else {
			$e = $idx - 1;
			
			if (!isset($this->exports[$e])) 
				return null;
			
			$ex = $this->exports[$e];
			
			return [
				'kind'     => 'export',
				'index'    => $e,
				'name'     => $this->nameByIndex($ex['objectName']),
				'classRef' => $ex['classIndex'],   // raw ref if you need to chain further
				'outer'    => $ex['packageIndex'], // raw ref if you need to chain further
			];
		}
	}	
}

final class UEReader
{
    private string $buf;
    private int $pos     = 0;
    private int $len     = 0;
    private int $version = 0;
    private bool $debug  = false;

    public function __construct(string $bytes){
        $this->buf = $bytes;
        $this->len = strlen($bytes);
    }
    public function setVersion(int $v): void { $this->version = $v; }
    public function getVersion(): int        { return $this->version; }
    public function setDebug(bool $on): void { $this->debug = $on; }
    public function isDebug(): bool          { return $this->debug; }
    public function length(): int            { return $this->len; }

    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void {
        if ($pos < 0 || $pos > $this->len) 
			throw new \OutOfBoundsException("seek($pos) out of bounds");
		
        $this->pos = $pos;
    }
	
	public function f32(): float {
		$b = $this->bytes(4);
		return unpack('g', $b)[1]; // little-endian float
	}

    public function bytes(int $n): string {
        if ($this->pos + $n > $this->len) {
            $ctx = sprintf("read %d bytes past EOF @pos=%d len=%d", $n, $this->pos, $this->len);
            throw new \OutOfBoundsException($ctx);
        }
		
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;
		
        return $s;
    }

    public function u8(): int { return ord($this->bytes(1)); }
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
        if ($u & 0x80000000) return -((~$u & 0xFFFFFFFF) + 1);
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
	
	public function u64(): int {
		$b = $this->bytes(8);
		$arr = unpack('P', $b); // little-endian unsigned 64-bit (PHP 7.0+)
		return $arr[1];
	}
}
?>