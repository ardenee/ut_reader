<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines canonical source-relative path and import filename normalization.
 * Why: Upload, redirect and chunk staging paths must apply identical traversal/slash semantics.
 * Role: Infrastructure path policy shared by import/staging components.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogImportPathPolicy
{
    public static function relative(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    public static function filename(string $name, string $missingMessage = 'Import filename is missing.'): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException($missingMessage);
        }
        return $name;
    }

    public static function replaceFilename(string $relativePath, string $name): string
    {
        $relativePath = self::relative($relativePath);
        $directory = trim(str_replace('\\', '/', dirname($relativePath)), '. /');
        return ($directory !== '' ? $directory . '/' : '') . self::filename($name);
    }
}
