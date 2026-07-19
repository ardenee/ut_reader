<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

/**
 * Infrastructure adapter that maps a configured engine profile to the existing
 * reader implementation. UE1 and UE2 legacy readers both declare a global
 * UnrealPackageReader class, so catalog pages that inspect mixed-engine files
 * must load them into separate runtime namespaces.
 */
final class CatalogReaderResolver
{
    /**
     * Legacy UE1/UE2 FString entries occasionally contain padding or obfuscation
     * bytes after the first null terminator while still declaring the full byte
     * count. Unreal treats the first null as the end of the ANSI name. The old
     * PHP readers removed only a final null byte, allowing those trailing bytes
     * into object names and generated paths.
     */
    private static function normalizeLegacyReaderSource(string $source, string $engineKey): string
    {
        if (!in_array($engineKey, ['UE1', 'UE2'], true)) {
            return $source;
        }

        $old = <<<'PHP'
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            return self::toUtf8($raw);
PHP;
        $new = <<<'PHP'
            $raw = $this->bytes($length);
            $terminator = strpos($raw, "\0");
            if ($terminator !== false) {
                $raw = substr($raw, 0, $terminator);
            }
            return self::toUtf8($raw);
PHP;

        $updated = str_replace($old, $new, $source, $replacementCount);
        if ($replacementCount !== 1) {
            throw new \RuntimeException('Legacy package reader string layout changed for ' . $engineKey . '.');
        }
        return $updated;
    }

    /**
     * Load a legacy UE1/UE2 source reader into an internal namespace instead
     * of including it globally. This leaves the standalone reader pages
     * unchanged while allowing one catalog request to analyse both engines.
     *
     * @param array<string, mixed> $config
     */
    private static function loadIsolatedLegacyReader(array $config, string $engineKey, string $notFoundMessagePrefix): string
    {
        $readerConfig = $config['engine_readers'][$engineKey] ?? [];
        $relativePath = (string)($readerConfig['reader'] ?? '');
        $readerPath = realpath(__DIR__ . '/../../../' . $relativePath);
        if ($readerPath === false || !is_file($readerPath)) {
            throw new \RuntimeException($notFoundMessagePrefix . ' ' . $engineKey . ': ' . $relativePath);
        }

        $runtimeNamespace = __NAMESPACE__ . '\\RuntimeLegacy\\' . $engineKey;
        $runtimeClass = $runtimeNamespace . '\\UnrealPackageReader';
        if (class_exists($runtimeClass, false)) {
            return $runtimeClass;
        }

        $source = @file_get_contents($readerPath);
        if (!is_string($source) || $source === '') {
            throw new \RuntimeException('Could not read legacy package reader for ' . $engineKey . '.');
        }

        $source = preg_replace('/^(?:\xEF\xBB\xBF)?\s*<\?php\s*/', '', $source, 1) ?? $source;
        $source = preg_replace('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;\s*/', '', $source, 1) ?? $source;
        $source = preg_replace('/\?>\s*$/', '', $source) ?? $source;
        $source = self::normalizeLegacyReaderSource($source, $engineKey);

        /*
         * Legacy reader files use unqualified RuntimeException and
         * OutOfBoundsException. Imports keep those references pointing to PHP's
         * global exception classes after the source is evaluated in a namespace.
         */
        $isolatedSource = 'namespace ' . $runtimeNamespace . ';'
            . 'use \\RuntimeException;'
            . 'use \\OutOfBoundsException;'
            . 'use \\InvalidArgumentException;'
            . 'use \\LogicException;'
            . 'use \\Throwable;'
            . "\n"
            . $source;

        try {
            eval($isolatedSource);
        } catch (\ParseError $error) {
            throw new \RuntimeException('Could not isolate legacy package reader for ' . $engineKey . ': ' . $error->getMessage(), 0, $error);
        }

        if (!class_exists($runtimeClass, false)) {
            throw new \RuntimeException('Isolated legacy package reader did not define UnrealPackageReader for ' . $engineKey . '.');
        }

        return $runtimeClass;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $versionedReaderEngines
     */
    public static function resolve(
        array $config,
        string $engineKey,
        string $notFoundMessagePrefix,
        string $missingClassMessagePrefix,
        array $versionedReaderEngines,
        bool $normalizeEngineKey = true
    ): string {
        if ($normalizeEngineKey) {
            $engineKey = strtoupper(trim($engineKey));
        }
        $readerConfig = $config['engine_readers'][$engineKey] ?? [];

        if (in_array($engineKey, ['UE1', 'UE2'], true)) {
            return self::loadIsolatedLegacyReader($config, $engineKey, $notFoundMessagePrefix);
        }

        if ($engineKey === 'UE3') {
            $catalogReader = realpath(__DIR__ . '/../../../parsers/UE3CatalogReader.php');
            if ($catalogReader !== false && is_file($catalogReader)) {
                require_once $catalogReader;
                if (class_exists('CatalogUE3PackageReader', false)) {
                    return 'CatalogUE3PackageReader';
                }
            }
        }

        $relativePath = (string)($readerConfig['reader'] ?? '');
        $readerPath = realpath(__DIR__ . '/../../../' . $relativePath);
        if ($readerPath === false || !is_file($readerPath)) {
            throw new \RuntimeException($notFoundMessagePrefix . ' ' . $engineKey . ': ' . $relativePath);
        }

        require_once $readerPath;

        $candidates = [];
        if (!empty($readerConfig['class'])) {
            $candidates[] = (string)$readerConfig['class'];
        }
        $candidates[] = in_array($engineKey, $versionedReaderEngines, true)
            ? 'UnrealPackageReader4'
            : 'UnrealPackageReader';
        $candidates[] = 'UnrealPackageReader';
        $candidates[] = 'UnrealPackageReader4';

        foreach (array_unique($candidates) as $className) {
            if ($className !== '' && class_exists($className, false)) {
                return $className;
            }
        }

        throw new \RuntimeException($missingClassMessagePrefix . $engineKey . ', but no supported reader class was found.');
    }
}
