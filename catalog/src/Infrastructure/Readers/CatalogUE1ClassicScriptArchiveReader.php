<?php
/**
 * Classic UE1 UStruct::SerializeExpr archive traversal.
 *
 * Authorities: Unreal v1.200 Core/Src/UnClass.cpp:1095-1348,
 * Core/Inc/UnScript.h:65+; UT99 v400 Core/Src/UnClass.cpp:1076-1344,
 * Core/Inc/UnStack.h:75+.
 *
 * XFER(T) advances the in-memory iCode by sizeof(T), while the package
 * FArchive encodes object pointers and FName using compact indices. These are two
 * different offsets: NEVER seek ahead by ScriptSize disk bytes.
 * Only source-supported token branches are decoded, others stay partial.
 * No VM execution, runtime pointer linking or object instantiation.
 */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Readers;

use RuntimeException;

final class CatalogUE1ClassicScriptArchiveReader
{
    /** @return array<string,mixed> */
    public static function inspect(
        CatalogUE1PackageReader $pkg, int $version, int $scriptSize,
        callable $read, callable $compact, int &$position,
        ?string $sourceFamily=null
    ): array {
        $family=CatalogUE1ClassicSourceFamily::select($version,$sourceFamily);
        // Epic's source archives ScriptSize as a signed INT. Never impose
        // a synthetic maximum such as 16 MB: the decoder is bounded by
        // the actual FArchive and in-memory ScriptSize budget below.
        if ($scriptSize < 0) {
            throw new RuntimeException('UE1 negative ScriptSize');
        }
        $start = $position;
        $iCode = 0;
        $count = 0;
        $unsupported = null;
        $preview = [];
        $lastToken = null; $lastTokenArchiveOffset = null;
        $ut99 = $family===CatalogUE1ClassicSourceFamily::UT99_V400;

        $mem = static function(int $n) use (
            &$iCode, $scriptSize, &$lastToken, &$lastTokenArchiveOffset, &$position
        ): void {
            $iCode += $n;
            if ($iCode > $scriptSize) {
                throw new RuntimeException(sprintf(
                    'UE1 SerializeExpr exceeded decoded script-memory ScriptSize '
                    .'(memory=%d/%d, token=%s, token_archive=%s, archive=%d)',
                    $iCode, $scriptSize,
                    $lastToken === null ? 'none' : sprintf('0x%02X', $lastToken),
                    $lastTokenArchiveOffset === null ? 'none' : (string)$lastTokenArchiveOffset,
                    $position
                ));
            }
        };
        $byte = static function() use ($read, $mem): int {
            $v = ord($read(1)); $mem(1); return $v;
        };
        $word = static function() use ($read, $mem): int {
            $v = (int)unpack('v', $read(2))[1]; $mem(2); return $v;
        };
        $dword = static function() use ($read, $mem): int {
            $v = (int)unpack('V', $read(4))[1]; $mem(4); return $v;
        };
        $ref = static function() use ($compact, $pkg, $mem): int {
            $v = $compact();
            if (($v < 0 && -$v > count($pkg->getImports())) ||
                ($v > 0 && $v > count($pkg->getExports()))) {
                throw new RuntimeException('UE1 SerializeExpr invalid object reference');
            }
            $mem(4); // C++ XFER(UObject*) or XFER(UProperty*) is sizeof(ptr).
            return $v;
        };
        $fname = static function() use ($compact, $pkg, $mem): array {
            $id = $compact();
            $names = $pkg->getNames();
            if ($id < 0 || !isset($names[$id])) {
                throw new RuntimeException('UE1 SerializeExpr invalid name index');
            }
            $mem(4); // C++ XFER(FName) increments iCode by sizeof(FName).
            return ['index' => $id, 'name' => (string)$names[$id]['name']];
        };

        $expression = null;
        $expression = static function(int $depth) use (
            &$expression, &$iCode, &$count, &$unsupported, $scriptSize,
            &$position, $ut99, $byte, $word, $dword, $ref, $fname, $read, $mem,
            &$lastToken, &$lastTokenArchiveOffset
        ): ?array {
            if ($unsupported !== null) { return null; }
            if ($depth > 128 || ++$count > $scriptSize) {
                throw new RuntimeException('UE1 SerializeExpr recursion or operation bound exceeded');
            }
            $begin = $position;
            $memBegin = $iCode;
            $token = $byte();
            $lastToken = $token; $lastTokenArchiveOffset = $begin;
            $node = ['token' => $token, 'memory_offset' => $memBegin,
                'archive_offset' => $begin, 'value' => null, 'children' => []];

            $child = static function() use (&$expression, &$node, &$unsupported, $depth): void {
                if ($unsupported !== null) { return; }
                $nested = $expression($depth + 1);
                if ($nested !== null && count($node['children']) < 24) {
                    $node['children'][] = $nested;
                }
            };
            $children = static function(int $n) use ($child, &$unsupported): void {
                for ($i = 0; $i < $n && $unsupported === null; $i++) {
                    $child();
                }
            };
            $params = static function() use (&$expression, &$node, &$unsupported, $depth, $scriptSize): void {
                for ($i = 0; $i < $scriptSize && $unsupported === null; $i++) {
                    $nested = $expression($depth + 1);
                    if ($nested === null) { return; }
                    if (count($node['children']) < 24) { $node['children'][] = $nested; }
                    if ($nested['token'] === 0x16) { return; }
                }
                if ($unsupported === null) {
                    throw new RuntimeException('UE1 SerializeExpr missing EndFunctionParms within ScriptSize');
                }
            };

            $maxConversion = $ut99 ? 0x60 : 0x5a;
            if ($token >= 0x39 && $token < $maxConversion) {
                $child(); // Every source conversion wraps exactly one expression.
            } elseif ($token >= 0x70) {
                $params(); // Native/Intrinsic function call parameters.
            } elseif ($token >= 0x60 && $token < 0x70) {
                $node['value'] = $byte(); // Extended native/intrinsic ID low byte.
                $params();
            } else {
                switch ($token) {
                    case 0x00: case 0x01: case 0x02: case 0x20: case 0x29:
                        $node['value'] = $ref();
                        break;
                    case 0x2d: case 0x0b: case 0x16: case 0x25: case 0x26:
                    case 0x27: case 0x28: case 0x2a: case 0x17:
                    case 0x30: case 0x31: case 0x08:
                        break;
                    case 0x04: // Unreal 1998 EX_Return no operand; UT99 returns expression.
                        if ($ut99) { $child(); }
                        break;
                    case 0x0e: // 1998 ValidateObject no operand; UT99 EatString expression.
                        if ($ut99) { $child(); }
                        break;
                    case 0x15: // 1998 EndCode, absent in UT99.
                        if ($ut99) {
                            $unsupported = 'UT99 EX_EndCode opcode not source-defined';
                        }
                        break;
                    case 0x06:
                        $node['value'] = $word();
                        break;
                    case 0x07: case 0x09: case 0x18: // JumpIfNot, Assert, Skip.
                        $node['value'] = $word();
                        $child();
                        break;
                    case 0x05: // EX_Switch BYTE value size, switch expression.
                        $node['value'] = $byte(); $child();
                        break;
                    case 0x0a: // EX_Case _WORD; MAXWORD has no condition.
                        $node['value'] = $word();
                        if ($node['value'] !== 65535) { $child(); }
                        break;
                    case 0x0c: // EX_LabelTable; FLabelEntry contains FName + INT iCode.
                        if (($iCode & 3) !== 0) {
                            throw new RuntimeException('UE1 EX_LabelTable requires memory alignment');
                        }
                        $labels = [];
                        for ($i = 0; $i < $scriptSize; $i++) {
                            $entry = ['name' => $fname(), 'code_offset' => $dword()];
                            if ($i < 32) { $labels[] = $entry; }
                            // Epic compares the resolved global FName against
                            // NAME_None, not the package-local NameMap slot.
                            // Genuine UT99 Engine.u has a terminal None at
                            // local index 3344, while index 0 is also None.
                            if (strcasecmp($entry['name']['name'], 'None') === 0) {
                                break;
                            }
                        }
                        if ($i >= $scriptSize) {
                            throw new RuntimeException('UE1 EX_LabelTable missing NAME_None terminator');
                        }
                        $node['value'] = $labels;
                        break;
                    case 0x0d: // GotoLabel expression.
                        $child();
                        break;
                    case 0x0f: // Let/assignment.
                        $children(2);
                        break;
                    case 0x10: // UT99 DynArrayElement.
                        if (!$ut99) { $unsupported = 'Unreal 1998 token 0x10 not defined'; break; }
                        $children(2);
                        break;
                    case 0x11: // UT99 New, four expressions.
                        if (!$ut99) { $unsupported = 'Unreal 1998 token 0x11 not defined'; break; }
                        $children(4);
                        break;
                    case 0x12: case 0x19: // ClassContext/Context.
                        $child();
                        if ($unsupported === null) {
                            $node['value'] = ['skip_offset' => $word(), 'zero_fill_size' => $byte()];
                            $child();
                        }
                        break;
                    case 0x13: case 0x2e: // MetaCast/DynamicCast class, expression.
                        $node['value'] = $ref(); $child();
                        break;
                    case 0x14: // 1998 BeginFunction; UT99 LetBool.
                        if ($ut99) {
                            $children(2);
                        } else {
                            $paramsInfo = [];
                            for ($i = 0; $i < $scriptSize; $i++) {
                                $size = $byte();
                                if ($size === 0) { break; }
                                $outParm = $byte();
                                if ($i < 32) { $paramsInfo[] = ['size' => $size, 'out' => $outParm]; }
                            }
                            if ($i >= $scriptSize) {
                                throw new RuntimeException('UE1 BeginFunction params unterminated');
                            }
                            $node['value'] = $paramsInfo;
                        }
                        break;
                    case 0x1a:
                        $children(2);
                        break;
                    case 0x1b: case 0x38:
                        $node['value'] = $fname(); $params();
                        break;
                    case 0x1c:
                        $node['value'] = $ref(); $params();
                        break;
                    case 0x1d:
                        $v = $dword();
                        $node['value'] = $v >= 0x80000000 ? $v - 0x100000000 : $v;
                        break;
                    case 0x1e:
                        $node['value'] = (float)unpack('g', $read(4))[1]; $mem(4);
                        break;
                    case 0x1f: // ANSI constant, zero terminated.
                        $text = '';
                        for ($i = 0; $i < $scriptSize; $i++) {
                            $char = $byte();
                            if ($char === 0) { break; }
                            $text .= chr($char);
                        }
                        if ($i >= $scriptSize) {
                            throw new RuntimeException('UE1 ANSI script string missing NUL');
                        }
                        $node['value'] = $text;
                        break;
                    case 0x21:
                        $node['value'] = $fname();
                        break;
                    case 0x22:
                        $node['value'] = [$dword(), $dword(), $dword()];
                        break;
                    case 0x23:
                        $node['value'] = array_values(unpack('g3', $read(12))); $mem(12);
                        break;
                    case 0x24: case 0x2c:
                        $node['value'] = $byte();
                        break;
                    case 0x2b: // Old ResizeString only in v1.200.
                        if ($ut99) { $unsupported = 'UT99 ResizeString not source-defined'; break; }
                        $node['value'] = $byte(); $child();
                        break;
                    case 0x2f:
                        $child();
                        if ($unsupported === null) { $node['value'] = $word(); }
                        break;
                    case 0x32: case 0x33: // Struct comparison: struct class then two operands.
                        $node['value'] = $ref(); $children(2);
                        break;
                    case 0x34: // UT99 EX_UnicodeStringConst; v1.200 StructConst not serialized.
                        if (!$ut99) {
                            $unsupported = 'Unreal 1998 EX_StructConst lacks SerializeExpr branch';
                            break;
                        }
                        $w = [];
                        for ($i = 0; $i < $scriptSize; $i++) {
                            $part = $word();
                            // Epic UT99 v400 UnClass.cpp: XFER(_WORD) then
                            // while (Script(iCode-1)): tests the HIGH byte of
                            // the just-written little-endian _WORD, not the
                            // whole 16-bit codepoint and not a UTF16 NUL.
                            if ($i < 512) { $w[] = $part; }
                            if (($part >> 8) === 0) { break; }
                        }
                        if ($i >= $scriptSize) {
                            throw new RuntimeException('UE1 Unicode script constant unterminated');
                        }
                        $node['value'] = $w;
                        break;
                    case 0x36:
                        $node['value'] = $ref(); $child();
                        break;
                    default:
                        $unsupported = sprintf('Source token 0x%02X not decoded', $token);
                        break;
                }
            }
            $node['memory_end'] = $iCode;
            $node['archive_end'] = $position;
            return $node;
        };

        while ($iCode < $scriptSize && $unsupported === null) {
            $node = $expression(0);
            if ($node !== null && count($preview) < 64) { $preview[] = $node; }
        }
        return [
            'status' => $unsupported === null ? 'script_archive_expressions_decoded'
                : 'script_archive_token_unimplemented',
            'source_family' => $family,
            'script_memory_expected' => $scriptSize,
            'script_memory_decoded' => $iCode,
            'archive_bytes_consumed' => $position - $start,
            'expression_count' => $count,
            'expression_preview' => $preview,
            'expression_preview_truncated' => $count > count($preview),
            'unsupported_branch' => $unsupported,
            'bytecode_executed' => false,
            'runtime_refs_resolved' => false,
        ];
    }
}
