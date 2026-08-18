<?php
/**
 * Purpose: Persists and loads one compressed package-table snapshot for an unverified file.
 * Why: Unverified staging needs Names/Imports/Exports before a game is selected, without using the retired global metadata tables.
 * Role: Dedicated persistence boundary for temporary unverified metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;

final class CatalogUnverifiedMetadataStore
{
    public const FORMAT_VERSION = 1;
    public const CODEC = 'gzip-json';

    private static array $schemaVerified = [];

    public function __construct(private readonly PDO $db)
    {
    }

    public function ensureSchema(): void
    {
        $key = spl_object_id($this->db);
        if (!empty(self::$schemaVerified[$key])) {
            return;
        }
        $statement = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_unverified_metadata"'
        );
        $statement->execute();
        if ((int)$statement->fetchColumn() !== 1) {
            throw new RuntimeException(
                'The unverified metadata staging schema is not migrated. '
                . 'Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }
        self::$schemaVerified[$key] = true;
    }

    /** @param array<string,mixed> $snapshot @return array<string,int|string> */
    public function write(array $snapshot): array
    {
        $this->ensureSchema();
        $fileId = (int)($snapshot['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new RuntimeException('Unverified metadata snapshot has no file identity.');
        }
        $names = array_values((array)($snapshot['names'] ?? []));
        $imports = array_values((array)($snapshot['imports'] ?? []));
        $exports = array_values((array)($snapshot['exports'] ?? []));
        $snapshot['file_id'] = $fileId;
        $snapshot['names'] = $names;
        $snapshot['imports'] = $imports;
        $snapshot['exports'] = $exports;

        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $compressed = gzencode($json, 6, ZLIB_ENCODING_GZIP);
        if (!is_string($compressed)) {
            throw new RuntimeException('Could not compress unverified metadata for file #' . $fileId . '.');
        }

        $statement = $this->db->prepare(
            'INSERT INTO ue_unverified_metadata('
            . 'file_id,format_version,codec,name_count,import_count,export_count,'
            . 'uncompressed_size,compressed_size,payload) '
            . 'VALUES(?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'name_count=VALUES(name_count),import_count=VALUES(import_count),export_count=VALUES(export_count),'
            . 'uncompressed_size=VALUES(uncompressed_size),compressed_size=VALUES(compressed_size),payload=VALUES(payload)'
        );
        $statement->bindValue(1, $fileId, PDO::PARAM_INT);
        $statement->bindValue(2, self::FORMAT_VERSION, PDO::PARAM_INT);
        $statement->bindValue(3, self::CODEC, PDO::PARAM_STR);
        $statement->bindValue(4, count($names), PDO::PARAM_INT);
        $statement->bindValue(5, count($imports), PDO::PARAM_INT);
        $statement->bindValue(6, count($exports), PDO::PARAM_INT);
        $statement->bindValue(7, strlen($json), PDO::PARAM_INT);
        $statement->bindValue(8, strlen($compressed), PDO::PARAM_INT);
        $statement->bindValue(9, $compressed, PDO::PARAM_LOB);
        $statement->execute();

        return [
            'file_id' => $fileId,
            'format_version' => self::FORMAT_VERSION,
            'codec' => self::CODEC,
            'name_count' => count($names),
            'import_count' => count($imports),
            'export_count' => count($exports),
            'uncompressed_size' => strlen($json),
            'compressed_size' => strlen($compressed),
        ];
    }

    /** @return array<string,mixed> */
    public function load(int $fileId, ?string $knownPackageName = null): array
    {
        $this->ensureSchema();
        if ($fileId < 1) {
            throw new RuntimeException('A positive unverified file ID is required.');
        }
        $statement = $this->db->prepare(
            'SELECT format_version,codec,name_count,import_count,export_count,'
            . 'uncompressed_size,compressed_size,payload '
            . 'FROM ue_unverified_metadata WHERE file_id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unverified metadata snapshot is missing for file #' . $fileId . '.');
        }
        if ((int)$row['format_version'] !== self::FORMAT_VERSION || (string)$row['codec'] !== self::CODEC) {
            throw new RuntimeException('Unsupported unverified metadata format for file #' . $fileId . '.');
        }

        $payload = $row['payload'];
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }
        if (!is_string($payload)) {
            throw new RuntimeException('Unverified metadata payload is unreadable for file #' . $fileId . '.');
        }
        $json = gzdecode($payload);
        if (!is_string($json)) {
            throw new RuntimeException('Unverified metadata payload could not be decompressed for file #' . $fileId . '.');
        }
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot)) {
            throw new RuntimeException('Unverified metadata payload is invalid for file #' . $fileId . '.');
        }
        $snapshot['file_id'] = $fileId;
        $snapshot['names'] = array_values((array)($snapshot['names'] ?? []));
        $snapshot['imports'] = array_values((array)($snapshot['imports'] ?? []));
        $snapshot['exports'] = array_values((array)($snapshot['exports'] ?? []));

        $expected = [
            'names' => (int)$row['name_count'],
            'imports' => (int)$row['import_count'],
            'exports' => (int)$row['export_count'],
        ];
        foreach ($expected as $section => $count) {
            if (count($snapshot[$section]) !== $count) {
                throw new RuntimeException(
                    'Unverified metadata ' . $section . ' count mismatch for file #' . $fileId . '.'
                );
            }
        }

        // A staged-file rename changes package identity but not parser table
        // structure. Rebase export full paths at read time so no large payload
        // rewrite is needed for a rename operation. Callers that already loaded
        // the current ue_files row can supply its package name and avoid another
        // database round trip on hot bulk/matching paths.
        $packageName = trim((string)($knownPackageName ?? ''));
        if ($packageName === '') {
            $package = $this->db->prepare('SELECT package_name FROM ue_files WHERE id=?');
            $package->execute([$fileId]);
            $packageName = trim((string)($package->fetchColumn() ?: ($snapshot['package_name'] ?? '')));
        }
        if ($packageName !== '') {
            $snapshot['package_name'] = $packageName;
            foreach ($snapshot['exports'] as $index => $export) {
                $local = trim((string)($export['local_path'] ?? ''));
                $snapshot['exports'][$index]['full_path'] = $local !== ''
                    ? $packageName . '.' . $local
                    : $packageName;
            }
            $snapshot['paths']['exports'] = array_map(
                static fn(array $export): array => [
                    'local' => (string)($export['local_path'] ?? ''),
                    'full' => (string)($export['full_path'] ?? ''),
                ],
                $snapshot['exports']
            );
        }
        return $snapshot;
    }

    public function has(int $fileId): bool
    {
        $this->ensureSchema();
        $statement = $this->db->prepare('SELECT 1 FROM ue_unverified_metadata WHERE file_id=? LIMIT 1');
        $statement->execute([$fileId]);
        return (bool)$statement->fetchColumn();
    }

    public function delete(int $fileId): int
    {
        $this->ensureSchema();
        $statement = $this->db->prepare('DELETE FROM ue_unverified_metadata WHERE file_id=?');
        $statement->execute([$fileId]);
        return $statement->rowCount();
    }

    /** @param list<int> $fileIds @return array<int,array{name_count:int,import_count:int,export_count:int}> */
    public function countsForFiles(array $fileIds): array
    {
        $this->ensureSchema();
        $fileIds = array_values(array_unique(array_filter(
            array_map('intval', $fileIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($fileIds === []) {
            return [];
        }
        $statement = $this->db->prepare(
            'SELECT file_id,name_count,import_count,export_count FROM ue_unverified_metadata '
            . 'WHERE file_id IN (' . implode(',', array_fill(0, count($fileIds), '?')) . ')'
        );
        $statement->execute($fileIds);
        $out = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int)$row['file_id']] = [
                'name_count' => (int)$row['name_count'],
                'import_count' => (int)$row['import_count'],
                'export_count' => (int)$row['export_count'],
            ];
        }
        return $out;
    }
}
