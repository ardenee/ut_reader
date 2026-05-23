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
	private array $chunkCache  = []; // ensure this property exists
	/** Cached per-export property maps (filled by parse or on demand). */
	private array $exportPropertiesCache = [];
	
    private UEReader $R;
	private bool $isCompressed = false;

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
    0x00=>'EX_LocalVariable', 0x01=>'EX_InstanceVariable', 0x02=>'EX_DefaultVariable',
    0x04=>'EX_Return', 0x05=>'EX_Switch', 0x06=>'EX_Jump', 0x07=>'EX_JumpIfNot',
    0x08=>'EX_Stop', 0x09=>'EX_Assert', 0x0A=>'EX_Case', 0x0B=>'EX_Nothing',
    0x0C=>'EX_LabelTable', 0x0D=>'EX_GotoLabel', 0x0E=>'EX_EatString',
    0x0F=>'EX_Let', 0x10=>'EX_DynArrayElement', 0x11=>'EX_New',
    0x12=>'EX_ClassContext', 0x13=>'EX_MetaCast', 0x14=>'EX_LetBool',
    0x16=>'EX_EndFunctionParms', 0x17=>'EX_Self', 0x18=>'EX_Skip',
    0x19=>'EX_Context', 0x1A=>'EX_ArrayElement', 0x1B=>'EX_VirtualFunction',
    0x1C=>'EX_FinalFunction', 0x1D=>'EX_IntConst', 0x1E=>'EX_FloatConst',
    0x1F=>'EX_StringConst', 0x20=>'EX_ObjectConst', 0x21=>'EX_NameConst',
    0x22=>'EX_RotationConst', 0x23=>'EX_VectorConst', 0x24=>'EX_ByteConst',
    0x25=>'EX_IntZero', 0x26=>'EX_IntOne', 0x27=>'EX_True', 0x28=>'EX_False',
    0x29=>'EX_NativeParm', 0x2A=>'EX_NoObject', 0x2C=>'EX_IntConstByte',
    0x2D=>'EX_BoolVariable', 0x2E=>'EX_DynamicCast', 0x2F=>'EX_Iterator',
    0x30=>'EX_IteratorPop', 0x31=>'EX_IteratorNext',
    0x32=>'EX_StructCmpEq', 0x33=>'EX_StructCmpNe',
    0x34=>'EX_UnicodeStringConst', 0x36=>'EX_StructMember',
    0x38=>'EX_GlobalFunction', 0x39=>'EX_RotatorToVector',
    // ... you can extend as needed
    0x60=>'EX_ExtendedNative', 0x70=>'EX_FirstNative'
];

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

/** Return all parsed properties for an export as an associative array. */
function getExportProperties(int $exportIndex): ?array {
    // Prefer the pre-scanned table populated in __construct()
    if (isset($this->exportProps[$exportIndex])) {
        return $this->exportProps[$exportIndex];
    }
    // Fallback: lazily parse the bounded body (keeps other functionality)
    if (!isset($this->exportPropertiesCache[$exportIndex])) {
        $this->exportPropertiesCache[$exportIndex] = $this->readPropertiesForExport($exportIndex);
    }
    return $this->exportPropertiesCache[$exportIndex] ?: null;
}


/** Convenience: get a single property by name (null if missing). */
public function getExportProperty(int $exportIndex, string $name, $default = null) {
    $props = $this->getExportProperties($exportIndex) ?? [];
    return array_key_exists($name, $props) ? $props[$name] : $default;
}

/** Convenience: properties for all exports of a given class (e.g., 'Shader'). */
public function getPropertiesByClass(string $className): array {
    $out = [];
    foreach ($this->exports ?? [] as $i => $_) {
        if ($this->exportClassName($i) === $className) {
            $out[$i] = $this->getExportProperties($i);
        }
    }
    return $out;
}

/**
 * Parse just the serialized properties for a single export (bounded).
 * Returns ['PropName' => mixed, ...]. Unknown types are returned as ['raw'=>hex,'len'=>N,'type'=>code].
 */
