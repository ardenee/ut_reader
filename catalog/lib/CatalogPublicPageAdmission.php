<?php
/**
 * Emergency, read-before-DB resource admission for public File Examine pages.
 *
 * The state file holds only expiring admission timestamps and hashed,
 * short-lived challenge identities. It is not a user analytics log.
 * Windows Apache workers coordinate with an exclusive filesystem lock.
 */
declare(strict_types=1);

final class CatalogPublicPageAdmission
{
    public const MAX_ACTIVE = 4;
    public const MAX_IN_TWO_SECONDS = 10;
    public const MAX_PER_PASS_MINUTE = 24;

    private static function file(): string
    {
        $override = getenv('UNREALDB_PUBLIC_ADMISSION_FILE');
        return is_string($override) && $override !== ''
            ? $override
            : __DIR__ . '/../storage/security/public-file-examine-admission.json';
    }

    /**
     * Reserve an inexpensive admission slot; no sessions, database or package IO.
     * A refused request can return 429 before application bootstrapping.
     */
    public static function admit(string $signedPass): bool
    {
        $file = self::file();
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open shared public admission state');
        }
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return false;
        }
        try {
            $contents = stream_get_contents($handle);
            if (!is_string($contents)) {
                throw new RuntimeException('Unable to read public admission state');
            }
            $state = $contents === '' ? [] : json_decode($contents, true);
            if (!is_array($state)) {
                throw new RuntimeException('Invalid public admission state');
            }
            $now = microtime(true);
            $active = [];
            foreach (($state['active'] ?? []) as $id => $started) {
                if (is_string($id) && is_numeric($started)
                    && (float)$started > $now - 90.0 && (float)$started <= $now + 1) {
                    $active[$id] = (float)$started;
                }
            }
            $recent = array_values(array_filter(
                (array)($state['recent'] ?? []),
                static fn($when): bool =>
                    is_numeric($when) && (float)$when > $now - 2.0 && (float)$when <= $now + 1
            ));
            $tokens = [];
            foreach ((array)($state['tokens'] ?? []) as $digest => $times) {
                if (!is_string($digest) || !is_array($times)) continue;
                $fresh = array_values(array_filter($times, static fn($when): bool =>
                    is_numeric($when) && (float)$when > $now - 60.0 && (float)$when <= $now + 1
                ));
                if ($fresh !== []) $tokens[$digest] = $fresh;
            }
            $digest = hash('sha256', $signedPass);
            $own = $tokens[$digest] ?? [];
            $allowed = count($active) < self::MAX_ACTIVE
                && count($recent) < self::MAX_IN_TWO_SECONDS
                && count($own) < self::MAX_PER_PASS_MINUTE
                && count($tokens) < 800;

            if ($allowed) {
                $id = bin2hex(random_bytes(12));
                $active[$id] = $now;
                $recent[] = $now;
                $own[] = $now;
                $tokens[$digest] = $own;
            }
            self::write($handle, [
                'active' => $active,
                'recent' => $recent,
                'tokens' => $tokens,
            ]);
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($allowed) {
            // Release the slot even if the application exits early or fails.
            register_shutdown_function([self::class, 'release'], $file, $id);
        }
        return $allowed;
    }

    private static function write($handle, array $state): void
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        rewind($handle);
        if (!ftruncate($handle, 0)
            || fwrite($handle, $json) !== strlen($json)
            || !fflush($handle)) {
            throw new RuntimeException('Unable to save public admission state');
        }
    }

    public static function release(string $file, string $id): void
    {
        $handle = @fopen($file, 'c+');
        if ($handle === false) return;
        if (!@flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            // An uncleanly released slot naturally expires within 90 seconds.
            return;
        }
        try {
            $s = json_decode((string)stream_get_contents($handle), true);
            if (is_array($s) && isset($s['active'][$id])) {
                unset($s['active'][$id]);
                self::write($handle, $s);
            }
        } catch (Throwable $e) {
            error_log('[UnrealDB public admission] Release failed: ' . $e->getMessage());
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
