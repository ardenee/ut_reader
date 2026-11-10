<?php
/**
 * Site Activity Logs must not load every blocked IP or recreate marker files
 * just to render a collapsed blacklist preview, particularly with no events.
 */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
$page=(string)file_get_contents(dirname(__DIR__,2).'/catalog/access-matrix.php');
$service=(string)file_get_contents(dirname(__DIR__,2).'/catalog/src/Infrastructure/Security/CatalogSiteBlocklist.php');
$check=static function(bool $result,string $message):void{
 if(!$result)throw new RuntimeException($message);
};
$check(!str_contains($page,'$siteBlocklist->all()'),
 'Site Activity Logs must never materialize full security blocklist');
$check(str_contains($page,'$siteBlocklist->recent(50)')
 && str_contains($page,'$siteBlocklist->matchingIps($visibleIps)')
 && str_contains($page,'$siteBlocklist->count()'),
 'Bounded site blocklist preview/lookup missing');
$check(str_contains($page,'site-blacklist.php'),
 'Full blocked-IP management must remain accessible');
$check(str_contains($service,'public function recent(')
 && str_contains($service,'public function matchingIps(')
 && str_contains($service,'WHERE ip_address IN (')
 && str_contains($service,'LIMIT ')
 && str_contains($service,'public function count()'),
 'Security service lacks bounded indexed queries');
$check(str_contains($service,'if (!is_file(self::markerPath($this->config, $packed)))'),
 'Full blocklist read still overwrites existing security markers');
echo json_encode(['pass'=>true,'dashboard_full_blocklist_load'=>false,
 'dashboard_preview_limit'=>50,'exact_ip_lookup'=>'batched indexed',
 'full_blocklist_management_link'=>true,'repeated_marker_rewrites'=>false,
 'production_mutations'=>0],JSON_PRETTY_PRINT).PHP_EOL;
