<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Audits runtime references to physically retired metadata tables.
 * Why: Current catalogue reads use compact metadata/projections exclusively; normal runtime PHP must never reference retired storage.
 * Role: Application maintenance audit enforcing the compact-only runtime boundary.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Maintenance;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class LegacyMetadataRuntimeAudit
{
    /** @var list<string> */
    private const TABLES = [
        'ue_names',
        'ue_imports',
        'ue_exports',
        'ue_dependencies',
        'ue_search_documents',
    ];

    /** @return array{files:int,references:int,matches:list<array<string,mixed>>} */
    public static function scan(string $catalogRoot): array
    {
        $catalogRoot = realpath($catalogRoot) ?: '';
        if ($catalogRoot === '' || !is_dir($catalogRoot)) {
            throw new RuntimeException('Catalog source root is unavailable for legacy metadata audit.');
        }

        $matches = [];
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($catalogRoot, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || !$item->isFile()) {
                continue;
            }
            $path = $item->getPathname();
            if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($path, strlen($catalogRoot) + 1));
            if (self::excluded($relative)) {
                continue;
            }

            $lines = @file($path, FILE_IGNORE_NEW_LINES);
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $index => $line) {
                $trimmed = trim((string)$line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }
                foreach (self::TABLES as $table) {
                    if (!preg_match('/\b' . preg_quote($table, '/') . '\b/i', $line)) {
                        continue;
                    }
                    $files[$relative] = true;
                    $matches[] = [
                        'file' => $relative,
                        'line' => $index + 1,
                        'table' => $table,
                        'operation' => self::operation($line),
                        'snippet' => function_exists('mb_substr')
                            ? mb_substr(trim($line), 0, 300)
                            : substr(trim($line), 0, 300),
                    ];
                }
            }
        }

        usort($matches, static function (array $left, array $right): int {
            return strcmp((string)$left['file'], (string)$right['file'])
                ?: ((int)$left['line'] <=> (int)$right['line'])
                ?: strcmp((string)$left['table'], (string)$right['table']);
        });

        return [
            'files' => count($files),
            'references' => count($matches),
            'matches' => $matches,
        ];
    }

    private static function excluded(string $relative): bool
    {
        if ($relative === 'src/Application/Maintenance/LegacyMetadataRuntimeAudit.php') {
            return true;
        }
        if (str_starts_with($relative, 'bin/verify-')) {
            return true;
        }
        foreach (['migrations/', 'tests/', 'storage/', 'vendor/'] as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return false;
    }

    private static function operation(string $line): string
    {
        $line = strtoupper($line);
        foreach (['INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'ALTER', 'DROP'] as $operation) {
            if (str_contains($line, $operation)) {
                return strtolower($operation);
            }
        }
        return 'read';
    }
}
