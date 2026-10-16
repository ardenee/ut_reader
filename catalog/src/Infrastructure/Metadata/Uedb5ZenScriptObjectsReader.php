<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;
use RuntimeException;

final class Uedb5ZenScriptObjectsReader
{
    /**
     * Read the optional cooked ScriptObjects chunk from a retained global container.
     * No synthetic object identities are created when the chunk is absent.
     */
    public static function fromToc(Uedb5IoStoreTocReader $toc, int $maxObjects = 1000000): array
    {
        // UE5 EIoChunkType::ScriptObjects=5; CreateIoChunkId(0,0,...).
        $matches = [];
        foreach ($toc->chunkIndexesByType(5) as $index) {
            $chunk = $toc->chunks()[$index];
            if ((string)$chunk['package_id'] === '0000000000000000'
                && (int)($chunk['chunk_index'] ?? 0) === 0) {
                $matches[] = $index;
            }
        }
        if (count($matches) === 0) {
            return ['present'=>false,'count'=>0,'objects'=>[],'runtime_authoritative'=>false];
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('Ambiguous global ScriptObjects chunks.');
        }
        $parsed = self::parse($toc->readChunk($matches[0]), $maxObjects);
        return ['present'=>true] + $parsed;
    }

    /** Cooked global ScriptObjects diagnostics, never runtime authority. */
    public static function parse(string $bytes, int $maxObjects = 1000000): array
    {
        $reader = new Uedb5BinaryReader($bytes, 'UE5 ScriptObjects');
        $names = Uedb5ZenPackageReader::readNameBatchForScriptObjects($reader);
        $count = $reader->u32le();
        if ($count > $maxObjects || $count > intdiv($reader->remaining(), 32)
            || $reader->remaining() !== $count * 32) {
            throw new RuntimeException('Invalid ScriptObjects count or record length.');
        }
        $objects = [];
        for ($i = 0; $i < $count; $i++) {
            $rawIndex = $reader->u32le();
            $number = $reader->u32le();
            $mapped = Uedb5BinaryReader::decodeMappedNameIndex($rawIndex);
            if ((int)$mapped['type'] !== 1 || !isset($names[(int)$mapped['index']])) {
                throw new RuntimeException('ScriptObjects global mapped-name index is invalid.');
            }
            $base = (string)$names[(int)$mapped['index']]['text'];
            $name = $number > 0 ? $base.'_'.($number-1) : $base;
            $globalIndex = $reader->u64HexLe();
            $outerIndex = $reader->u64HexLe();
            $cdoClassIndex = $reader->u64HexLe();
            if (isset($objects[$globalIndex])) {
                throw new RuntimeException('Duplicate ScriptObjects global index.');
            }
            $objects[$globalIndex] = [
                'name'=>$name, 'global_index'=>$globalIndex,
                'outer_index'=>$outerIndex, 'cdo_class_index'=>$cdoClassIndex,
                'source'=>'cooked_script_objects_chunk',
                'runtime_resolved'=>false,
            ];
        }
        return ['count'=>$count,'objects'=>$objects,'runtime_authoritative'=>false];
    }
}
