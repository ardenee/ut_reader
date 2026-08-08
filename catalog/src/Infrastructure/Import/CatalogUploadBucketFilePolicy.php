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
    /** @var list<string>|null */
    private ?array $allowedExtensions = null;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        if ($this->allowedExtensions !== null) {
            return $this->allowedExtensions;
        }
        $extensions = [];
        foreach (\gp_all_profiles($this->db) as $profile) {
            foreach (\gp_extensions($profile) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') {
                    $extensions[$extension] = true;
                }
            }
        }
        if ($extensions === []) {
            foreach (($this->config['allowed_extensions'] ?? []) as $extension) {
                $extension = \catalog_clean_unreal_extension((string)$extension);
                if ($extension !== '') {
                    $extensions[$extension] = true;
                }
            }
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
        if ($allowRedirectWrapper && $this->isRedirectWrapper($name)) {
            return;
        }
        $extension = \catalog_clean_unreal_extension((string)pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $this->allowedExtensions();
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
