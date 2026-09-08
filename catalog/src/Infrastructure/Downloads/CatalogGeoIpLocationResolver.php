<?php
/**
 * Local-only IP geolocation resolver.
 *
 * Resolves public IPv4/IPv6 addresses against ue_geoip_country_ranges. When the
 * optional city/region columns are populated, the result includes approximate
 * coordinates and an accuracy radius. Country-only imports continue to work.
 *
 * No network lookup is ever performed from the request path.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use Throwable;

final class CatalogGeoIpLocationResolver
{
    /**
     * @var array<string,array{
     *   country_code:string,country_name:string,subdivision_code:string,
     *   subdivision_name:string,city_name:string,postal_code:string,
     *   latitude:?float,longitude:?float,accuracy_radius_km:?int,precision:string
     * }>
     */
    private array $requestCache = [];

    private ?bool $detailedColumnsAvailable = null;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @return array{
     *   country_code:string,country_name:string,subdivision_code:string,
     *   subdivision_name:string,city_name:string,postal_code:string,
     *   latitude:?float,longitude:?float,accuracy_radius_km:?int,precision:string
     * }
     */
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

        // Private/reserved addresses describe infrastructure rather than a
        // geographic visitor location.
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return $this->requestCache[$ip] = $this->unknown();
        }

        try {
            $detailed = $this->detailedColumnsAvailable();
            $sql = $detailed
                ? 'SELECT range_end,country_code,country_name,subdivision_code,subdivision_name,city_name,postal_code,'
                    . 'latitude,longitude,accuracy_radius_km '
                    . 'FROM ue_geoip_country_ranges WHERE ip_version=? AND range_start<=? '
                    . 'ORDER BY range_start DESC LIMIT 1'
                : 'SELECT range_end,country_code,country_name '
                    . 'FROM ue_geoip_country_ranges WHERE ip_version=? AND range_start<=? '
                    . 'ORDER BY range_start DESC LIMIT 1';

            $statement = $this->db->prepare($sql);
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
            $countryName = trim((string)($row['country_name'] ?? ''));
            if (preg_match('/^[A-Z]{2}$/', $code) !== 1 || $countryName === '') {
                return $this->requestCache[$ip] = $this->unknown();
            }

            $latitude = $this->coordinate($row['latitude'] ?? null, -90.0, 90.0);
            $longitude = $this->coordinate($row['longitude'] ?? null, -180.0, 180.0);
            if ($latitude === null || $longitude === null) {
                $latitude = null;
                $longitude = null;
            }

            $accuracy = isset($row['accuracy_radius_km']) && $row['accuracy_radius_km'] !== null
                ? max(0, min(100000, (int)$row['accuracy_radius_km']))
                : null;

            return $this->requestCache[$ip] = [
                'country_code' => $code,
                'country_name' => substr($countryName, 0, 120),
                'subdivision_code' => substr(trim((string)($row['subdivision_code'] ?? '')), 0, 32),
                'subdivision_name' => substr(trim((string)($row['subdivision_name'] ?? '')), 0, 120),
                'city_name' => substr(trim((string)($row['city_name'] ?? '')), 0, 120),
                'postal_code' => substr(trim((string)($row['postal_code'] ?? '')), 0, 32),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy_radius_km' => $accuracy,
                'precision' => $latitude !== null && $longitude !== null ? 'coordinate' : 'country',
            ];
        } catch (Throwable) {
            // GeoIP is optional enrichment. Missing/outdated data must never
            // break downloads or administrator reporting.
            return $this->requestCache[$ip] = $this->unknown();
        }
    }

    private function detailedColumnsAvailable(): bool
    {
        if ($this->detailedColumnsAvailable !== null) {
            return $this->detailedColumnsAvailable;
        }

        try {
            $statement = $this->db->query(
                'SELECT COUNT(*) FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_geoip_country_ranges" '
                . 'AND COLUMN_NAME IN ("subdivision_code","subdivision_name","city_name","postal_code","latitude","longitude","accuracy_radius_km")'
            );
            return $this->detailedColumnsAvailable = $statement !== false
                && (int)$statement->fetchColumn() === 7;
        } catch (Throwable) {
            return $this->detailedColumnsAvailable = false;
        }
    }

    private function coordinate(mixed $value, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        $number = (float)$value;
        return is_finite($number) && $number >= $minimum && $number <= $maximum
            ? $number
            : null;
    }

    /**
     * @return array{
     *   country_code:string,country_name:string,subdivision_code:string,
     *   subdivision_name:string,city_name:string,postal_code:string,
     *   latitude:?float,longitude:?float,accuracy_radius_km:?int,precision:string
     * }
     */
    private function unknown(): array
    {
        return [
            'country_code' => '',
            'country_name' => '',
            'subdivision_code' => '',
            'subdivision_name' => '',
            'city_name' => '',
            'postal_code' => '',
            'latitude' => null,
            'longitude' => null,
            'accuracy_radius_km' => null,
            'precision' => 'unknown',
        ];
    }
}
