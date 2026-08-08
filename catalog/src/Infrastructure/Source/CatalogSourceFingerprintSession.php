<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns optional source-file fingerprint cache probing, lookup and persistence for one source scan.
 * Why: Fingerprint availability/error accounting and cache bookkeeping should not obscure package matching/import orchestration.
 * Role: Infrastructure source-scan collaborator over PdoSourceFileFingerprintCache; cache failures remain non-fatal.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoSourceFileFingerprintCache;

final class CatalogSourceFingerprintSession
{
    private readonly PdoSourceFileFingerprintCache $cache;
    private bool $available = false;
    private int $writes = 0;
    private int $errors = 0;

    public function __construct(PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->cache = new PdoSourceFileFingerprintCache($db);
        try {
            $this->available = $this->cache->isAvailable();
        } catch (Throwable $error) {
            $this->errors++;
            error_log('[UnrealDB source fingerprint availability] ' . $error->getMessage());
        }
    }

    public function available(): bool
    {
        return $this->available;
    }

    /**
     * @return array{probe:array{file_size:int,modified_at:int,quick_fingerprint:string}|null,cached:array<string,mixed>|null}
     */
    public function probeAndLookup(string $path, int $sourceId, string $relativePath): array
    {
        if (!$this->available) {
            return ['probe' => null, 'cached' => null];
        }

        try {
            $probe = $this->cache->probe($path);
            return [
                'probe' => $probe,
                'cached' => $this->cache->lookup($sourceId, $relativePath, $probe),
            ];
        } catch (Throwable $error) {
            $this->errors++;
            error_log('[UnrealDB source fingerprint probe] ' . $error->getMessage());
            return ['probe' => null, 'cached' => null];
        }
    }

    /** @return array<string,mixed>|null */
    public function resolveVerifiedFile(array $cached, int $gameId): ?array
    {
        return $this->cache->resolveVerifiedFile($cached, $gameId);
    }

    /** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
    public function cachedWork(string $path, array $cached): array
    {
        $redirect = (int)($cached['is_redirect'] ?? 0) === 1;
        $name = trim((string)($cached['work_name'] ?? ''));
        if ($name === '') {
            $name = \catalog_clean_unreal_filename(basename($path));
        }
        return [
            'path' => $path,
            'name' => $name,
            'temp' => false,
            'redirect' => $redirect,
            'source_extension' => $redirect
                ? strtolower((string)pathinfo($path, PATHINFO_EXTENSION))
                : '',
        ];
    }

    /**
     * @param array{file_size:int,modified_at:int,quick_fingerprint:string}|null $probe
     * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
     * @param array<string,mixed>|null $file
     */
    public function remember(
        int $sourceId,
        string $relativePath,
        ?array $probe,
        array $work,
        ?string $md5,
        ?string $sha1,
        ?string $guid,
        ?array $file,
        ?string $method
    ): void {
        if (!$this->available || $probe === null) {
            return;
        }

        try {
            $this->cache->remember(
                $sourceId,
                $relativePath,
                $probe,
                (string)$work['name'],
                (bool)$work['redirect'],
                $md5,
                $sha1,
                $guid,
                $file,
                $method
            );
            $this->writes++;
        } catch (Throwable $error) {
            $this->errors++;
            error_log('[UnrealDB source fingerprint] ' . $error->getMessage());
        }
    }

    /** @param array<string,int> $counters */
    public function applyCounters(array &$counters): void
    {
        $counters['fingerprints_written'] = $this->writes;
        $counters['fingerprint_errors'] = $this->errors;
    }
}
