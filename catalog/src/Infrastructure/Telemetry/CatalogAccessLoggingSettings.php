<?php
/**
 * Persistent first-party access logging settings.
 *
 * Uses the existing generic settings table so logging controls do not require a
 * dedicated schema migration. IP exclusions are normalized before storage and
 * applied at ingestion time so ignored traffic never affects Access Matrix
 * counts or raw-event history.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Telemetry;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationSettingsStore;

final class CatalogAccessLoggingSettings
{
    private const ENABLED = 'access_logging_enabled';
    private const IGNORE_ADMINS = 'access_logging_ignore_admin_sessions';
    private const IGNORED_IPS = 'access_logging_ignored_ips';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{enabled:bool,ignore_admin_sessions:bool,ignored_ips:list<string>} */
    public function current(): array
    {
        $statement = $this->db->prepare(
            'SELECT setting_name,setting_value FROM ue_federation_settings '
            . 'WHERE setting_name IN (?,?,?)'
        );
        $statement->execute([self::ENABLED, self::IGNORE_ADMINS, self::IGNORED_IPS]);
        $values = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
        }

        return [
            'enabled' => ($values[self::ENABLED] ?? '1') !== '0',
            'ignore_admin_sessions' => ($values[self::IGNORE_ADMINS] ?? '0') === '1',
            'ignored_ips' => $this->decodeIps($values[self::IGNORED_IPS] ?? '[]'),
        ];
    }

    /** @param array<string,mixed> $input
     *  @return array{enabled:bool,ignore_admin_sessions:bool,ignored_ips:list<string>}
     */
    public function save(array $input): array
    {
        $rawIps = trim((string)($input['ignored_ips'] ?? ''));
        $ips = [];
        foreach (preg_split('/[\r\n,;]+/', $rawIps) ?: [] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            $normalized = self::normalizeIp($candidate);
            if ($normalized === null) {
                throw new RuntimeException('Invalid ignored IP address: ' . $candidate);
            }
            $ips[$normalized] = true;
        }
        if (count($ips) > 200) {
            throw new RuntimeException('No more than 200 ignored IP addresses may be configured.');
        }
        $ipList = array_keys($ips);
        natcasesort($ipList);
        $ipList = array_values($ipList);

        $store = new CatalogFederationSettingsStore($this->db);
        $store->set(self::ENABLED, isset($input['logging_enabled']) ? '1' : '0');
        $store->set(self::IGNORE_ADMINS, isset($input['ignore_admin_sessions']) ? '1' : '0');
        $encoded = json_encode($ipList, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $store->set(self::IGNORED_IPS, $encoded);

        return [
            'enabled' => isset($input['logging_enabled']),
            'ignore_admin_sessions' => isset($input['ignore_admin_sessions']),
            'ignored_ips' => $ipList,
        ];
    }

    public function shouldIgnore(string $ip, bool $isAdmin): bool
    {
        $settings = $this->current();
        if (!$settings['enabled']) {
            return true;
        }
        if ($isAdmin && $settings['ignore_admin_sessions']) {
            return true;
        }

        $normalized = self::normalizeIp($ip);
        return $normalized !== null && in_array($normalized, $settings['ignored_ips'], true);
    }

    private function decodeIps(string $raw): array
    {
        $decoded = json_decode($raw, true);
        $items = is_array($decoded) ? $decoded : (preg_split('/[\r\n,;]+/', $raw) ?: []);
        $ips = [];
        foreach ($items as $candidate) {
            $normalized = self::normalizeIp((string)$candidate);
            if ($normalized !== null) {
                $ips[$normalized] = true;
            }
        }
        return array_keys($ips);
    }

    private static function normalizeIp(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        if (!is_string($packed) || !in_array(strlen($packed), [4, 16], true)) {
            return null;
        }
        $normalized = @inet_ntop($packed);
        return is_string($normalized) && $normalized !== '' ? strtolower($normalized) : null;
    }
}
