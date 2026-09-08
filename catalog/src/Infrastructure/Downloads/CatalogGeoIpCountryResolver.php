<?php
/**
 * Backward-compatible facade for the richer local GeoIP location resolver.
 *
 * Existing callers may continue using this class. The returned array now also
 * contains optional subdivision/city/coordinate fields in addition to the
 * original country_code and country_name keys.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;

final class CatalogGeoIpCountryResolver
{
    private readonly CatalogGeoIpLocationResolver $resolver;

    public function __construct(PDO $db)
    {
        $this->resolver = new CatalogGeoIpLocationResolver($db);
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
        return $this->resolver->resolve($ip);
    }
}
