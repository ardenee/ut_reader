<?php
/**
 * Registers verified UEDB5 containers beside the live UEDB4 registration.
 * This class deliberately never updates ue_file_metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use JsonException;
use PDO;
use RuntimeException;

final class PdoUedb5StagingRegistrationRepository
{
    private Uedb5MetadataReader $reader;

    public function __construct(
        private readonly PDO $db,
        string $storageRoot
    ) {
        $this->reader = new Uedb5MetadataReader($storageRoot);
    }

    /** @return array<string,mixed> */
    public function inspect(int $gameId, int $fileId): array
    {
        $verified = $this->reader->verify($gameId, $fileId);
        $manifest = (array)($verified['manifest'] ?? []);
        if ((int)($manifest['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('Staged metadata is not UEDB5.');
        }

        $counts = [];
        foreach ((array)($manifest['counts'] ?? []) as $section => $count) {
            $section = (string)$section;
            $count = (int)$count;
            if ($section === '' || $count < 0) {
                throw new RuntimeException('UEDB5 manifest contains an invalid section count.');
            }
            $counts[$section] = $count;
        }
        ksort($counts, SORT_STRING);
        try {
            $countsJson = json_encode($counts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('Could not encode UEDB5 section counts.', 0, $error);
        }

        [$packageKeyKind, $packageKey] = $this->packageKey($gameId, $fileId, $manifest);
        $uncompressedSize = max(0, (int)$verified['payload_start'] - Uedb5MetadataContainer::HEADER_LENGTH);
        foreach ((array)($manifest['sections'] ?? []) as $blocks) {
            foreach ((array)$blocks as $block) {
                $uncompressedSize += (int)((array)$block)['uncompressed_length'];
            }
        }
        return [
            'file_id' => $fileId,
            'game_id' => $gameId,
            'format_version' => Uedb5MetadataContainer::FORMAT_VERSION,
            'codec' => Uedb5MetadataContainer::CODEC_BLOCK_GZIP,
            'compressed_size' => (int)$verified['compressed_size'],
            'uncompressed_size' => $uncompressedSize,
            'payload_sha256' => (string)$verified['payload_sha256'],
            'block_count' => (int)$verified['block_count'],
            'package_family' => (string)($manifest['package_family'] ?? ''),
            'source_policy' => (string)($manifest['source_policy'] ?? ''),
            'package_key_kind' => $packageKeyKind,
            'package_key' => $packageKey,
            'package_name' => (string)($manifest['file']['package_name'] ?? ''),
            'section_counts_json' => $countsJson,
            'section_counts' => $counts,
        ];
    }

    /** @return array<string,mixed> */
    public function register(int $gameId, int $fileId): array
    {
        $row = $this->inspect($gameId, $fileId);
        $this->assertVerifiedCatalogFile($gameId, $fileId);
        return $this->upsert($row);
    }

    /** Refresh an already staged V5 registration without consulting V4 metadata. */
    public function refreshExisting(int $gameId, int $fileId): array
    {
        $existing = $this->find($fileId);
        if (!is_array($existing) || (int)($existing['game_id'] ?? 0) !== $gameId) {
            throw new RuntimeException('UEDB5 dependency pass requires an existing staged V5 registration.');
        }
        return $this->upsert($this->inspect($gameId, $fileId));
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function upsert(array $row): array
    {
        $timestamp = gmdate('Y-m-d H:i:s');
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_files');
        $statement = $this->db->prepare(
            'INSERT INTO ue_uedb5_files('
            . 'file_id,game_id,format_version,codec,compressed_size,uncompressed_size,payload_sha256,'
            . 'block_count,package_family,source_policy,package_key_kind,package_key,package_name,'
            . 'section_counts_json,created_at,updated_at'
            . ') VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'game_id=VALUES(game_id),format_version=VALUES(format_version),codec=VALUES(codec),'
            . 'compressed_size=VALUES(compressed_size),uncompressed_size=VALUES(uncompressed_size),'
            . 'payload_sha256=VALUES(payload_sha256),block_count=VALUES(block_count),'
            . 'package_family=VALUES(package_family),source_policy=VALUES(source_policy),'
            . 'package_key_kind=VALUES(package_key_kind),package_key=VALUES(package_key),'
            . 'package_name=VALUES(package_name),section_counts_json=VALUES(section_counts_json),'
            . 'updated_at=VALUES(updated_at)'
        );
        $statement->execute([
            $row['file_id'], $row['game_id'], $row['format_version'], $row['codec'],
            $row['compressed_size'], $row['uncompressed_size'], $row['payload_sha256'],
            $row['block_count'], $row['package_family'], $row['source_policy'],
            $row['package_key_kind'], $row['package_key'], $row['package_name'],
            $row['section_counts_json'], $timestamp, $timestamp,
        ]);
        return $row + ['registered' => true, 'updated_at' => $timestamp];
    }
    public function remove(int $fileId): void
    {
        if ($fileId < 1) {
            return;
        }
        Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_files');
        $this->db->prepare('DELETE FROM ue_uedb5_files WHERE file_id=?')->execute([$fileId]);
    }

    /** @return array<string,mixed>|null */
    public function find(int $fileId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT file_id,game_id,format_version,codec,compressed_size,uncompressed_size,'
            . 'payload_sha256,block_count,package_family,source_policy,package_key_kind,package_key,'
            . 'package_name,section_counts_json,created_at,updated_at '
            . 'FROM ue_uedb5_files WHERE file_id=?'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $manifest @return array{0:int,1:string} */
    private function packageKey(int $gameId, int $fileId, array $manifest): array
    {
        $family = (string)($manifest['package_family'] ?? '');
        if ($family === Uedb5ZenPackageReader::PACKAGE_FAMILY) {
            $summary = $this->reader->page($gameId, $fileId, 'package_summary', 0, 1);
            $packageId = strtoupper(trim((string)($summary[0]['package_id'] ?? '')));
            if (preg_match('/^[0-9A-F]{16}$/', $packageId) !== 1) {
                throw new RuntimeException('Zen UEDB5 staging registration requires a valid FPackageId.');
            }
            $binary = hex2bin($packageId);
            if (!is_string($binary) || strlen($binary) !== 8) {
                throw new RuntimeException('Could not encode Zen FPackageId registration key.');
            }
            return [Uedb5SqlProjectionContract::PACKAGE_KEY_ZEN_PACKAGE_ID, $binary];
        }

        $packageName = (string)($manifest['file']['package_name'] ?? '');
        if ($packageName === '') {
            throw new RuntimeException('Classic UEDB5 staging registration requires a package name.');
        }
        $kind = Uedb5SqlProjectionContract::classicPackageKeyKindForImportSchema(
            (string)($manifest['section_schemas']['imports'] ?? '')
        );
        return [$kind, Uedb5SqlProjectionContract::classicPackageKeyBinary($packageName, $kind)];
    }

    private function assertVerifiedCatalogFile(int $gameId, int $fileId): void
    {
        $statement = $this->db->prepare(
            'SELECT game_id,scan_status FROM ue_files WHERE id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || (int)($row['game_id'] ?? 0) !== $gameId
            || (string)($row['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('UEDB5 registration requires the matching verified catalogue file.');
        }
    }
}
