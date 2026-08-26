<?php
/**
 * Local-only GeoIP country resolver for audit ingestion.
 *
 * Country information is resolved once when a download/generation audit row is
 * created and persisted on that row. No network lookup is performed here and
 * Download Logs never resolves IPs while rendering historical data.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use Throwable;

final class CatalogGeoIpCountryResolver
{
    /** @var array<string,array{country_code:string,country_name:string}> */
    private array $requestCache = [];

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{country_code:string,country_name:string} */
    public function resolve(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || strtolower($ip) === 'unknown') {
            return $this->unknown();
        }
        if (isset($this->requestCache[$ip])) {
            return $this->requestCache[$ip];
        }

        $packed = @inet_pton($ip);
        if (!is_string($packed) || !in_array(strlen($packed), [4, 16], true)) {
            return $this->requestCache[$ip] = $this->unknown();
        }

        // Private/reserved addresses are operational infrastructure, not a
        // geographic visitor identity. Leave them blank rather than assigning a
        // misleading country.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $this->requestCache[$ip] = $this->unknown();
        }

        try {
            $statement = $this->db->prepare(
                'SELECT range_end,country_code,country_name '
                . 'FROM ue_geoip_country_ranges '
                . 'WHERE ip_version=? AND range_start<=? '
                . 'ORDER BY range_start DESC LIMIT 1'
            );
            $statement->bindValue(1, strlen($packed) === 4 ? 4 : 6, PDO::PARAM_INT);
            $statement->bindValue(2, $packed, PDO::PARAM_LOB);
            $statement->execute();
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || !is_string($row['range_end'] ?? null)) {
                return $this->requestCache[$ip] = $this->unknown();
            }
            if (strcmp($packed, (string)$row['range_end']) > 0) {
                return $this->requestCache[$ip] = $this->unknown();
            }

            $code = strtoupper(trim((string)($row['country_code'] ?? '')));
            $name = trim((string)($row['country_name'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || $name === '') {
                return $this->requestCache[$ip] = $this->unknown();
            }
            return $this->requestCache[$ip] = [
                'country_code' => $code,
                'country_name' => substr($name, 0, 120),
            ];
        } catch (Throwable $error) {
            // GeoIP is enrichment only. A missing/outdated lookup table must
            // never prevent or delay the actual download audit path.
            return $this->requestCache[$ip] = $this->unknown();
        }
    }

    /** @return array{country_code:string,country_name:string} */
    private function unknown(): array
    {
        return ['country_code' => '', 'country_name' => ''];
    }
}