public function readPropertiesForExport(int $exportIndex): array {
    $R = $this->exportBodyReader($exportIndex);
    if (!$R) return [];

    $props = [];
    // read until Name == 'None'
    while ($R->remaining() > 0) {
        $nameIdx = $R->index();
        $nameStr = $this->nameByIndex($nameIdx) ?? '';
        if ($nameStr === 'None') break;

        $info     = $R->u8();
        $typeCode = $info & 0x0F;
        $sizeCode = ($info >> 4) & 0x07;
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
                $R->u16(); $R->u8(); // 4 bytes total
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
					$ix  = $R->index();
					$val = $this->displayNameFromRef($ix) ?? $ix;
					$left = $payloadLen - 0; // index() consumes varint; we don't know exact byte count here
					if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
				} else { $val = null; }
				break;

			case 0x06: // NameProperty (index)
				if ($payloadLen > 0 && $R->remaining() > 0) {
					$ix  = $R->index();
					$val = $this->nameByIndex($ix);
					$left = $payloadLen - 0;
					if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
				} else { $val = null; }
				break;

			case 0x07: // StringProperty (length already reflected by sizeCode/payloadLen)
			case 0x0D: // StrProperty (older/alt spelling)
				if ($payloadLen > 0 && $R->remaining() >= $payloadLen) {
					$bytes = $R->bytes($payloadLen);
					$val   = rtrim($bytes, "\x00");
				} else { $val = ''; }
				break;

			case 0x08: // ClassProperty (index to class object) – treat like ObjectProperty
				if ($payloadLen > 0 && $R->remaining() > 0) {
					$ix  = $R->index();
					$val = $this->displayNameFromRef($ix) ?? $ix;
					$left = $payloadLen - 0;
					if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
				} else { $val = null; }
				break;

			case 0x09: // ArrayProperty – inner layout varies; keep raw bytes safely
				$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen)
					? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Array']
					: ['raw' => '', 'len' => 0, 'type' => 'Array'];
				break;

			case 0x0A: // StructProperty – you already read $structName before size
				if ($payloadLen > 0 && $R->remaining() >= $payloadLen) {
					if (strcasecmp($structName, 'Vector') === 0 && $payloadLen >= 12) {
						$val = ['x' => $R->f32(), 'y' => $R->f32(), 'z' => $R->f32()];
						$left = $payloadLen - 12; if ($left > 0) $R->skip($left);
					} else if (strcasecmp($structName, 'Rotator') === 0 && $payloadLen >= 12) {
						$val = ['pitch' => $R->i32(), 'yaw' => $R->i32(), 'roll' => $R->i32()];
						$left = $payloadLen - 12; if ($left > 0) $R->skip($left);
					} else {
						$val = ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'struct' => $structName];
					}
				} else { $val = null; }
				break;

			case 0x0B: // VectorProperty – some packages serialize as Struct(Vector)
				if ($payloadLen >= 12 && $R->remaining() >= 12) {
					$val = ['x' => $R->f32(), 'y' => $R->f32(), 'z' => $R->f32()];
					$left = $payloadLen - 12; if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
				} else {
					$val = ($payloadLen > 0) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Vector'] : null;
				}
				break;

			case 0x0C: // RotatorProperty – some packages serialize as Struct(Rotator)
				if ($payloadLen >= 12 && $R->remaining() >= 12) {
					$val = ['pitch' => $R->i32(), 'yaw' => $R->i32(), 'roll' => $R->i32()];
					$left = $payloadLen - 12; if ($left > 0 && $R->remaining() >= $left) $R->skip($left);
				} else {
					$val = ($payloadLen > 0) ? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Rotator'] : null;
				}
				break;

			case 0x0E: // MapProperty – opaque
				$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen)
					? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'Map']
					: ['raw' => '', 'len' => 0, 'type' => 'Map'];
				break;

			case 0x0F: // FixedArrayProperty – opaque without element schema
				$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen)
					? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => 'FixedArray']
					: ['raw' => '', 'len' => 0, 'type' => 'FixedArray'];
				break;

			default: // Unknown/unsupported
				$val = ($payloadLen > 0 && $R->remaining() >= $payloadLen)
					? ['raw' => bin2hex($R->bytes($payloadLen)), 'len' => $payloadLen, 'type' => $typeCode]
					: null;
				break;
		}

        $props[$nameStr] = $val;
    }

    return $props;
}


