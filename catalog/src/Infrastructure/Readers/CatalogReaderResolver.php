<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogReaderResolver` for catalog reader resolver.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

/**
 * Maps a detected engine generation to the reader used by catalog inventory.
 *
 * UE1 and UE2 use one canonical memory-bounded parser implementation:
 * CatalogLegacyPackageReader.php. "Legacy" here means the UE1/UE2 serialized
 * package family, not a deprecated/alternate reader. Every catalog path should
 * resolve UE1/UE2 through this class resolver rather than instantiate another
 * package parser directly.
 *
 * UE3 uses the strict Epic catalog parser. UE4 and UE5 use their dedicated
 * configured engine readers; UE5 must never alias the UE4 reader because its
 * package-summary/import/export version gates differ.
 */
final class CatalogReaderResolver
{
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

        if (in_array($engineKey, ['UE1', 'UE2'], true)) {
            $streamingReader = realpath(__DIR__ . '/CatalogLegacyPackageReader.php');
            if ($streamingReader === false || !is_file($streamingReader)) {
                throw new \RuntimeException($notFoundMessagePrefix . ' ' . $engineKey . ': CatalogLegacyPackageReader.php');
            }
            require_once $streamingReader;
            $className = $engineKey === 'UE1'
                ? __NAMESPACE__ . '\\CatalogUE1PackageReader'
                : __NAMESPACE__ . '\\CatalogUE2PackageReader';
            if (!class_exists($className, false)) {
                throw new \RuntimeException($missingClassMessagePrefix . $engineKey . ', but the streaming catalog reader was not defined.');
            }
            return $className;
        }

        if ($engineKey === 'UE3') {
            $catalogReader = realpath(__DIR__ . '/../../../parsers/UE3CatalogReader.php');
            if ($catalogReader === false || !is_file($catalogReader)) {
                throw new \RuntimeException($notFoundMessagePrefix . ' UE3: catalog/parsers/UE3CatalogReader.php');
            }
            require_once $catalogReader;
            if (!class_exists('CatalogUE3PackageReader', false)) {
                throw new \RuntimeException($missingClassMessagePrefix . 'UE3, but CatalogUE3PackageReader was not defined.');
            }
            return 'CatalogUE3PackageReader';
        }

        if ($engineKey === 'UE5') {
            $canonical = realpath(__DIR__ . '/../../../../UE5/UnrealPackageReader.php');
            if ($canonical === false || !is_file($canonical)) {
                throw new \RuntimeException($notFoundMessagePrefix . ' UE5: UE5/UnrealPackageReader.php');
            }
            require_once $canonical;
            if (!class_exists('UnrealPackageReader5', false)) {
                throw new \RuntimeException($missingClassMessagePrefix . 'UE5, but UnrealPackageReader5 was not defined.');
            }
            return 'UnrealPackageReader5';
        }
        $readerConfig = $config['engine_readers'][$engineKey] ?? [];
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
        $candidates[] = $engineKey === 'UE5'
            ? 'UnrealPackageReader5'
            : (in_array($engineKey, $versionedReaderEngines, true) ? 'UnrealPackageReader4' : 'UnrealPackageReader');
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
