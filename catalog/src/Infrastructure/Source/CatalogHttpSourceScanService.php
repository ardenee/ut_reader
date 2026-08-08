<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Executes trusted HTTP/redirect source manifest scans and package matching.
 * Why: Manifest parsing, trusted network IO, deep GUID inspection and source-location persistence are Infrastructure concerns.
 * Role: Infrastructure source scanner preserving the existing HTTP scan behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpCurlTransport;
use UnrealDb\Catalog\Infrastructure\Http\TrustedHttpEndpointPolicy;

final class CatalogHttpSourceScanService
{
    private readonly TrustedHttpEndpointPolicy $endpointPolicy;
    private readonly TrustedHttpCurlTransport $http;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogParser.php';
        require_once $root . '/lib/CatalogScanner.php';
        require_once $root . '/lib/GameProfiles.php';
        $this->endpointPolicy = new TrustedHttpEndpointPolicy();
        $this->http = new TrustedHttpCurlTransport(false);
    }

    /** @return array<string,mixed> */
    public function run(
        int $sourceId,
        string $manifestName,
        bool $checkRemoteSize,
        bool $deepScan,
        int $maxDeepBytes
    ): array {
        $manifestName = trim($manifestName);
        if ($manifestName === '') {
            throw new RuntimeException('Manifest name/path is required.');
        }

        $source = \catalog_one(
            $this->db,
            'SELECT s.*, g.name game_name, p.engine_key profile_engine '
            . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?',
            [$sourceId]
        );
        if (!$source) {
            throw new RuntimeException('Source not found.');
        }
        if (!in_array($source['source_type'], ['http_mirror', 'redirect_server'], true)) {
            throw new RuntimeException('This scanner only accepts HTTP mirror and redirect-server sources.');
        }
        $profile = \gp_required_profile_for_game($this->db, (int)$source['game_id']);

        $target = $this->endpointPolicy->source((string)$source['base_path'], false);
        $manifestUrl = $this->endpointPolicy->relativeUrl($target, $manifestName);
        $manifest = $this->http->bytes($target, $manifestUrl, 5 * 1024 * 1024, 'manifest');
        $paths = $this->extractManifestPaths($manifest, $profile);
        if (count($paths) > 50000) {
            throw new RuntimeException('Manifest contains more than the 50,000 allowed package entries.');
        }

        $upsert = $this->db->prepare(
            'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
            . 'VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source),last_seen_at=NOW()'
        );
        $result = [
            'source' => $source,
            'manifest_url' => $manifestUrl,
            'path_count' => count($paths),
            'matched' => 0,
            'matched_guid' => 0,
            'unknown' => 0,
            'ambiguous' => 0,
            'deep_failed' => 0,
            'invalid_paths' => 0,
            'samples' => [],
        ];
        $deepLimit = 100;
        $deepUsed = 0;

        foreach ($paths as $relativePath) {
            try {
                $url = $this->endpointPolicy->relativeUrl($target, $relativePath);
            } catch (Throwable) {
                $result['invalid_paths']++;
                continue;
            }
            $remoteSize = $checkRemoteSize ? $this->http->headSize($target, $url) : null;
            $match = $this->matchFile($source, $relativePath, $remoteSize);

            if (!$match && $deepScan && $deepUsed < $deepLimit) {
                $deepUsed++;
                try {
                    $match = $this->deepGuidMatch($source, $target, $url, $maxDeepBytes);
                } catch (Throwable) {
                    $result['deep_failed']++;
                    if (count($result['samples']) < 50) {
                        $result['samples'][] = $relativePath . ' - deep scan failed';
                    }
                    continue;
                }
            }

            if ($match && isset($match['file']) && is_array($match['file'])) {
                $upsert->execute([(int)$match['file']['id'], $sourceId, $relativePath, 1]);
                \scanner_record_source_relative_path($this->db, (int)$match['file']['id'], $relativePath);
                if (($match['status'] ?? '') === 'matched_guid') {
                    $result['matched_guid']++;
                } else {
                    $result['matched']++;
                }
                continue;
            }
            if ($match && in_array($match['status'] ?? '', ['ambiguous', 'ambiguous_guid'], true)) {
                $result['ambiguous']++;
                if (count($result['samples']) < 50) {
                    $result['samples'][] = $relativePath . ' - ' . $match['status'];
                }
                continue;
            }

            $result['unknown']++;
            if (count($result['samples']) < 50) {
                $result['samples'][] = $relativePath . ' - ' . ($match['status'] ?? 'unknown');
            }
        }

        return $result;
    }

    private function allowedExtension(string $path, array $profile): bool
    {
        $ext = \catalog_clean_unreal_extension((string)pathinfo($path, PATHINFO_EXTENSION));
        $extensions = \gp_extensions($profile);
        if ($extensions === []) {
            $extensions = \scanner_profile_extensions($profile, $this->config);
        }
        return in_array($ext, $extensions, true);
    }

    private function cleanManifestLine(string $line): string
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            return '';
        }
        if (str_contains($line, ',')) {
            $parts = str_getcsv($line);
            $line = trim((string)($parts[0] ?? ''));
        }
        return trim($line, " \t\r\n\"'");
    }

    /** @return list<string> */
    private function extractManifestPaths(string $manifestText, array $profile): array
    {
        $paths = [];
        $trimmed = trim($manifestText);
        $json = json_decode($trimmed, true);
        $items = is_array($json)
            ? (array_is_list($json) ? $json : (is_array($json['files'] ?? null) ? $json['files'] : []))
            : preg_split('/\R/', $manifestText);

        foreach ($items ?: [] as $item) {
            $path = is_array($item)
                ? (string)($item['path'] ?? $item['file'] ?? $item['name'] ?? '')
                : (string)$item;
            $path = $this->cleanManifestLine($path);
            if ($path !== '' && $this->allowedExtension($path, $profile)) {
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    /** @return array<string,mixed>|null */
    private function matchFile(array $source, string $relativePath, ?int $remoteSize): ?array
    {
        $basename = basename($relativePath);
        $matches = $this->verifiedMatches((int)$source['game_id'], 'original_name=?', [$basename]);
        if (count($matches) === 1) {
            return ['status' => 'matched_name', 'file' => $matches[0]];
        }
        if (count($matches) > 1 && $remoteSize === null) {
            return ['status' => 'ambiguous', 'file' => null];
        }

        if ($remoteSize !== null) {
            $matches = $this->verifiedMatches(
                (int)$source['game_id'],
                'original_name=? AND file_size=?',
                [$basename, $remoteSize]
            );
            if (count($matches) === 1) {
                return ['status' => 'matched_name_size', 'file' => $matches[0]];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous', 'file' => null];
            }
        }

        $sourcePackage = \scanner_ue_package_name_from_source_relative($relativePath);
        if ($sourcePackage !== '') {
            $matches = $this->verifiedMatches((int)$source['game_id'], 'package_name=?', [$sourcePackage]);
            if (count($matches) === 1) {
                return ['status' => 'matched_source_package', 'file' => $matches[0]];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous', 'file' => null];
            }
        }

        $stem = pathinfo($basename, PATHINFO_FILENAME);
        if ($stem !== '') {
            $matches = $this->verifiedMatches((int)$source['game_id'], 'package_name=?', [$stem]);
            if (count($matches) === 1) {
                return ['status' => 'matched_package_name', 'file' => $matches[0]];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous', 'file' => null];
            }
        }
        return null;
    }

    /** @return list<array<string,mixed>> */
    private function verifiedMatches(int $gameId, string $predicate, array $args): array
    {
        return \catalog_all(
            $this->db,
            'SELECT id,package_name,original_name,file_size,md5,package_guid '
            . 'FROM ue_files WHERE game_id=? AND ' . $predicate . ' AND scan_status="verified" ORDER BY id',
            array_merge([$gameId], $args)
        );
    }

    /** @return array<string,mixed> */
    private function deepGuidMatch(array $source, array $target, string $url, int $maxBytes): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ue_http_scan_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary deep-scan file.');
        }
        @unlink($tmp);

        try {
            $this->http->toFile($target, $url, $tmp, $maxBytes, 'package');
            $engine = \gp_engine_for_game($this->db, (int)$source['game_id']);
            $header = \catalog_try_read_package_header($this->config, $engine, $tmp);
            $guid = \catalog_header_guid($header);
            if ($guid === '') {
                return ['status' => 'no_guid', 'file' => null, 'guid' => ''];
            }
            $matches = $this->verifiedMatches((int)$source['game_id'], 'package_guid=?', [$guid]);
            if (count($matches) === 1) {
                return ['status' => 'matched_guid', 'file' => $matches[0], 'guid' => $guid];
            }
            if (count($matches) > 1) {
                return ['status' => 'ambiguous_guid', 'file' => null, 'guid' => $guid];
            }
            return ['status' => 'unknown_guid', 'file' => null, 'guid' => $guid];
        } finally {
            @unlink($tmp);
        }
    }
}
