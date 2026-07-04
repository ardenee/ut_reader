<?php
declare(strict_types=1);

/**
 * Resolves the implementation class for an Unreal package engine.
 *
 * This keeps the legacy reader configuration format and global reader class
 * names intact while providing one audited place for path validation and
 * selection order. Callers can retain their existing error wording and
 * engine-key normalization rules through explicit arguments.
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
        $readerConfig = $config['engine_readers'][$engineKey] ?? [];

        if ($engineKey === 'UE3') {
            $catalogReader = realpath(__DIR__ . '/../parsers/UE3CatalogReader.php');
            if ($catalogReader !== false && is_file($catalogReader)) {
                require_once $catalogReader;
                if (class_exists('CatalogUE3PackageReader', false)) {
                    return 'CatalogUE3PackageReader';
                }
            }
        }

        $relativePath = (string)($readerConfig['reader'] ?? '');
        $readerPath = realpath(__DIR__ . '/../' . $relativePath);
        if ($readerPath === false || !is_file($readerPath)) {
            throw new RuntimeException($notFoundMessagePrefix . ' ' . $engineKey . ': ' . $relativePath);
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

        throw new RuntimeException($missingClassMessagePrefix . $engineKey . ', but no supported reader class was found.');
    }
}
