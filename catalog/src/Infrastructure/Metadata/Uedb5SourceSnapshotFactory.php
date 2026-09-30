<?php
/** Rebuilds source-shaped UEDB5 snapshots directly from authoritative Unreal package bytes. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;

final class Uedb5SourceSnapshotFactory
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {}

    /** @return array{engine_key:string,min_version:int,max_version:int} */
    public function contract(string $slug): array
    {
        return match ($slug) {
            'ut99' => ['engine_key'=>'UE1','min_version'=>Uedb5Ut99SnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5Ut99SnapshotBuilder::MAX_VERSION],
            'unrealgold' => ['engine_key'=>'UE1','min_version'=>Uedb5UnrealSnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5UnrealSnapshotBuilder::MAX_VERSION],
            'unreal2' => ['engine_key'=>'UE2','min_version'=>Uedb5Unreal2SnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5Unreal2SnapshotBuilder::MAX_VERSION],
            'ut2003' => ['engine_key'=>'UE2','min_version'=>Uedb5Ut2003SnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5Ut2003SnapshotBuilder::MAX_VERSION],
            'ut2004' => ['engine_key'=>'UE2','min_version'=>Uedb5Ut2004SnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5Ut2004SnapshotBuilder::MAX_VERSION],
            'ut3' => ['engine_key'=>'UE3','min_version'=>Uedb5Ut3SnapshotBuilder::PACKAGE_VERSION,'max_version'=>Uedb5Ut3SnapshotBuilder::PACKAGE_VERSION],
            'ut4' => ['engine_key'=>'UE4','min_version'=>Uedb5Ut4SnapshotBuilder::MIN_VERSION,'max_version'=>Uedb5Ut4SnapshotBuilder::MAX_VERSION],
            'ue5' => ['engine_key'=>'UE5','min_version'=>1000,'max_version'=>1018],
            default => throw new RuntimeException('No UEDB5 source snapshot builder is registered for game ' . $slug . '.'),
        };
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public function build(string $gameSlug, string $path, array $file): array
    {
        $contract = $this->contract($gameSlug);
        $engineKey = (string)$contract['engine_key'];
        $readerClass = CatalogReaderResolver::resolve(
            $this->config,
            $engineKey,
            'Reader not found for package engine',
            'Reader file loaded for package engine ',
            ['UE4','UE5']
        );
        if ($engineKey === 'UE4' || $engineKey === 'UE5') {
            $this->applyProfile($gameSlug, (int)($file['game_id'] ?? 0), $engineKey);
        }
        $reader = new $readerClass($path);

        return match ($gameSlug) {
            'ut99' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader
                ? Uedb5Ut99SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT99 did not resolve the canonical UE1 reader.'),
            'unrealgold' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader
                ? Uedb5UnrealSnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('Unreal did not resolve the canonical UE1 reader.'),
            'unreal2' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Unreal2SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('Unreal II did not resolve the canonical UE2 reader.'),
            'ut2003' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Ut2003SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT2003 did not resolve the canonical UE2 reader.'),
            'ut2004' => $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2PackageReader
                ? Uedb5Ut2004SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT2004 did not resolve the canonical UE2 reader.'),
            'ut3' => $reader instanceof \CatalogUE3PackageReader
                ? Uedb5Ut3SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT3 did not resolve the canonical UE3 reader.'),
            'ut4' => $reader instanceof \UnrealPackageReader4
                ? Uedb5Ut4SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT4 did not resolve the canonical UE4 reader.'),
            'ue5' => $reader instanceof \UnrealPackageReader5
                ? Uedb5Ue5ClassicSnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UE5 classic did not resolve the canonical UE5 reader.'),
            default => throw new RuntimeException('No UEDB5 source snapshot builder is registered for game ' . $gameSlug . '.'),
        };
    }

    private function applyProfile(string $gameSlug, int $gameId, string $engineKey): void
    {
        if ($gameId < 1 || !function_exists('gp_required_profile_for_game')) {
            throw new RuntimeException('Game parser-profile helper is unavailable for UEDB5 source parsing.');
        }
        $game = $this->game($gameSlug);
        $profile = \gp_required_profile_for_game($this->db, $gameId);
        if ($engineKey === 'UE4') {
            if (!function_exists('catalog_ue4_reader_options') || !function_exists('catalog_ue4_set_next_reader_options')) {
                throw new RuntimeException('UE4 parser-profile helpers are unavailable for UEDB5 source parsing.');
            }
            \catalog_ue4_set_next_reader_options(\catalog_ue4_reader_options($this->config, $game, $profile));
            return;
        }
        if (!function_exists('catalog_ue5_reader_options') || !function_exists('catalog_ue5_set_next_reader_options')) {
            throw new RuntimeException('UE5 parser-profile helpers are unavailable for UEDB5 source parsing.');
        }
        \catalog_ue5_set_next_reader_options(\catalog_ue5_reader_options($this->config, $game, $profile));
    }

    /** @return array<string,mixed> */
    private function game(string $slug): array
    {
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1');
        $statement->execute([trim($slug)]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unknown game slug: ' . $slug);
        }
        return $row;
    }
}
