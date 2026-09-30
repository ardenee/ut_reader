<?php
/**
 * Builds disposable SQL accelerator rows from an authoritative UEDB5 snapshot.
 * Source-shaped class/outer/flags/serialization data deliberately stays in UEDB5.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use RuntimeException;

final class Uedb5SqlProjectionBuilder
{
    public const PROVIDER_SOURCE_PRIMARY = 1;

    /** @param array<string,mixed> $snapshot @param array<string,mixed> $registration */
    public static function build(array $snapshot, array $registration): array
    {
        $fileId = (int)($snapshot['file']['id'] ?? 0);
        $gameId = (int)($snapshot['file']['game_id'] ?? 0);
        if ($fileId < 1 || $gameId < 1) {
            throw new RuntimeException('UEDB5 SQL projection requires positive file/game identity.');
        }
        if ((int)($registration['file_id'] ?? 0) !== $fileId || (int)($registration['game_id'] ?? 0) !== $gameId) {
            throw new RuntimeException('UEDB5 SQL projection registration identity mismatch.');
        }
        $provider = [[
            'source_kind' => self::PROVIDER_SOURCE_PRIMARY,
            'source_id' => $fileId,
            'game_id' => $gameId,
            'package_key_kind' => (int)($registration['package_key_kind'] ?? 0),
            'package_key' => (string)($registration['package_key'] ?? ''),
            'file_id' => $fileId,
        ]];

        $search = [];
        $names = [];
        $nameRows = self::nameRows($snapshot);
        foreach ($nameRows as $fallback => $row) {
            $row = (array)$row;
            $text = self::rowText($row);
            if ($text === '') { continue; }
            $key = self::key($text);
            $fingerHex = bin2hex($key['fingerprint']);
            $search[$fingerHex] = $key;
            if (!isset($names[$fingerHex])) {
                $names[$fingerHex] = [
                    'file_id' => $fileId,
                    'name_key_hash' => $key['hash'],
                    'name_key_length' => $key['length'],
                    'name_key_fingerprint' => $key['fingerprint'],
                    'first_name_index' => max(0, (int)($row['index'] ?? $fallback)),
                ];
            }
        }
        $packageName = trim((string)($snapshot['file']['package_name'] ?? ''));
        if ($packageName !== '') {
            $packageKey = self::key($packageName);
            $search[bin2hex($packageKey['fingerprint'])] = $packageKey;
        }

        $objects = [];
        foreach ([
            ['exports', Uedb5SqlProjectionContract::OBJECT_KIND_EXPORT],
            ['cell_exports', Uedb5SqlProjectionContract::OBJECT_KIND_CELL_EXPORT],
        ] as [$section, $kind]) {
            foreach ((array)($snapshot['sections'][$section] ?? []) as $fallback => $row) {
                $row = (array)$row;
                $text = self::objectText($row);
                if ($text === '') { continue; }
                $key = self::key($text);
                $search[bin2hex($key['fingerprint'])] = $key;
                $objects[] = [
                    'file_id' => $fileId,
                    'object_kind' => $kind,
                    'object_index' => max(0, (int)($row['index'] ?? $fallback)),
                    'object_name_hash' => $key['hash'],
                    'object_name_length' => $key['length'],
                    'public_export_hash' => self::publicHash($row),
                ];
            }
        }
        return [
            'provider_keys' => $provider,
            'search_keys' => array_values($search),
            'name_candidates' => array_values($names),
            'object_candidates' => $objects,
            'dependency_edges' => [],
            'dependency_packages' => [],
        ];
    }

    /** @param array<string,mixed> $snapshot @return list<array<string,mixed>> */
    private static function nameRows(array $snapshot): array
    {
        if (isset($snapshot['sections']['names'])) {
            return array_values((array)$snapshot['sections']['names']);
        }
        return array_values((array)($snapshot['sections']['name_map'] ?? []));
    }

    /** @param array<string,mixed> $row */
    private static function rowText(array $row): string
    {
        return trim((string)($row['text'] ?? $row['name'] ?? ''));
    }

    /** @param array<string,mixed> $row */
    private static function objectText(array $row): string
    {
        $value = $row['object_name'] ?? $row['objectName'] ?? null;
        if (is_array($value)) {
            return trim((string)($value['text'] ?? $value['base_text'] ?? ''));
        }
        return trim((string)($row['objectNameText'] ?? $value ?? ''));
    }
    /** @return array{hash:string,length:int,fingerprint:string,normalized_text:string} */
    private static function key(string $text): array
    {
        $normalized = CatalogUnrealIdentityHash::nameKey($text);
        return [
            'hash' => md5($normalized, true),
            'length' => strlen($normalized),
            'fingerprint' => hash('sha256', $normalized, true),
            'normalized_text' => $normalized,
        ];
    }

    /** @param array<string,mixed> $row */
    private static function publicHash(array $row): ?string
    {
        $hex = strtoupper(trim((string)($row['public_export_hash'] ?? '')));
        if ($hex === '' || $hex === '0000000000000000') {
            return null;
        }
        if (preg_match('/^[0-9A-F]{16}$/', $hex) !== 1) {
            throw new RuntimeException('UEDB5 object candidate has an invalid PublicExportHash.');
        }
        $binary = hex2bin($hex);
        if (!is_string($binary) || strlen($binary) !== 8) {
            throw new RuntimeException('Could not encode UEDB5 PublicExportHash.');
        }
        return $binary;
    }
}
