<?php
/** Source-backed, collision-safe compact candidate projection. No SQL writes. */
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5CompactCandidateProjectionBuilder
{
    /**
     * Convert canonical V5 projection rows into compact name-ID rows.
     *
     * Dictionary entries must contain name_id, key_hash (raw 16 bytes),
     * key_length, key_fingerprint (raw 32 bytes) and normalized_text.
     * All object identity is re-derived from the authoritative V5 snapshot,
     * never guessed from an MD5 lookup alone.
     *
     * @param array<string,mixed> $snapshot
     * @param array<string,mixed> $projection Result of Uedb5SqlProjectionBuilder::build()
     * @param list<array<string,mixed>> $dictionary
     * @return array{name_candidates:list<array<string,int>>,object_candidates:list<array<string,int|string|null>>}
     */
    public static function build(array $snapshot, array $projection, array $dictionary): array
    {
        $byFingerprint = [];
        $ids = [];
        foreach ($dictionary as $entry) {
            $id = (int)($entry['name_id'] ?? 0);
            $hash = (string)($entry['key_hash'] ?? '');
            $length = (int)($entry['key_length'] ?? -1);
            $fingerprint = (string)($entry['key_fingerprint'] ?? '');
            $normalized = (string)($entry['normalized_text'] ?? '');
            if ($id < 1 || strlen($hash) !== 16 || strlen($fingerprint) !== 32 ||
                strlen($normalized) !== $length ||
                !hash_equals(md5($normalized, true), $hash) ||
                !hash_equals(hash('sha256', $normalized, true), $fingerprint)) {
                throw new RuntimeException('Invalid compact name dictionary identity.');
            }
            $hex = bin2hex($fingerprint);
            if (isset($ids[$id]) || isset($byFingerprint[$hex])) {
                throw new RuntimeException('Duplicate compact dictionary ID or fingerprint.');
            }
            $ids[$id] = true;
            $byFingerprint[$hex] = ['id'=>$id,'hash'=>$hash,'length'=>$length,'text'=>$normalized];
        }

        $fileId = (int)($snapshot['file']['id'] ?? 0);
        if ($fileId < 1) throw new RuntimeException('Missing source V5 file identity.');

        $names = [];
        foreach ((array)($projection['name_candidates'] ?? []) as $candidate) {
            $fingerprint = (string)($candidate['name_key_fingerprint'] ?? '');
            $hex = bin2hex($fingerprint);
            $entry = $byFingerprint[$hex] ?? null;
            if ($entry === null || (int)($candidate['file_id'] ?? 0) !== $fileId ||
                !hash_equals($entry['hash'], (string)($candidate['name_key_hash'] ?? '')) ||
                $entry['length'] !== (int)($candidate['name_key_length'] ?? -1)) {
                throw new RuntimeException('Unresolved compact FName candidate identity.');
            }
            $index = (int)($candidate['first_name_index'] ?? -1);
            $section = $snapshot['sections']['names'] ?? $snapshot['sections']['name_map'] ?? [];
            $source = self::indexed($section, $index);
            $text = trim((string)($source['text'] ?? $source['name'] ?? ''));
            if ($text === '' || CatalogUnrealIdentityHash::nameKey($text) !== $entry['text']) {
                throw new RuntimeException('FName source normalization mismatch.');
            }
            $names[] = ['file_id'=>$fileId,'name_id'=>$entry['id'],'first_name_index'=>$index];
        }

        $objects = [];
        foreach ((array)($projection['object_candidates'] ?? []) as $candidate) {
            if ((int)($candidate['file_id'] ?? 0) !== $fileId) {
                throw new RuntimeException('Object candidate file identity mismatch.');
            }
            $kind = (int)($candidate['object_kind'] ?? -1);
            $sectionName = match ($kind) {
                Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT => 'exports',
                Uedb5SqlProjectionContract::OBJECT_KIND_CELL_EXPORT => 'cell_exports',
                default => throw new RuntimeException('Unrecognized object candidate kind.'),
            };
            $index = (int)($candidate['object_index'] ?? -1);
            $source = self::indexed($snapshot['sections'][$sectionName] ?? [], $index);
            $value = $source['object_name'] ?? $source['objectName'] ?? null;
            $text = is_array($value)
                ? trim((string)($value['text'] ?? $value['base_text'] ?? ''))
                : trim((string)($source['objectNameText'] ?? $value ?? ''));
            $normalized = CatalogUnrealIdentityHash::nameKey($text);
            if ($normalized === '') throw new RuntimeException('Empty source object candidate name.');
            $hash = md5($normalized, true);
            $length = strlen($normalized);
            $entry = $byFingerprint[bin2hex(hash('sha256', $normalized, true))] ?? null;
            if ($entry === null || $entry['text'] !== $normalized ||
                !hash_equals($entry['hash'], $hash) || $entry['length'] !== $length ||
                !hash_equals($hash, (string)($candidate['object_name_hash'] ?? '')) ||
                $length !== (int)($candidate['object_name_length'] ?? -1)) {
                throw new RuntimeException('Unresolved compact object identity from authoritative V5 source.');
            }
            $objects[] = [
                'file_id'=>$fileId,'object_kind'=>$kind,'object_index'=>$index,
                'name_id'=>$entry['id'],
                'public_export_hash'=>$candidate['public_export_hash'] ?? null,
            ];
        }
        return ['name_candidates'=>$names,'object_candidates'=>$objects];
    }

    /** @param mixed $rows @return array<string,mixed> */
    private static function indexed(mixed $rows, int $index): array
    {
        if ($index < 0 || !is_array($rows)) {
            throw new RuntimeException('Invalid V5 snapshot section/index.');
        }
        foreach ($rows as $fallback=>$row) {
            if ((int)($row['index'] ?? $fallback) === $index) return (array)$row;
        }
        throw new RuntimeException('Candidate index absent from authoritative V5 snapshot.');
    }
}
