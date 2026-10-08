<?php
declare(strict_types=1);
namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/** Read-only dependency candidate diagnostics from authoritative UEDB5. */
final class Uedb5DependencyExportDiagnostics
{
    /** @return list<array<string,mixed>> */
    public static function candidates(PDO $db, int $fileId, string $objectName): array
    {
        $rows = self::exports($db, $fileId);
        $hits = [];
        foreach ($rows as $row) {
            if (strcasecmp((string)$row['object_name'], $objectName) !== 0) {
                continue;
            }
            $class = (string)$row['class_name'];
            $split = strrpos($class, '.');
            $row['class_package'] = $split === false ? '' : substr($class, 0, $split);
            $row['class_name'] = $split === false ? $class : substr($class, $split + 1);
            unset($row['full_path'], $row['local_path'], $row['serial_size'], $row['serial_offset']);
            $hits[] = $row;
        }
        usort($hits, static fn(array $a, array $b): int => (int)$b['export_index'] <=> (int)$a['export_index']);
        return array_slice($hits, 0, 20);
    }

    public static function outerPath(PDO $db, int $fileId, int $outerIndex, string $package): string
    {
        if ($outerIndex === 0) {
            return trim($package);
        }
        $rows = self::exports($db, $fileId);
        $byIndex = [];
        foreach ($rows as $row) {
            $byIndex[(int)$row['export_index']] = $row;
        }
        $parts = [];
        $seen = [];
        $current = $outerIndex;
        for ($guard = 0; $current > 0 && $guard < 64; $guard++) {
            $exportIndex = $current - 1;
            if (isset($seen[$exportIndex])) {
                break;
            }
            $seen[$exportIndex] = true;
            if (!isset($byIndex[$exportIndex])) {
                $parts[] = '[export #' . $exportIndex . ']';
                break;
            }
            array_unshift($parts, (string)$byIndex[$exportIndex]['object_name']);
            $current = (int)$byIndex[$exportIndex]['outer_index'];
        }
        return implode('.', array_values(array_filter(array_merge([trim($package)], $parts), static fn(string $v): bool => $v !== '')));
    }

    /** @return list<array<string,mixed>> */
    private static function exports(PDO $db, int $fileId): array
    {
        $statement = $db->prepare('SELECT game_id FROM ue_uedb5_files WHERE file_id=? AND format_version=5 LIMIT 1');
        $statement->execute([$fileId]);
        $gameId = (int)$statement->fetchColumn();
        if ($gameId < 1) {
            throw new RuntimeException('UEDB5 registration missing for provider #' . $fileId);
        }
        $config = function_exists('catalog_config') ? \catalog_config() : [];
        $root = trim((string)($config['storage_path'] ?? ''));
        if ($root === '') {
            throw new RuntimeException('UEDB5 storage root missing.');
        }
        $snapshot = (new Uedb5MetadataReader($root))->snapshot($gameId, $fileId);
        return Uedb5UpkExportRows::fromSnapshot($snapshot);
    }
}