<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

/**
 * Preview Epic's optional cooked ScriptObjects diagnostic paths, never live
 * UObject resolution. Input IDs supplied as "registered" MUST come from an
 * external runtime observation; this class does not infer them from a chunk.
 *
 * Epic UE5.8.3 AsyncLoading2.cpp:
 *  FCookedScriptObjectsDebug::TrimDebugData / GetPathNameInternal / GetPathName.
 */
final class Uedb5ZenScriptImportDiagnosticPreview
{
    /**
     * Simulate TrimDebugData against an explicitly supplied set of runtime
     * ScriptObjects indices, keeping missing records and all cooked outers.
     * Does not verify that the supplied indices are truly registered.
     *
     * @param array<string,array<string,mixed>> $objects Decoded cooked records
     * @param list<string> $runtimeRegisteredIndices Independent caller-supplied IDs
     * @return array<string,array<string,mixed>>
     */
    public static function retainMissingWithOuters(array $objects, array $runtimeRegisteredIndices): array
    {
        $registered = [];
        foreach ($runtimeRegisteredIndices as $index) {
            $key = self::checkedIndex((string)$index);
            $registered[$key] = true;
        }

        $keep = [];
        foreach ($objects as $index => $unused) {
            $key = self::checkedIndex((string)$index);
            if (isset($registered[$key])) {
                continue;
            }
            $walk = $key;
            $seen = [];
            while (isset($objects[$walk]) && !isset($keep[$walk])) {
                if (isset($seen[$walk])) {
                    throw new RuntimeException('Cooked ScriptObjects outer chain contains a cycle.');
                }
                $seen[$walk] = true;
                $keep[$walk] = true;
                $walk = self::checkedIndex((string)($objects[$walk]['outer_index'] ?? ''));
            }
        }

        return array_intersect_key($objects, $keep);
    }

    /**
     * Source-shaped GetPathName diagnostic fallback over a *chosen cooked map*.
     * Does not call Epic's live ScriptObjects.FindRef and never proves loading.
     *
     * @param array<string,array<string,mixed>> $retainedCookedObjects
     */
    public static function getCookedDiagnosticPathName(array $retainedCookedObjects, string $index): string
    {
        $key = self::checkedIndex($index);
        if (!isset($retainedCookedObjects[$key])) {
            return '';
        }

        $names = [];
        $visited = [];
        while (isset($retainedCookedObjects[$key])) {
            if (isset($visited[$key])) {
                throw new RuntimeException('Cooked ScriptObjects diagnostic path contains an outer cycle.');
            }
            $visited[$key] = true;
            $entry = (array)$retainedCookedObjects[$key];
            $names[] = (string)($entry['name'] ?? '');
            $key = self::checkedIndex((string)($entry['outer_index'] ?? ''));
        }
        $names = array_reverse($names);
        $path = '';
        foreach ($names as $depth => $name) {
            if ($depth > 0) {
                // Epic: NumParts 1 => '.', 2 => SUBOBJECT_DELIMITER_CHAR ':',
                // NumParts > 2 => '?' because the exact separator is unknown.
                $path .= $depth === 1 ? '.' : ($depth === 2 ? ':' : '?');
            }
            $path .= $name;
        }
        return $path;
    }

    private static function checkedIndex(string $index): string
    {
        $key = strtoupper($index);
        if (preg_match('/^[0-9A-F]{16}$/', $key) !== 1) {
            throw new RuntimeException('Cooked ScriptObjects index must be exact 64-bit hexadecimal.');
        }
        return $key;
    }
}
