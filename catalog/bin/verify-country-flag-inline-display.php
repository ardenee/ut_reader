<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/lib/CatalogCountryFlag.php";
$failures = [];
foreach (["us", "kw", "sa", "gb", "de", "jp", "za"] as $code) {
    $html = catalog_country_flag_html($code, "download-country-flag");
    if (!str_contains($html, "data:image/svg+xml;base64,") || str_contains($html, "country-flag.php")) {
        $failures[] = $code;
    }
}
if (catalog_country_flag_html("../x", "flag") !== "") { $failures[] = "invalid_code"; }
foreach (["access-matrix.php", "download-logs.php", "site-blacklist.php", "assets/catalog-ui.js"] as $path) {
    if (str_contains((string)file_get_contents(dirname(__DIR__) . "/" . $path), "country-flag.php?code=")) {
        $failures[] = $path;
    }
}
echo json_encode(["ok" => $failures === [], "failures" => $failures], JSON_PRETTY_PRINT), PHP_EOL;
exit($failures === [] ? 0 : 2);