/** Disassemble tokens into readable lines up to an optional byte limit. */
private function disasmScript(UEReader $R, int $limit = 4096): array {
    $base  = $R->tell();
    $lines = [];
    $used  = 0;

    $readIndex = function() use ($R) { return $R->index(); };

    while ($R->remaining() > 0 && $used < $limit) {
        $pc  = $R->tell() - $base;
        $opb = ord($R->bytes(1)); $used++;
        $mn  = self::OP[$opb] ?? sprintf('OP_%02X', $opb);

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
                    $off = $R->u16(); $used += 2;
                    $line .= " off={$off}";
                }
                break;

            case 0x05: // Switch (BYTE size + token)
                if ($R->remaining() >= 1) {
                    $sz = $R->u8(); $used += 1;
                    $line .= " size={$sz}";
                }
                // fallthrough — we won’t fully parse nested tokens here
                break;

            case 0x09: // Assert (WORD line + TOKEN)
                if ($R->remaining() >= 2) {
                    $ln = $R->u16(); $used += 2;
                    $line .= " line={$ln}";
                }
                break;

            case 0x1D: // IntConst (DWORD)
                if ($R->remaining() >= 4) {
                    $v = $R->u32(); $used += 4;
                    $line .= " {$v}";
                }
                break;

            case 0x1E: // FloatConst
                if ($R->remaining() >= 4) {
                    $v = $R->f32(); $used += 4;
                    $line .= " {$v}";
                }
                break;

            case 0x1F: // StringConst (ASCIIZ)
                // Minimal: read until 0x00 within sane bound
                $s = '';
                $guard = min(512, $R->remaining());
                for ($i=0; $i<$guard; $i++) {
                    $b = $R->bytes(1); $used++;
                    if ($b === "\x00") break;
                    $s .= $b;
                }
                $line .= " \"".addslashes($s)."\"";
                break;

            case 0x20: // ObjectConst (INDEX object ref)
                if ($R->remaining() >= 1) {
                    $ix = $readIndex(); // size varies; `index()` manages it
                    $line .= " ".($this->displayNameFromRef($ix) ?? "ref{$ix}");
                    // used bytes already tracked by reader
                }
                break;

            case 0x21: // NameConst (INDEX)
                if ($R->remaining() >= 1) {
                    $ix = $readIndex();
                    $line .= " '".($this->nameByIndex($ix) ?? "name{$ix}")."'";
                }
                break;

            case 0x22: // RotationConst (3 * DWORD)
                if ($R->remaining() >= 12) {
                    $p=$R->u32(); $y=$R->u32(); $r=$R->u32(); $used+=12;
                    $line .= " pitch={$p} yaw={$y} roll={$r}";
                }
                break;

            case 0x23: // VectorConst (3 * FLOAT)
                if ($R->remaining() >= 12) {
                    $x=$R->f32(); $y=$R->f32(); $z=$R->f32(); $used+=12;
                    $line .= " X={$x} Y={$y} Z={$z}";
                }
                break;

            case 0x1B: // VirtualFunction (INDEX name) (params until EndFunctionParms)
            case 0x1C: // FinalFunction (INDEX object)
            case 0x38: // GlobalFunction (INDEX name)
                if ($R->remaining() >= 1) {
                    $ix = $readIndex();
                    $nm = ($opb === 0x1C) ? ($this->displayNameFromRef($ix) ?? "ref{$ix}") : ($this->nameByIndex($ix) ?? "name{$ix}");
                    $line .= " ".$nm."(";
                    // Skip params until EndFunctionParms (0x16)
                    $parenDepth = 1;
                    $guard2 = 0;
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
                    $b2 = ord($R->bytes(1)); $used++;
                    $native = (($opb - 0x60) << 8) + $b2;
                    $line .= " native={$native}";
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
        $this->readCompressionHeaderIfAny();
        $this->readNameTable();
        $this->readImportTable();
        $this->readExportTable();
		$this->readAllExportProperties();
    }
	
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
	
	function matLabel($v, $nameByIndex, $displayNameFromRef) {
	  if (is_int($v)) 
		  return $nameByIndex($v) ?? (string)$v;
	  
	  if (is_array($v) && isset($v['object'])) 
		  return $displayNameFromRef($v['object']);
	  
	  return is_scalar($v) ? (string)$v : json_encode($v);
	}
	
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

	
	private function formatGuid(string $raw16): string
	{
		if (strlen($raw16) !== 16) 
			return '';
		
		$b = array_values(unpack('C16', $raw16));
		// Data1 (4 bytes LE), Data2 (2 LE), Data3 (2 LE), Data4 (8 BE-ish)
		$d1 = sprintf('%02x%02x%02x%02x', $b[4], $b[3], $b[2], $b[1]);
		$d2 = sprintf('%02x%02x',       $b[6], $b[5]);
		$d3 = sprintf('%02x%02x',       $b[8], $b[7]);
		$d4 = sprintf('%02x%02x',       $b[9], $b[10]);
		$d5 = sprintf('%02x%02x%02x%02x%02x%02x', $b[11], $b[12], $b[13], $b[14], $b[15], $b[16]);
		
		return strtoupper("{$d1}-{$d2}-{$d3}-{$d4}-{$d5}");
	}

    public function propertyTypeName(int $code): string { 
		return self::PROPERTY_TYPES[$code] ?? ('Type(0x'.strtoupper(dechex($code)).')'); 
	}
	
    private function readHeader(): void
	{
		$R         = $this->R;
		$R->seek(0);
		$signature = $R->u32();
		
		if ($signature !== 0x9E2A83C1) {
			throw new \RuntimeException("Bad signature 0x".dechex($signature));
		}

		// Versions
		$version  = $R->u16();
		$licensee = $R->u16();
		$fileLen  = $R->length();

		// (Optional, guarded) headerSize (>=249)
		if ($version >= 249 && ($R->tell() + 4) <= $fileLen) {
			$save    = $R->tell();
			$hdrSize = $R->u32();
			
			if (!($hdrSize >= 0x20 && $hdrSize <= $fileLen)) 
				$R->seek($save);
			
			else $this->header['headerSize'] = $hdrSize;
		}

		// (Optional, guarded) folder name as FString (>=269)
		if ($version >= 269 && ($R->tell() + 4) <= $fileLen) {
			$save = $R->tell();
			$len  = $R->i32();
			$ok   = false;
			
			if ($len === 0) { 
				$this->header['folderName'] = ''; 
				$ok = true; 
			}
			elseif ($len > 0 && ($R->tell() + $len) <= $fileLen && $len <= 8192) {
				$this->header['folderName'] = rtrim($R->bytes($len), "\x00"); 
				$ok = true;
			} elseif ($len < 0) {
				$need = (-$len) * 2;
				
				if (($R->tell() + $need) <= $fileLen && $need <= 16384) {
					$this->header['folderName'] = rtrim(@iconv('UTF-16LE','UTF-8//IGNORE',$R->bytes($need)) ?: '', "\x00");
					$ok = true;
				}
			}
			
			if (!$ok) 
				$R->seek($save);
		}

		// Package flags at this point are 32-bit for all eras
		$pkgFlags = $R->u32();

		// Required tables
		$nameCount    = $R->u32();
		$nameOffset   = $R->u32();
		$exportCount  = $R->u32();
		$exportOffset = $R->u32();
		$importCount  = $R->u32();
		$importOffset = $R->u32();

		// (Optional) dependsPos (>=415)
		if ($version >= 415 && ($R->tell() + 4) <= $fileLen) {
			$this->header['dependsPos'] = $R->u32();
		}

		// (Optional) 16-byte pad (>=584)
		if ($version >= 584 && ($R->tell() + 16) <= $fileLen) {
			$R->bytes(16);
		}

		// ===== Heritage / GUID / Generations (PDF + your old code) =====
		$guid           = null;
		$generations    = [];
		$heritageCount  = null;
		$heritageOffset = null;

		if ($version < 68) {
			// UE1: heritageCount + heritageOffset, then GUID(s) at heritageOffset (keep last seen)
			$heritageCount  = $R->u32();
			$heritageOffset = $R->u32();
			$save           = $R->tell();
			
			if ($heritageCount > 0 && $heritageOffset > 0 && $heritageOffset + 16 <= $fileLen) {
				$R->seek($heritageOffset);
				for ($i = 0; $i < $heritageCount; $i++) {
					$guid = $R->guid(); // your existing UEReader->guid() – correct formatting
				}
			}
			
			$R->seek($save);
		} else {
			// UE2/UE3: GUID immediately, then generations
			if (($R->tell() + 16) <= $fileLen) {
				$guid = $R->guid(); // use your UEReader->guid(); do NOT reformat here
			}
			if (($R->tell() + 4) <= $fileLen) {
				$genCount = $R->u32();
				// PDF + your old code: store pairs (exports, names); (UE3 sometimes has netObjectsCount, handled elsewhere if you need)
				for ($i = 0; $i < $genCount; $i++) {
					if (($R->tell() + 8) > $fileLen) 
						break;
					
					$e             = $R->u32();
					$n             = $R->u32();
					$generations[] = ['exportCount' => $e, 'nameCount' => $n];
				}
			}
		}
		// ==============================================================

		// (Optional) engine / cooker (guarded)
		if ($version >= 245 && ($R->tell() + 4) <= $fileLen) 
			$R->u32();
		
		if ($version >= 277 && ($R->tell() + 4) <= $fileLen) 
			$R->u32();

		// UE3 compression header (>=334), strictly guarded
		$this->isCompressed     = false;
		$this->compressionFlags = 0;
		$this->chunks           = [];
		
		if ($version >= 334 && ($R->tell() + 8) <= $fileLen) {
			$save   = $R->tell();
			$cFlags = $R->u32();
			$cCount = $R->u32();
			$need   = $cCount * 16;
			
			if ($cFlags != 0 && $cCount > 0 && $cCount < 100000 && ($R->tell() + $need) <= $fileLen) {
				$chunks = [];
				
				for ($i = 0; $i < $cCount; $i++) {
					$uOff     = $R->u32(); 
					$uLen     = $R->u32();
					$cOff     = $R->u32(); 
					$cLen     = $R->u32();
					$chunks[] = ['uOff'=>$uOff, 'uLen'=>$uLen, 'cOff'=>$cOff, 'cLen'=>$cLen];
				}
				
				$this->isCompressed     = true;
				$this->compressionFlags = $cFlags;
				$this->chunks           = $chunks;
			} else {
				$R->seek($save);
			}
		}

		// Validate table offsets against the right space
		if ($this->isCompressed && !empty($this->chunks)) {
			$last      = $this->chunks[count($this->chunks)-1];
			$uncompEnd = (int)($last['uOff'] + $last['uLen']);
			
			foreach (['nameOffset'=>$nameOffset, 'importOffset'=>$importOffset, 'exportOffset'=>$exportOffset] as $label => $off) {
				if ($off < 0 || $off > $uncompEnd) {
					throw new \RuntimeException("Header says $label=$off, outside uncompressed size (end=$uncompEnd)");
				}
			}
		} else {
			foreach (['nameOffset'=>$nameOffset, 'importOffset'=>$importOffset, 'exportOffset'=>$exportOffset] as $label => $off) {
				if ($off < 0 || $off > $fileLen) {
					throw new \RuntimeException("Header says $label=$off, outside file (len=$fileLen)");
				}
			}
		}

		// Commit
		$this->header = [
			'signature'       => $signature,
			'version'         => $version,
			'licensee'        => $licensee,
			'pkgFlags'        => $pkgFlags,
			'nameCount'       => $nameCount,
			'nameOffset'      => $nameOffset,
			'exportCount'     => $exportCount,
			'exportOffset'    => $exportOffset,
			'importCount'     => $importCount,
			'importOffset'    => $importOffset,
			'guid'            => $guid,              // from $R->guid()
			'generations'     => $generations,       // only for v>=68
			'heritageCount'   => $heritageCount,     // only for v<68
			'heritageOffset'  => $heritageOffset,    // only for v<68
		];
	}

	private function totalUncompressedSize(): int {
		if (!$this->isCompressed || empty($this->chunks)) 
			return $this->R->length();
		
		$last = $this->chunks[count($this->chunks)-1];
		
		return (int)($last['uOff'] + $last['uLen']);
	}

	private function readUncompressedRange(int $start, int $length): string {
		if (!$this->isCompressed) {
			$cur   = $this->R->tell();
			$this->R->seek($start);
			$bytes = $this->R->bytes($length);
			$this->R->seek($cur);
			
			return $bytes;
		}
		
		if ($length <= 0) 
			return '';
		
		$end = $start + $length; $out = '';
		
		foreach ($this->chunks as $i => $c) {
			$cStart = (int)$c['uOff']; $cEnd = (int)($c['uOff'] + $c['uLen']);
			
			if ($end <= $cStart || $start >= $cEnd) 
				continue;
			
			$this->ensureChunkLoaded($i);
			$relStart = max($start, $cStart); $relEnd = min($end, $cEnd);
			$out .= substr($this->chunkCache[$i], $relStart - $cStart, $relEnd - $relStart);
		}
		
		return (strlen($out) > $length) ? substr($out, 0, $length) : $out;
	}
	
	
	
	private function ensureChunkLoaded(int $chunkIndex): void
	{
		if (isset($this->chunkCache[$chunkIndex])) 
			return;
		
		if (empty($this->chunks[$chunkIndex])) {
			throw new \RuntimeException("Chunk $chunkIndex not present");
		}

		$c    = $this->chunks[$chunkIndex];
		$cOff = (int)$c['cOff'];
		$cLen = (int)$c['cLen'];
		$uLen = (int)$c['uLen'];

		// Bound read of this chunk’s compressed data
		$this->R->seek($cOff);
		$compBuf    = $this->R->bytes($cLen);
		$r          = new UEReader($compBuf);
		$out        = '';
		$usedHeader = false;

		// ---- Try headered layout: [sig][blockSize][compTotal][uncompTotal] + N*(comp,uncomp) + blocks ----
		if ($r->length() >= 16) {
			$sig     = $r->u32();          // not strictly checked
			$blkSize = $r->u32();          // typically 131072
			$compTot = $r->u32();          // total compressed bytes for this chunk
			$uncTot  = $r->u32();          // total uncompressed bytes for this chunk

			if ($blkSize > 0 && $blkSize <= 4_000_000 && $uncTot > 0) {
				$num        = (int)ceil($uncTot / $blkSize);
				$pairsBytes = $num * 8;

				// Validate pairs table fits inside cLen and compTot also fits
				if (16 + $pairsBytes <= $r->length() && $compTot >= 0 && (16 + $pairsBytes + $compTot) <= $r->length()) {
					// Read size pairs
					$pairs = [];
					
					for ($i = 0; $i < $num; $i++) {
						$cs = $r->u32(); $us = $r->u32();
						
						if ($cs < 0 || $us < 0) { 
							$pairs = []; 
							break; 
						}
						
						$pairs[] = [$cs, $us];
					}

					if (!empty($pairs)) {
						$compStart = $r->tell();
						$sumComp   = 0; 
						
						foreach ($pairs as $p) 
							$sumComp += $p[0];

						// If sumComp doesn’t equal compTot (some builds are sloppy), trust smaller bound
						$compBound = min($compTot, $sumComp);
						
						if ($compBound <= 0) 
							$compBound = $sumComp;

						if ($compStart + $compBound <= $r->length()) {
							$consumed = 0;
							
							foreach ($pairs as [$cs, $us]) {
								if ($consumed + $cs > $compBound) 
									break; // stop cleanly
								
								$blk       = substr($compBuf, $compStart + $consumed, $cs);
								$consumed += $cs;

								if (function_exists('lzo1x_decompress')) {
									$raw = lzo1x_decompress($blk, $us);
									
									if ($raw === false || ($us > 0 && strlen($raw) !== $us)) {
										throw new \RuntimeException("LZO1X failed/size mismatch");
									}
								} else {
									$raw = @gzuncompress($blk);
									
									if ($raw === false || ($us > 0 && strlen($raw) !== $us)) {
										throw new \RuntimeException("No LZO1X available");
									}
								}
								$out .= $raw;
								
								if (strlen($out) >= $uLen) 
									break;
							}
							
							$usedHeader = true;
						}
					}
				}
			}
		}

		// ---- Tolerant fallback: pairs-first layout (no header) ----
		if (!$usedHeader) {
			$r->seek(0);
			$sumUnc = 0;
			$pairs  = [];

			// Collect pairs table until we reach or exceed uLen or run out of pair entries
			while ($r->remaining() >= 8 && $sumUnc < $uLen) {
				$cs = $r->u32(); 
				$us = $r->u32();
				
				if ($cs <= 0 || $us < 0) 
					break;
				
				$pairs[] = [$cs, $us];
				$sumUnc += $us;
			}

			// Decompress following blocks; stop cleanly if truncated
			foreach ($pairs as [$cs, $us]) {
				if ($cs > $r->remaining()) 
					break; // truncated, stop gracefully
				
				$blk = $r->bytes($cs);

				if (function_exists('lzo1x_decompress')) {
					$raw = lzo1x_decompress($blk, $us);
					
					if ($raw === false) 
						break;
					
					if ($us > 0 && strlen($raw) !== $us) 
						break;
				} else {
					$raw = @gzuncompress($blk);
					
					if ($raw === false) 
						break;
					
					if ($us > 0 && strlen($raw) !== $us) 
						break;
				}
				
				$out .= $raw;
				
				if (strlen($out) >= $uLen) 
					break;
			}
		}

		// Enforce advertised uncompressed size for this chunk
		if (strlen($out) > $uLen) 
			$out = substr($out, 0, $uLen);		
		elseif (strlen($out) < $uLen) 
			$out = str_pad($out, $uLen, "\x00");

		$this->chunkCache[$chunkIndex] = $out;
	}

	// New: build a reader over the FULL uncompressed stream
	private function makeFullUncompressedReader(): UEReader
	{
		$total = $this->totalUncompressedSize();
		$buf   = $this->readUncompressedRange(0, $total);
		$r     = new UEReader($buf);
		
		if (method_exists($r, 'setVersion')) 
			$r->setVersion((int)($this->header['version'] ?? 0));
		
		return $r;
	}

	private function makeUncompressedReader(int $offset, int $limitBytes): UEReader {
		$total = $this->totalUncompressedSize();
		$limit = max(0, min($limitBytes, $total - $offset));
		$bytes = $this->readUncompressedRange($offset, $limit);
		$sub   = new UEReader($bytes);
		$sub->setVersion($this->header['version'] ?? 0);
		
		return $sub;
	}
	
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
				$nameBytes = $R->tell() - $propStart;

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
						if ($typeCode === 0x03) { // Bool handled earlier
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
		$save = $R->tell();
		$tailStart = max($e['serialOffset'], $e['serialOffset'] + $e['serialSize'] - 10);
		$R->seek($tailStart);
		
		if ($R->remaining() >= 8) {
			$summary['width']  = $R->u32();
			$summary['height'] = $R->u32();
		}
		
		$R->seek($save);

		return $summary;
	}

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
	
	/** Peek the body of a UFunction export (after properties). */
	public function peekFunction(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$ver = (int)($this->header['version'] ?? 0);
		$out = [];

		// Function (inherits Struct)
		if ($ver <= 63) {
			if ($R->remaining() < 2) return $out;
			$out['ParmsSize'] = $R->index(); // historically INDEX; many builds use WORD/INDEX
		}

		if ($R->remaining() < 2) return $out;
		$out['iNative'] = $R->u16(); // WORD

		if ($ver <= 63) {
			if ($R->remaining() < 4) return $out;
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

	/** Peek the body of a UState export (after properties). */
	public function peekState(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
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
		if ($R->remaining() >= 2) { $out['LabelTableOffset'] = $R->u16(); }
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

	/** Peek the body of a UClass export (the meta 'class' object), after properties. */
	public function peekClass(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
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
			$depCount = $R->index();
			$out['Dependencies'] = $depCount;
			// Each dependency: INDEX ClassRef; DWORD Deep; DWORD ScriptTextCRC
			$deps = [];
			for ($i=0; $i<$depCount && $R->remaining() >= 12; $i++) {
				$cref = $R->index();
				$deep = $R->u32();
				$crc  = $R->u32();
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
	
	/** Create a bounded reader view for an export body. */
	private function exportBodyReader(int $exportIndex): ?UEReader {
		$e = $this->exports[$exportIndex] ?? null;
		if (!$e) return null;
		$ofs = (int)($e['serialOffset'] ?? 0);
		$siz = (int)($e['serialSize']  ?? 0);
		if ($siz <= 0) return null;

		$R = clone $this->R;
		$R->seek($ofs);
		// Make a shallow wrapper that refuses to read past ofs+siz
		$R->setBounds($ofs, $ofs + $siz); // implement setBounds in UEReader if not present
		return $R;
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
			if ($typeCode === 0x0A) { $R->index(); }

			$payloadLen = match ($sizeCode) {
				0 => 1, 1 => 2, 2 => 4, 3 => 12, 4 => 16,
				5 => $R->u8(),
				6 => $R->u16(),
				7 => $R->u32(),
			};

			if ($isArrayIx) {
				// packed array index (1/2/4 bytes with tag in MS bits)
				$b = $R->u8();
				if (($b & 0x80) === 0) {
					// 1 byte only
				} elseif (($b & 0xC0) === 0x80) {
					// 2 bytes total (we already read first), read the remaining one
					$R->u8();
				} else {
					// 4 bytes total (we already read first), read remaining two
					$R->u16(); $R->u8();
				}
			}

			if ($typeCode !== 0x03 && $payloadLen > 0) {
				$R->skip($payloadLen);
			}
		}
	}

	/** Decode flags helpers (use the maps you already added). */
	private function namesFromFlags(int $v, array $map): array {
		$out = [];
		foreach ($map as $bit => $name) if (($v & $bit) === $bit) $out[] = $name;
		return $out;
	}
	
	public function readMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = ['Vertices'=>[], 'Triangles'=>[], 'Scale'=>null, 'Origin'=>null];

		if ($R->remaining() < 4) return $out;
		$vertCount = $R->index();
		for ($i=0; $i<$vertCount && $R->remaining() >= 12; $i++) {
			$x = $R->f32(); $y = $R->f32(); $z = $R->f32();
			$out['Vertices'][] = [$x,$y,$z];
		}

		if ($R->remaining() >= 4) {
			$triCount = $R->index();
			for ($i=0; $i<$triCount && $R->remaining() >= 6; $i++) {
				$v0 = $R->u16(); $v1 = $R->u16(); $v2 = $R->u16();
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
	
	public function readLodMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = ['LODs'=>[], 'BoundingBox'=>null, 'BoundingSphere'=>null];

		// BoundingBox
		if ($R->remaining() >= 25) {
			$min = [$R->f32(), $R->f32(), $R->f32()];
			$max = [$R->f32(), $R->f32(), $R->f32()];
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
			$verts = [];
			for ($i=0; $i<min($vertCount, 10) && $R->remaining() >= 12; $i++) {
				$verts[] = [$R->f32(), $R->f32(), $R->f32()];
			}
			$out['VertexPreview'] = $verts;
		}

		// Material list (optional)
		if ($R->remaining() >= 4) {
			$matCount = $R->index();
			$mats = [];
			for ($i=0; $i<$matCount && $R->remaining() >= 4; $i++) {
				$ix = $R->index();
				$mats[] = $this->displayNameFromRef($ix);
			}
			$out['Materials'] = $mats;
		}

		return $out;
	}
	
	public function readSkeletalMesh(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = ['Bones'=>[], 'Vertices'=>[], 'Weights'=>[], 'BoundingBox'=>null];

		// BoundingBox first
		if ($R->remaining() >= 25) {
			$min = [$R->f32(), $R->f32(), $R->f32()];
			$max = [$R->f32(), $R->f32(), $R->f32()];
			$valid = (bool)$R->u8();
			$out['BoundingBox'] = ['Min'=>$min, 'Max'=>$max, 'Valid'=>$valid];
		}

		// Bones
		if ($R->remaining() >= 4) {
			$boneCount = $R->index();
			$bones = [];
			for ($i=0; $i<$boneCount && $R->remaining() >= 40; $i++) {
				$nameIdx = $R->index();
				$parent  = $R->i32();
				$pos     = [$R->f32(), $R->f32(), $R->f32()];
				$rot     = [$R->f32(), $R->f32(), $R->f32(), $R->f32()];
				$scale   = [$R->f32(), $R->f32(), $R->f32()];
				$bones[] = [
					'Name'=>$this->nameByIndex($nameIdx),
					'Parent'=>$parent,
					'Pos'=>$pos,
					'Rot'=>$rot,
					'Scale'=>$scale,
				];
			}
			$out['Bones'] = $bones;
		}

		// Preview first few vertex weights (variable layout)
		if ($R->remaining() >= 4) {
			$vcount = $R->index();
			$out['VertexCount'] = $vcount;
			for ($i=0; $i<min($vcount, 5) && $R->remaining() >= 16; $i++) {
				$x=$R->f32(); $y=$R->f32(); $z=$R->f32();
				$boneIdx=$R->u8(); $weight=$R->f32();
				$out['Vertices'][]=['Pos'=>[$x,$y,$z],'Bone'=>$boneIdx,'Weight'=>$weight];
			}
		}

		return $out;
	}
	
	public function readAnimation(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		$out = ['Sequences'=>[]];

		if ($R->remaining() < 4) return $out;
		$seqCount = $R->index();
		for ($i=0; $i<$seqCount && $R->remaining() >= 16; $i++) {
			$nameIdx = $R->index();
			$groupIdx = $R->index();
			$startFrame = $R->u16();
			$numFrames  = $R->u16();
			$rate       = $R->f32();
			$out['Sequences'][] = [
				'Name'=>$this->nameByIndex($nameIdx),
				'Group'=>$this->nameByIndex($groupIdx),
				'Start'=>$startFrame,
				'Frames'=>$numFrames,
				'Rate'=>$rate,
			];
		}
		return $out;
	}
	/*
	switch ($pkg->exportClassName($i)) {
		case 'Mesh':          $info = $pkg->readMesh($i); break;
		case 'LodMesh':       $info = $pkg->readLodMesh($i); break;
		case 'SkeletalMesh':  $info = $pkg->readSkeletalMesh($i); break;
		case 'Animation':     $info = $pkg->readAnimation($i); break;
	}
	*/
	
/**
 * Compute vertex and wedge normals for a LodMesh geo block.
 * geo must be the result of readLodMeshGeometry().
 *
 * Returns ['vertexNormals'=>[ [x,y,z], ... ],
 *          'wedgeNormals' =>[ [x,y,z], ... ] ]
 */
public function computeLodMeshNormals(array $geo): array {
    $P = $geo['Points']    ?? [];
    $W = $geo['Wedges']    ?? [];
    $F = $geo['Faces']     ?? [];

    $pn = count($P);
    $vn = array_fill(0, $pn, [0.0,0.0,0.0]); // vertex normal accumulators

    // Helper closures
    $add = function(array &$a, array $b): void { $a[0]+=$b[0]; $a[1]+=$b[1]; $a[2]+=$b[2]; };
    $sub = fn(array $a, array $b) => [$a[0]-$b[0], $a[1]-$b[1], $a[2]-$b[2]];
    $cross = fn(array $a, array $b) => [
        $a[1]*$b[2]-$a[2]*$b[1],
        $a[2]*$b[0]-$a[0]*$b[2],
        $a[0]*$b[1]-$a[1]*$b[0]
    ];
    $norm = function(array $v): array {
        $len = sqrt($v[0]*$v[0]+$v[1]*$v[1]+$v[2]*$v[2]);
        if ($len > 1e-12) { $inv = 1.0/$len; return [$v[0]*$inv,$v[1]*$inv,$v[2]*$inv]; }
        return [0.0,0.0,1.0]; // fallback
    };

    // Accumulate face normals to each involved vertex (via wedge -> vertexIndex)
    foreach ($F as $tri) {
        $w1 = $tri['w1'] ?? null; $w2 = $tri['w2'] ?? null; $w3 = $tri['w3'] ?? null;
        if ($w1===null || $w2===null || $w3===null) continue;
        if (!isset($W[$w1], $W[$w2], $W[$w3])) continue;

        $i1 = (int)$W[$w1]['vertexIndex'];
        $i2 = (int)$W[$w2]['vertexIndex'];
        $i3 = (int)$W[$w3]['vertexIndex'];
        if (!isset($P[$i1], $P[$i2], $P[$i3])) continue;

        $p1 = $P[$i1]; $p2 = $P[$i2]; $p3 = $P[$i3];
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
    foreach ($vn as $k => $acc) { $vn[$k] = $norm($acc); }

    // Per-wedge normals (copy the vertex normal they reference)
    $wn = [];
    foreach ($W as $w) {
        $vi = (int)$w['vertexIndex'];
        $wn[] = $vn[$vi] ?? [0.0,0.0,1.0];
    }

    return ['vertexNormals'=>$vn, 'wedgeNormals'=>$wn];
}
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
    if (!$full || !$geo) return null;

    $sections = $full['LODs'][0]['Sections'] ?? []; // choose LOD 0 by default
    $faces    = $geo['Faces']  ?? [];
    $wedges   = $geo['Wedges'] ?? [];
    $points   = $geo['Points'] ?? [];

    // helper math
    $sub = fn(array $a, array $b) => [$a[0]-$b[0], $a[1]-$b[1], $a[2]-$b[2]];
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

        $umin=INF; $vmin=INF; $umax=-INF; $vmax=-INF;
        $fnSum = [0.0,0.0,0.0]; $validFaces=0;

        for ($t=$first; $t<=$last; $t++) {
            if (!isset($faces[$t])) break;
            $f = $faces[$t];
            $w1=$f['w1']??null; $w2=$f['w2']??null; $w3=$f['w3']??null;
            if (!isset($wedges[$w1],$wedges[$w2],$wedges[$w3])) continue;

            // UV range
            foreach ([$wedges[$w1],$wedges[$w2],$wedges[$w3]] as $w) {
                $u=$w['u']; $v=$w['v'];
                if ($u<$umin) $umin=$u; if ($u>$umax) $umax=$u;
                if ($v<$vmin) $vmin=$v; if ($v>$vmax) $vmax=$v;
            }

            // Face normal (from vertex positions)
            $i1=$wedges[$w1]['vertexIndex']; $i2=$wedges[$w2]['vertexIndex']; $i3=$wedges[$w3]['vertexIndex'];
            if (!isset($points[$i1],$points[$i2],$points[$i3])) continue;
            $p1=$points[$i1]; $p2=$points[$i2]; $p3=$points[$i3];
            $fn = $norm($cross($sub($p2,$p1), $sub($p3,$p1)));
            $fnSum[0]+=$fn[0]; $fnSum[1]+=$fn[1]; $fnSum[2]+=$fn[2];
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
 */
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
/*
$ok = $pkg->renderLodMeshPNG($i, __DIR__."/lodmesh_preview.png", [
  'mode' => 'flat',
  'rotate' => [35, 20, 0],
  'size' => [800, 800]
]);
*/
public function renderLodMeshSVG(int $exportIndex, array $opt=[]): string {
    $opt['mode'] = 'wire';
    $opt['size'] = $opt['size'] ?? [640,640];
    $svgW = $opt['size'][0]; $svgH = $opt['size'][1];

    // Reuse the PNG pipeline up to projected 2D points, but instead of drawing, build SVG lines.
    // For brevity here, call renderLodMeshPNG’s math path and adapt to SVG if you want a no-deps path.
    return "<svg width='{$svgW}' height='{$svgH}' xmlns='http://www.w3.org/2000/svg'><rect width='100%' height='100%' fill='#141418'/><!-- TODO: lines --></svg>";
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

	public function readMusic(int $exportIndex): ?array {
		$R = $this->exportBodyReader($exportIndex);
		if (!$R) return null;
		$this->skipProperties($R);

		// The PDF notes “single chunk” typical; layout varies by version.
		$rem = $R->remaining();
		$chunk = ($rem > 0) ? $R->bytes($rem) : '';
		return ['Bytes'=>strlen($chunk), 'Data'=>$chunk];
	}


	
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
					$valDisplay = ($typeName !== '' 
						? ('Type(' . $typeName . ') raw (' . (int)$payloadLen . ' bytes)')
						: ('raw (' . (int)$payloadLen . ' bytes)'));
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


    private function readNameTable(): void
	{
		$count   = $this->header['nameCount'];
		$offset  = $this->header['nameOffset'];
		$version = (int)($this->header['version'] ?? 0);

		if ($version >= 334 && $this->isCompressed) {
			$R = $this->makeFullUncompressedReader();
			$R->seek($offset);
		} else {
			$R = $this->R;
			$R->seek($offset);
		}

		$names = [];
		
		for ($i = 0; $i < $count; $i++) {
			if ($version < 334) {  // UE1/UE2 — keep your old working logic
				$nameStr = $this->readNAME($R, $version);
				$flags   = ($version >= 141) ? $R->u64() : $R->u32();
			} else { // UE3 — FString with signed i32 length
				$len = $R->i32();
				
				if ($len === 0)        
					$nameStr = '';
				
				elseif ($len > 0)      
					$nameStr = rtrim($R->bytes($len), "\x00");
				else                   
					$nameStr = rtrim(@iconv('UTF-16LE','UTF-8//IGNORE', $R->bytes((- $len) * 2)) ?: '', "\x00");
				
				$flags = ($version >= 141) ? $R->u64() : $R->u32();
			}
			
			$names[] = ['index'=>$i, 'name'=>$nameStr, 'flags'=>$flags];
		}
		
		$this->names = $names;
	}

	public function exportDisplayName(int $ref): string {
		return $this->displayNameFromRef($ref);
	}
	
		
	/**
	 * Build "Package.Group.Object" from a RAW object ref (0 / +N / −N).
	 */
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

	private function nameStr(int $nameIndex): string {
		return ($nameIndex >= 0 && $nameIndex < count($this->names)) ? (string)($this->names[$nameIndex]['name'] ?? '') : '';
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

	private function readImportTable(): void
	{
		$count   = $this->header['importCount'];
		$offset  = $this->header['importOffset'];
		$version = (int)($this->header['version'] ?? 0);

		if ($version >= 334 && $this->isCompressed) {
			$R = $this->makeFullUncompressedReader();
			$R->seek($offset);
		} else {
			$R = $this->R; $R->seek($offset);
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
		$count   = $this->header['exportCount'];
		$offset  = $this->header['exportOffset'];
		$version = (int)($this->header['version'] ?? 0);

		if ($version >= 334 && $this->isCompressed) {
			$R = $this->makeFullUncompressedReader();
			$R->seek($offset);
		} else {
			$R = $this->R; $R->seek($offset);
		}

		$exports = [];
		
		for ($i=0; $i<$count; $i++) {
			$classIndex   = $R->index();  // ref: 0/±
			$superIndex   = $R->index();  // ref: 0/±
			$packageIndex = $R->i32();    // ref: 0/±
			$objectName   = $R->index();  // name index
			$objectFlags  = ($version >= 195) ? $R->u64() : $R->u32();
			$serialSize   = $R->index();
			
			if ($serialSize > 0) 
				$serialOffset = $R->index(); 
			else 
				$serialOffset = 0;
			
			$exports[] = compact('classIndex', 'superIndex', 'packageIndex', 'objectName', 'objectFlags', 'serialSize', 'serialOffset');
		}
		
		$this->exports = $exports;
	}

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
	
	/** Name table helper */
	public function nameByIndex(?int $idx): ?string {
		if ($idx === null) 
			return null;
		
		return $this->names[$idx]['name'] ?? null;
	}

	private function resolveObjectRef(int $idx): array
	{
		if ($idx === 0) 
			return ['type'=>'none','index'=>-1];
		
		if ($idx > 0)   
			return ['type'=>'export','index'=>$idx - 1];
		
		return ['type'=>'import','index'=>(-$idx) - 1];
	}	
	
	
	
	
	
	
//.public function exportClassName(int $ref): string;    // raw object ref (INDEX): classIndex
//.public function exportSuperName(int $ref): string;    // raw object ref (INDEX): superIndex
//.public function exportPackageName(int $ref): string;  // raw object ref (DWORD/INDEX): packageIndex → group path
//.public function exportObjectName(int $nameIx): string;// name table index (INDEX): objectName

// --- helpers (keep private) ----------------------------------------------

/** Resolve a raw object ref (0/+N/−N) to its display name (imports/exports). */
private function displayNameFromRef(int $ref): string {
    if ($ref === 0) return '';               // None
    if ($ref > 0) {                          // export
        $ix = $ref - 1;
        return isset($this->exports[$ix]) ? $this->nameStr($this->exports[$ix]['objectName']) : '';
    }                                        // import
    $ix = (-$ref) - 1;
    return isset($this->imports[$ix]) ? $this->nameStr($this->imports[$ix]['objectName']) : '';
}

/** Build full path (Package.Group...) from a raw object ref by walking outers. */
private function groupPathFromRef(int $ref): string {
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
public function exportPackageName(int $ref): string {
    return $this->groupPathFromRef($ref);
}

/** Object name (name table index from Export.objectName). */
public function exportObjectName(int $nameIndex): string {
    return $this->nameByIndex($nameIndex);
}

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
		if ($start < 0) $start = 0;
		if ($end > $this->len) $end = $this->len;
		if ($end < $start) $end = $start;

		$this->minPos = $start;
		$this->maxPos = $end;

		// If current pos is outside, clamp it inside the new bounds.
		if ($this->pos < $this->minPos) $this->pos = $this->minPos;
		if ($this->pos > $this->maxPos) $this->pos = $this->maxPos;
	}

	/** Remove bounds: allow reading the entire buffer again. */
	public function clearBounds(): void {
		$this->minPos = 0;
		$this->maxPos = $this->len;
	}
	
    public function setVersion(int $v): void { 
		$this->version = $v; 
	}
	
    public function getVersion(): int        { 
		return $this->version; 
	}	

	// Inside class UEReader (only if you don't already have them)
	public function length():         int { 
		return strlen($this->buf); 
	}
	
	public function tell():           int { 
		return $this->pos; 
	}
	
	/** Bytes remaining within the current bounds. */
	public function remaining(): int {
		$rem = $this->maxPos - $this->pos;
		
		//return ($rem > 0) ? $rem : 0;
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
		$chunk = substr($this->buf, $this->pos, $n);
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