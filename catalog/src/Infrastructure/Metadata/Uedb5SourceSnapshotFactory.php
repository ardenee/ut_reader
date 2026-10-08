<?php
/** Rebuilds source-shaped UEDB5 snapshots directly from authoritative Unreal package bytes. */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;

final class Uedb5SourceSnapshotFactory
{
    /** @var array<int,array<string,mixed>> */
    private array $profileCache = [];

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
    }

    /** @return array{engine_key:string,min_version:?int,max_version:?int,profile_id:int,compatibility_rules_json:?string} */
    public function contractForGameId(int $gameId): array
    {
        $sourceKey = Uedb5GameSourceRegistry::sourceKey($gameId);
        $source = $this->contract($sourceKey);
        $profile = $this->profileForGame($gameId);
        $profileEngine = strtoupper(trim((string)($profile['engine_key'] ?? '')));
        if ($profileEngine !== (string)$source['engine_key']) {
            throw new RuntimeException(
                'Assigned game profile engine ' . ($profileEngine !== '' ? $profileEngine : 'UNKNOWN')
                . ' does not match UEDB5 source engine ' . (string)$source['engine_key']
                . ' for game_id=' . $gameId . '.'
            );
        }
        return [
            'engine_key' => $profileEngine,
            'min_version' => $profile['package_version_min'] !== null ? (int)$profile['package_version_min'] : null,
            'max_version' => $profile['package_version_max'] !== null ? (int)$profile['package_version_max'] : null,
            'profile_id' => (int)$profile['id'],
            'compatibility_rules_json' => $profile['compatibility_rules_json'] !== null
                ? (string)$profile['compatibility_rules_json'] : null,
        ];
    }

    /** @return array{engine_key:string} */
    public function contract(string $sourceKey): array
    {
        return match ($sourceKey) {
            'ut99', 'unrealgold' => ['engine_key'=>'UE1'],
            'unreal2', 'ut2003', 'ut2004' => ['engine_key'=>'UE2'],
            'ut3' => ['engine_key'=>'UE3'],
            'ut4' => ['engine_key'=>'UE4'],
            'ue5' => ['engine_key'=>'UE5'],
            default => throw new RuntimeException('No UEDB5 source snapshot builder is registered for source key ' . $sourceKey . '.'),
        };
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    public function buildForGameId(int $gameId, string $path, array $file): array
    {
        if (!$this->profileAllowsCatalogRow($gameId, $file)) {
            $version = array_key_exists('package_version', $file) && $file['package_version'] !== null
                ? (int)$file['package_version'] : null;
            throw new RuntimeException(
                'Package version is outside the active game profile for game_id=' . $gameId
                . ' (version=' . ($version ?? 'unknown') . ').'
            );
        }
        return $this->buildForSourceKey(Uedb5GameSourceRegistry::sourceKey($gameId), $gameId, $path, $file);
    }

    /** @param array<string,mixed> $file */
    public function profileAllowsCatalogRow(int $gameId, array $file): bool
    {
        $profile = $this->profileForGame($gameId);
        $source = $this->contract(Uedb5GameSourceRegistry::sourceKey($gameId));
        $version = array_key_exists('package_version', $file) && $file['package_version'] !== null
            ? (int)$file['package_version'] : null;
        $licensee = array_key_exists('licensee_version', $file) && $file['licensee_version'] !== null
            ? (int)$file['licensee_version'] : null;
        $engineKey = (string)$source['engine_key'];
        $decision = \gp_profile_version_decision(
            $profile,
            $version,
            $licensee,
            Uedb5GameSourceRegistry::sourceKey($gameId) === 'ut2004' && $version !== null && $version < 100 ? 'UE1' : $engineKey,
            in_array($engineKey, ['UE4', 'UE5'], true)
        );
        if (empty($decision['ok'])) {
            return false;
        }
        $compatibility = $decision['compatibility'] ?? null;
        return !is_array($compatibility)
            || strtoupper((string)($compatibility['reader_engine'] ?? '')) === $engineKey
            || (Uedb5GameSourceRegistry::sourceKey($gameId) === 'ut2004' && strtoupper((string)($compatibility['reader_engine'] ?? '')) === 'UE1');
    }

    /** Legacy slug entry point retained for compatibility; runtime migration uses game IDs. */
    public function build(string $gameSlug, string $path, array $file): array
    {
        $game = $this->gameBySlug($gameSlug);
        return $this->buildForGameId((int)$game['id'], $path, $file);
    }

    /** @param array<string,mixed> $file @return array<string,mixed> */
    private function buildForSourceKey(string $sourceKey, int $gameId, string $path, array $file): array
    {
        $contract = $this->contract($sourceKey);
        $engineKey = (string)$contract['engine_key'];
        $readerEngine = $engineKey;
        if ($sourceKey === 'ut2004' && isset($file['package_version']) && (int)$file['package_version'] < 100) {
            $decision = \gp_profile_version_decision(
                $this->profileForGame($gameId), (int)$file['package_version'],
                isset($file['licensee_version']) ? (int)$file['licensee_version'] : null, 'UE1'
            );
            if (!empty($decision['ok']) && strtoupper((string)($decision['compatibility']['reader_engine'] ?? '')) === 'UE1') {
                $readerEngine = 'UE1';
            }
        }
        $readerClass = CatalogReaderResolver::resolve(
            $this->config,
            $readerEngine,
            'Reader not found for package engine',
            'Reader file loaded for package engine ',
            ['UE4','UE5']
        );
        if ($engineKey === 'UE4' || $engineKey === 'UE5') {
            $this->applyProfile($gameId, $engineKey);
        }
        $reader = $sourceKey === 'unrealgold'
            && $readerClass === \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader::class
            ? new $readerClass($path, true)
            : new $readerClass($path);
        if (!method_exists($reader, 'getHeader')) {
            throw new RuntimeException('Canonical package reader does not expose its parsed header for profile validation.');
        }
        $this->assertParsedHeaderAllowed($gameId, $readerEngine, (array)$reader->getHeader());

        return match ($sourceKey) {
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
                : ($readerEngine === 'UE1' && $reader instanceof \UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader
                    ? Uedb5LegacySnapshotBuilder::build($reader, $file, [
                        'label'=>'UT2004 UE1 compatibility',
                        'policy'=>'ue1-ut2004-legacy-texture-profile-compatible',
                        'schema_prefix'=>'ue1.ut2004.compat',
                    ])
                    : throw new RuntimeException('UT2004 did not resolve the profile-authorized package reader.')),
            'ut3' => $reader instanceof \CatalogUE3PackageReader
                ? Uedb5Ut3SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT3 did not resolve the canonical UE3 reader.'),
            'ut4' => $reader instanceof \UnrealPackageReader4
                ? Uedb5Ut4SnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UT4 did not resolve the canonical UE4 reader.'),
            'ue5' => $reader instanceof \UnrealPackageReader5
                ? Uedb5Ue5ClassicSnapshotBuilder::build($reader, $file)
                : throw new RuntimeException('UE5 classic did not resolve the canonical UE5 reader.'),
            default => throw new RuntimeException('No UEDB5 source snapshot builder is registered for source key ' . $sourceKey . '.'),
        };
    }

    /** @param array<string,mixed> $header */
    private function assertParsedHeaderAllowed(int $gameId, string $engineKey, array $header): void
    {
        $profile = $this->profileForGame($gameId);
        $signedPackageVersion = in_array($engineKey, ['UE4','UE5'], true);
        if ($engineKey === 'UE4') {
            $version = isset($header['legacyFileVersion']) ? (int)$header['legacyFileVersion'] : null;
            $licensee = isset($header['serializedLicenseeVersion']) ? (int)$header['serializedLicenseeVersion'] : null;
        } elseif ($engineKey === 'UE5') {
            $version = isset($header['serializedUE5Version']) ? (int)$header['serializedUE5Version'] : null;
            $licensee = isset($header['serializedLicenseeVersion']) ? (int)$header['serializedLicenseeVersion'] : null;
        } else {
            $version = isset($header['version']) ? (int)$header['version'] : null;
            $licensee = isset($header['licenseeVersion'])
                ? (int)$header['licenseeVersion']
                : (isset($header['licensee']) ? (int)$header['licensee'] : null);
        }
        $decision = \gp_profile_version_decision(
            $profile,
            $version,
            $licensee,
            $engineKey,
            $signedPackageVersion
        );
        if (empty($decision['ok'])) {
            throw new RuntimeException(
                'Parsed package version is outside the active game profile for game_id=' . $gameId
                . ' (version=' . ($version ?? 'unknown') . ', reason=' . (string)($decision['reason'] ?? 'rejected') . ').'
            );
        }
        $compatibility = $decision['compatibility'] ?? null;
        if (is_array($compatibility)
            && strtoupper((string)($compatibility['reader_engine'] ?? '')) !== $engineKey) {
            throw new RuntimeException('Active game profile compatibility rule selected a different package reader engine.');
        }
    }

    private function applyProfile(int $gameId, string $engineKey): void
    {
        if ($gameId < 1 || !function_exists('gp_required_profile_for_game')) {
            throw new RuntimeException('Game parser-profile helper is unavailable for UEDB5 source parsing.');
        }
        $game = $this->gameById($gameId);
        $profile = $this->profileForGame($gameId);
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
    private function profileForGame(int $gameId): array
    {
        if (!isset($this->profileCache[$gameId])) {
            $this->profileCache[$gameId] = \gp_required_profile_for_game($this->db, $gameId);
        }
        return $this->profileCache[$gameId];
    }

    /** @return array<string,mixed> */
    private function gameById(int $gameId): array
    {
        $statement = $this->db->prepare('SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1');
        $statement->execute([$gameId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Unknown game_id: ' . $gameId);
        }
        return $row;
    }

    /** @return array<string,mixed> */
    private function gameBySlug(string $slug): array
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
