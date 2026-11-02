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
 * UE1 and UE2 have separate canonical reader entry points:
 * CatalogUE1PackageReader.php and CatalogUE2PackageReader.php. They share
 * no low-level or high-level parser code; each engine owns its byte IO,
 * parser and version gates. No profile may silently fall back to the other.
 *
 * UE3 uses the strict Epic catalog parser. UE4 and UE5 use their dedicated
 * configured engine readers; UE5 must never alias the UE4 reader because its
 * package-summary/import/export version gates differ.
 */
final class CatalogReaderResolver
{
    /**
     * Select the selected game's loader, not the heuristic engine hint of a
     * legacy package. Epic's UE2 loaders can read version-60+ UE1 wire files.
     * UT2004 has a separate, UE2-owned compatibility parser for the profile's
     * explicitly UE1-selected pre-v100 format; no UE1 parser is imported.
     *
     * @param array<string,mixed> $config
     * @return array{0:string,1:string} [reader class, owning engine]
     */
    public static function resolveForGame(
        array $config,
        string $sourceKey,
        string $profileEngine,
        string $classifiedEngine,
        ?int $version
    ): array {
        $sourceKey = strtolower(trim($sourceKey));
        $profileEngine = strtoupper(trim($profileEngine));
        $classifiedEngine = strtoupper(trim($classifiedEngine));
        // Original UE1 packages do not become UE2 packages because an older
        // version-band heuristic calls the archive UE2. The selected game's
        // loader owns the bytes; only an explicitly supported compatibility
        // overlay may select a different wire-format reader.
        if ($profileEngine === 'UE1' && in_array($sourceKey, ['unrealgold', 'ut99'], true)) {
            return [self::resolve($config, 'UE1', 'Reader not found for package engine',
                'Reader file loaded for package engine ', ['UE4', 'UE5']), 'UE1'];
        }
        if ($profileEngine === 'UE2'
            && in_array($sourceKey, ['unreal2', 'ut2003', 'ut2004'], true)) {
            // Earlier versions trigger Epic's interactive OldVersion prompt;
            // noninteractive cataloguing cannot assume the user accepted it.
            if ($version === null || $version < 60) {
                throw new \RuntimeException('UE2 source loader cannot automatically admit package versions below 60.');
            }
            if ($sourceKey === 'ut2004' && $classifiedEngine === 'UE1' && $version < 100) {
                return [__NAMESPACE__ . '\\CatalogUE2LegacyCompatibilityPackageReader', 'UE2'];
            }
            return [self::resolve($config, 'UE2', 'Reader not found for package engine',
                'Reader file loaded for package engine ', ['UE4', 'UE5']), 'UE2'];
        }
        return [self::resolve($config, $classifiedEngine, 'Reader not found for package engine',
            'Reader file loaded for package engine ', ['UE4', 'UE5']), $classifiedEngine];
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

        if (in_array($engineKey, ['UE1', 'UE2'], true)) {
            $readerFilename = $engineKey === 'UE1'
                ? 'CatalogUE1PackageReader.php'
                : 'CatalogUE2PackageReader.php';
            $readerFile = realpath(__DIR__ . '/' . $readerFilename);
            if ($readerFile === false || !is_file($readerFile)) {
                throw new \RuntimeException($notFoundMessagePrefix . ' ' . $engineKey . ': ' . $readerFilename);
            }
            require_once $readerFile;
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
