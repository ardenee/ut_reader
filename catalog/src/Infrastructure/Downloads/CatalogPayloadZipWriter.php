<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Writes and validates generated dependency/UT3 payload ZIP archives.
 * Why: ZIP file I/O and payload-only validation are archive-format concerns separate from planning and descriptors.
 * Role: Active downloads infrastructure writer for payload-only ZIP exports.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use RuntimeException;
use ZipArchive;

final class CatalogPayloadZipWriter
{
    /** @return array<string,mixed> */
    public function write(string $outputPath, array $plan): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive is required for ZIP package exports.');
        }

        $zip = new ZipArchive();
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the package ZIP.');
        }

        $closed = false;
        try {
            foreach ($plan['files'] as $file) {
                if (!$zip->addFile((string)$file['storage_path'], (string)$file['install_path'])) {
                    throw new RuntimeException(
                        'Could not add ' . $file['original_name'] . ' to the ZIP.'
                    );
                }
            }
            $closed = $zip->close();
        } finally {
            if (!$closed) {
                @$zip->close();
            }
        }
        if (!$closed) {
            throw new RuntimeException('Could not finalize the package ZIP.');
        }

        $validation = $this->validate($outputPath, $plan);
        if (empty($validation['ok'])) {
            throw new RuntimeException(
                'Generated ZIP validation failed: '
                . implode('; ', (array)$validation['errors'])
            );
        }
        return $validation;
    }

    /** @return array<string,mixed> */
    public function validate(string $path, array $plan): array
    {
        $errors = [];
        if (!class_exists(ZipArchive::class)) {
            return ['ok' => false, 'errors' => ['ZipArchive unavailable'], 'file_count' => 0];
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [
                'ok' => false,
                'errors' => ['Could not reopen generated ZIP'],
                'file_count' => 0,
            ];
        }
        try {
            if ($zip->numFiles !== count($plan['files'])) {
                $errors[] = 'ZIP contains files other than the selected package payload.';
            }
            foreach ($plan['files'] as $file) {
                $stat = $zip->statName((string)$file['install_path']);
                if ($stat === false) {
                    $errors[] = 'Missing entry ' . $file['install_path'];
                } elseif ((int)$stat['size'] !== (int)$file['file_size']) {
                    $errors[] = 'Size mismatch for ' . $file['install_path'];
                }
            }
            foreach (['UnrealDB-Mod.json', 'Readme.txt'] as $unwanted) {
                if ($zip->locateName($unwanted, ZipArchive::FL_NOCASE) !== false) {
                    $errors[] = 'Unexpected generated text file ' . $unwanted;
                }
            }
        } finally {
            $zip->close();
        }
        return [
            'ok' => !$errors,
            'errors' => $errors,
            'file_count' => count($plan['files']),
        ];
    }
}
