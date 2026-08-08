<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Derives canonical package identity from a mounted source-relative path.
 * Why: UE4/UE5 source identity naming is pure policy and should not be coupled to mutation/reconciliation logic.
 * Role: Infrastructure naming policy shared by source-identity audit and repair.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Identity;

final class CatalogSourceIdentityNaming
{
    public function __construct()
    {
        require_once dirname(__DIR__, 3) . '/lib/Scanner/CatalogScannerPath.php';
    }

    public function path(string $sourceRelativePath): string
    {
        $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);
        if ($sourceRelativePath === '') {
            return '';
        }
        return preg_replace('/\.(uz|uz2|uz3)$/i', '', $sourceRelativePath) ?? $sourceRelativePath;
    }

    public function packageName(
        string $engineKey,
        string $sourceRelativePath,
        string $originalName = ''
    ): string {
        $engineKey = strtoupper(trim($engineKey));
        $sourceRelativePath = $this->path($sourceRelativePath);
        if (in_array($engineKey, ['UE4', 'UE5'], true)) {
            return \scanner_ue_package_name_from_source_relative($sourceRelativePath);
        }
        $sourceOriginalName = \scanner_original_name_from_source_relative($sourceRelativePath);
        return \scanner_logical_package_name(
            $sourceOriginalName !== '' ? $sourceOriginalName : $originalName
        );
    }

    /** @param list<string> $names @return list<string> */
    public function normalizedNames(array $names): array
    {
        $normalized = [];
        foreach ($names as $name) {
            $name = trim((string)$name);
            if ($name !== '') {
                $normalized[mb_strtolower($name, 'UTF-8')] = $name;
            }
        }
        ksort($normalized);
        return array_values($normalized);
    }
}
