<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Readers;

/**
 * Maps a detected engine generation to the reader used by catalog inventory.
 *
 * UE1 and UE2 previously loaded the standalone readers by reading their PHP
 * source into memory, rewriting it and evaluating it inside runtime namespaces.
 * Those readers then loaded the complete package into another PHP string. Large
 * packages could therefore exhaust a 128 MiB detached worker before inventory
 * reached the Names/Imports/Exports stages.
 *
 * Catalog inventory now uses direct, memory-bounded streaming readers for UE1
 * and UE2. Standalone reader pages remain unchanged.
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

        $readerConfig = $config['engine_readers'][$engineKey] ?? [];
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
