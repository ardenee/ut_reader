<?php
/**
 * Read-only acceptance for public privacy notice and retired opt-in analytics.
 * Web-level checks also run against genuine PHP HTTP output in the deploy gate.
 */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
$catalog=$repo.'/catalog';
require_once $catalog.'/lib/CatalogSupport.php';
function checkPrivacy(bool $condition,string $failure):void{
    if (!$condition)throw new RuntimeException($failure);
}
$privacy=file_get_contents($catalog.'/privacy.html');
$landing=file_get_contents($catalog.'/direct-entry.html');
$inert=file_get_contents($catalog.'/assets/privacy-notice.js');
$collector=file_get_contents($catalog.'/assets/catalog-access-matrix.js');
$endpoint=file_get_contents($catalog.'/access-event.php');
$transform=file_get_contents($catalog.'/src/Presentation/Http/CatalogPageResponseTransform.php');
$core=file_get_contents($catalog.'/lib/CatalogSupportCore.php');
$style=file_get_contents($catalog.'/assets/catalog-ui.css');
checkPrivacy(str_contains($privacy,'CrowdSec'),'CrowdSec processing not disclosed');
checkPrivacy(str_contains($privacy,'Essential security processing'),'Essential security missing');
checkPrivacy(str_contains($privacy,'do not operate an optional visitor-analytics service'),'Optional analytics policy inaccurate');
checkPrivacy(!str_contains($privacy,'<script src="/catalog/assets/privacy-notice.js"'),'Privacy page loads discontinued modal');
checkPrivacy(!str_contains($landing,'<script src="/catalog/assets/privacy-notice.js"'),'Gate still loads discontinued modal');
checkPrivacy(str_contains($landing,'three-second') || str_contains($landing,'three seconds'),'Three-second gate no longer described');
checkPrivacy(!str_contains($inert,'createElement(') && !str_contains($inert,'addEventListener('),'Cached notice script can render UI');
checkPrivacy(str_contains($inert,'Max-Age=0'),'Old tracking acknowledgement cookies are not retired');
checkPrivacy(!str_contains($collector,'fetch(') && !str_contains($collector,'sendBeacon'),'Legacy browser event capture remains active');
checkPrivacy(str_contains($endpoint,'http_response_code(204)'),'Retired analytics endpoint not no-op');
checkPrivacy(!str_contains($endpoint,'catalog_db(') && !str_contains($endpoint,'CatalogAccessEventRecorder'),'Endpoint may write analytics');
checkPrivacy(!str_contains($transform,"'catalog-access-matrix.js'"),'Event collector injected by renderer');
checkPrivacy(!str_contains($core,'recordPageView()'),'Server still records optional page views');
checkPrivacy(!str_contains($core,"assets/privacy-notice.js?v="),'Public pages still inject privacy modal script');
checkPrivacy(str_contains($core,'<footer class="catalog-privacy-footer"'),'Shared footer missing');
checkPrivacy(str_contains($core,"'privacy.html'"),'Privacy link missing from footer');
checkPrivacy(str_contains($style,'.catalog-privacy-footer'),'Footer CSS missing');
require_once $catalog.'/lib/CatalogDirectEntryGate.php';
checkPrivacy(CatalogDirectEntryGate::eligibleRoute('file-examine.php'),'Server-side gate removed');
checkPrivacy(CatalogDirectEntryGate::eligibleRoute('game-files.php'),'Game listing gate removed');
echo json_encode([
 'pass'=>true,'no_db_queries'=>true,'public_footer_link'=>true,
 'privacy_popup_removed'=>true,'acknowledgement_cookie_not_created'=>true,
 'obsolete_cookies_retired'=>true,'public_tracking_disabled'=>true,
 'analytics_endpoint_no_op'=>true,'server_side_direct_gate_preserved'=>true,
 'privacy_discloses_crowdsec'=>true,
],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
