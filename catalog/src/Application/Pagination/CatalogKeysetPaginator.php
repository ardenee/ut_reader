<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Pagination;

use JsonException;

/**
 * Creates context-bound opaque cursors and lexicographic SQL predicates.
 *
 * Cursor data contains only already-visible sort values. The HMAC prevents a
 * client from changing those values or reusing a cursor with another filter,
 * game or sort configuration.
 */
final class CatalogKeysetPaginator
{
    /** @param array<string,mixed> $config @param list<mixed> $values */
    public static function encode(array $config, string $context, array $values): string
    {
        $payload = [
            'v' => 1,
            'c' => hash('sha256', $context),
            'd' => array_values($values),
        ];

        try {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new \RuntimeException('Pagination cursor could not be encoded.', 0, $error);
        }

        $body = self::base64UrlEncode($json);
        $signature = self::base64UrlEncode(hash_hmac('sha256', $body, self::key($config), true));
        return $body . '.' . $signature;
    }

    /** @param array<string,mixed> $config @return list<mixed>|null */
    public static function decode(array $config, string $context, string $token): ?array
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 4096 || substr_count($token, '.') !== 1) {
            return null;
        }

        [$body, $signature] = explode('.', $token, 2);
        $expected = self::base64UrlEncode(hash_hmac('sha256', $body, self::key($config), true));
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $json = self::base64UrlDecode($body);
        if ($json === null) {
            return null;
        }

        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($payload)
            || (int)($payload['v'] ?? 0) !== 1
            || !hash_equals(hash('sha256', $context), (string)($payload['c'] ?? ''))
            || !is_array($payload['d'] ?? null)
        ) {
            return null;
        }

        return array_values($payload['d']);
    }

    /**
     * @param list<string> $columns
     * @param list<string> $directions
     * @param list<mixed> $values
     * @return array{sql:string,args:list<mixed>}
     */
    public static function comparison(array $columns, array $directions, array $values, bool $after): array
    {
        $count = count($columns);
        if ($count === 0 || count($directions) !== $count || count($values) !== $count) {
            throw new \InvalidArgumentException('Pagination cursor shape does not match the sort tuple.');
        }

        $branches = [];
        $args = [];
        for ($index = 0; $index < $count; $index++) {
            $parts = [];
            for ($previous = 0; $previous < $index; $previous++) {
                $parts[] = $columns[$previous] . '=?';
                $args[] = $values[$previous];
            }

            $direction = self::direction($directions[$index]);
            $greater = $direction === 'ASC';
            if (!$after) {
                $greater = !$greater;
            }
            $parts[] = $columns[$index] . ($greater ? '>?' : '<?');
            $args[] = $values[$index];
            $branches[] = '(' . implode(' AND ', $parts) . ')';
        }

        return ['sql' => '(' . implode(' OR ', $branches) . ')', 'args' => $args];
    }

    /** @param list<string> $columns @param list<string> $directions */
    public static function order(array $columns, array $directions, bool $reverse = false): string
    {
        if ($columns === [] || count($columns) !== count($directions)) {
            throw new \InvalidArgumentException('Pagination sort columns and directions do not match.');
        }

        $parts = [];
        foreach ($columns as $index => $column) {
            $direction = self::direction($directions[$index]);
            if ($reverse) {
                $direction = $direction === 'ASC' ? 'DESC' : 'ASC';
            }
            $parts[] = $column . ' ' . $direction;
        }
        return implode(', ', $parts);
    }

    private static function direction(string $direction): string
    {
        return strtoupper(trim($direction)) === 'DESC' ? 'DESC' : 'ASC';
    }

    /** @param array<string,mixed> $config */
    private static function key(array $config): string
    {
        $db = is_array($config['db'] ?? null) ? $config['db'] : [];
        $seed = implode("\0", [
            (string)($db['database'] ?? ''),
            (string)($db['username'] ?? ''),
            (string)($db['password'] ?? ''),
            (string)($config['storage_path'] ?? ''),
            __FILE__,
        ]);
        return hash('sha256', $seed, true);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $padding = (4 - (strlen($value) % 4)) % 4;
        $decoded = base64_decode(strtr($value . str_repeat('=', $padding), '-_', '+/'), true);
        return is_string($decoded) ? $decoded : null;
    }
}
