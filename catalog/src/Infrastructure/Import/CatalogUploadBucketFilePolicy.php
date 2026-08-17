<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Centralizes Upload Bucket filename, extension, redirect-wrapper and browser identity validation.
 * Why: The v2 chunk endpoint and worker paths must not duplicate profile/filename policy or scatter procedural profile helpers.
 * Role: Infrastructure compatibility policy around the established game-profile/redirect contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

final class CatalogUploadBucketFilePolicy
{
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar'];

    /** @var list<string>|null */
    private ?array $allowedExtensions = null;

    /** @var list<string>|null */
    private ?array $allowedPackageExtensions = null;

    private ?bool $pakContainerAllowed = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
    }

    /** @return list<string> */
    public function allowedPackageExtensions(): array
    {
        if ($this->allowedPackageExtensions !== null) {
            return $this->allowedPackageExtensions;
        }
        $extensions = [];
        foreach (\gp_all_profiles($this->db) as $profile) {
            foreach (\gp_extensions($profile) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                // PAK is a transport/container identity. It is deliberately not
                // a package-table extension even if an older profile listed it.
                if ($extension !== '' && $extension !== 'pak') {
                    $extensions[$extension] = true;
                }
            }
        }
        if ($extensions === []) {
            foreach (($this->config['allowed_extensions'] ?? []) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '' && $extension !== 'pak') {
                    $extensions[$extension] = true;
                }
            }
        }
        return $this->allowedPackageExtensions = array_keys($extensions);
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        if ($this->allowedExtensions !== null) {
            return $this->allowedExtensions;
        }
        $extensions = array_fill_keys($this->allowedPackageExtensions(), true);
        foreach (self::ARCHIVE_EXTENSIONS as $extension) {
            $extensions[$extension] = true;
        }
        if ($this->pakContainerAllowed()) {
            $extensions['pak'] = true;
        }
        return $this->allowedExtensions = array_keys($extensions);
    }

    public function cleanName(string $name, string $missingMessage = 'Chunked bucket upload filename is missing.'): string
    {
        $name = \catalog_clean_unreal_filename(basename(str_replace('\\', '/', trim($name))));
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException($missingMessage);
        }
        return $name;
    }

    public function validateName(string $name, bool $allowRedirectWrapper = true): void
    {
        if (($allowRedirectWrapper && $this->isRedirectWrapper($name)) || $this->isArchive($name)) {
            return;
        }
        if ($this->isPakContainer($name)) {
            if (!$this->pakContainerAllowed()) {
                throw new \InvalidArgumentException(
                    'PAK container upload requires at least one active UE4 or UE5 game profile.'
                );
            }
            return;
        }
        $extension = \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $this->allowedPackageExtensions();
        if ($allowed !== [] && !in_array($extension, $allowed, true)) {
            throw new \InvalidArgumentException(
                'Extension .' . ($extension !== '' ? $extension : '(none)')
                . ' is not allowed by any active game profile.'
            );
        }
    }

    public function isRedirectWrapper(string $name): bool
    {
        return \catalog_redirect_archive_is_supported_filename($name);
    }

    public function isArchive(string $name): bool
    {
        return in_array(
            \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION)),
            self::ARCHIVE_EXTENSIONS,
            true
        );
    }

    public function isPakContainer(string $name): bool
    {
        return \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION)) === 'pak';
    }

    private function pakContainerAllowed(): bool
    {
        if ($this->pakContainerAllowed !== null) {
            return $this->pakContainerAllowed;
        }
        foreach (\gp_all_profiles($this->db) as $profile) {
            if (in_array(strtoupper(trim((string)($profile['engine_key'] ?? ''))), ['UE4', 'UE5'], true)) {
                return $this->pakContainerAllowed = true;
            }
        }
        return $this->pakContainerAllowed = false;
    }

    /**
     * Normalize the filename produced by a redirect wrapper without applying
     * broad duplicate heuristics to ordinary package filenames.
     *
     * Download mirrors commonly rename a collision by appending a numeric marker
     * after the real package extension, for example Name.uax(1) or Name.uax (1).
     * That marker is transport/download noise: the package target is Name.uax.
     */
    public static function cleanRedirectOutputName(string $name): string
    {
        $name = basename(str_replace(["\0", '\\'], ['', '/'], trim($name)));
        $name = preg_replace(
            '/^(.*\.[A-Za-z0-9_]+)\s*\([0-9]+\)$/u',
            '$1',
            $name
        ) ?? $name;
        return \catalog_clean_unreal_filename($name);
    }

    /**
     * UZ2 and UZ3 never serialize a replacement filename, so their output name
     * is deterministically the wrapper name with the final redirect extension
     * removed. Classic UZ embeds the original filename and therefore cannot be
     * classified safely from the transport filename alone.
     */
    public static function deterministicRedirectOutputName(string $name): ?string
    {
        $name = basename(str_replace(["\0", '\\'], ['', '/'], trim($name)));
        $extension = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, ['uz2', 'uz3'], true)) {
            return null;
        }
        $suffix = '.' . $extension;
        $base = substr($name, 0, -strlen($suffix));
        if (!is_string($base) || trim($base) === '') {
            return null;
        }
        return self::cleanRedirectOutputName($base);
    }

    /** @param array<string,mixed> $source @return array{md5:string,sha1:string} */
    public function browserIdentity(array $source): array
    {
        $md5 = strtolower(trim((string)($source['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($source['sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \InvalidArgumentException(
                'A valid browser-calculated MD5 and SHA-1 are required for an uncompressed file.'
            );
        }
        return ['md5' => $md5, 'sha1' => $sha1];
    }
}
