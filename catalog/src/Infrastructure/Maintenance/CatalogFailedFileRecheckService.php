<?php
/**
 * Read-only revalidation of a failed catalogue file using the CURRENT
 * game-specific package scanner/reader. No registration, promotion or writes.
 * The known-invalid byte-identity record remains authoritative.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;

final class CatalogFailedFileRecheckService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /** @return array{ok:bool,source_available:bool,message:string,identity_ok:bool,details:array<string,mixed>} */
    public function recheck(int $fileId): array
    {
        $file = \catalog_one($this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status="failed"', [$fileId]);
        if (!$file) {
            throw new RuntimeException('This file is not currently a failed catalogue record.');
        }

        $path = CatalogFileMaintenanceSupport::storagePath($this->config, $file);
        if ($path === null || !is_file($path)) {
            return [
                'ok'=>false,'source_available'=>false,'identity_ok'=>false,
                'message'=>'The original stored bytes are unavailable. Restore or upload a corrected source before rescanning.',
                'details'=>[],
            ];
        }
        $size = @filesize($path);
        $md5 = @md5_file($path);
        $sha1 = @sha1_file($path);
        if ($size === false || $md5 === false || $sha1 === false
            || (int)$size !== (int)$file['file_size']
            || !hash_equals(strtolower((string)$file['md5']), strtolower($md5))
            || !hash_equals(strtolower((string)$file['sha1']), strtolower($sha1))) {
            return [
                'ok'=>false,'source_available'=>true,'identity_ok'=>false,
                'message'=>'Stored bytes do not match the original catalogue size/MD5/SHA-1. No rescan was attempted.',
                'details'=>['stored_size'=>$size],
            ];
        }

        try {
            $inspection = (new CatalogVerifiedPackageInspector($this->db,$this->config))->inspect(
                (int)$file['game_id'],
                $path,
                (string)$file['original_name'],
                true,
                (string)($file['source_relative_path'] ?? '')
            );
            return [
                'ok'=>true,'source_available'=>true,'identity_ok'=>true,
                'message'=>'The current engine-specific scanner accepted the stored package tables. '
                    . 'The historical failed status and known-invalid identity have NOT been changed; '
                    . 'review this evidence before any explicit repair/promotion.',
                'details'=>[
                    'reader_engine'=>$inspection->readerEngine,
                    'package_name'=>$inspection->packageName,
                    'name_count'=>$inspection->nameCount(),
                    'import_count'=>$inspection->importCount(),
                    'export_count'=>$inspection->exportCount(),
                ],
            ];
        } catch (Throwable $error) {
            return [
                'ok'=>false,'source_available'=>true,'identity_ok'=>true,
                'message'=>'The current package reader still rejects this source: '.$error->getMessage(),
                'details'=>[],
            ];
        }
    }
}
