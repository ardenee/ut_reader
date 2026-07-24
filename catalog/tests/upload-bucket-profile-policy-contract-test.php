<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function bucket_policy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/upload-bucket.php');
$endpoint = file_get_contents($root . '/api/v1/upload-bucket-chunk.php');
$javascript = file_get_contents($root . '/assets/upload-bucket.js');
$support = file_get_contents($root . '/lib/CatalogSupport.php');
$epicRedirect = file_get_contents($root . '/lib/CatalogEpicRedirect.php');
$uz2Stream = file_get_contents($root . '/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php');
bucket_policy_expect(is_string($page), 'Upload bucket page is missing.');
bucket_policy_expect(is_string($endpoint), 'Chunked upload bucket endpoint is missing.');
bucket_policy_expect(is_string($javascript), 'Upload bucket browser client is missing.');
bucket_policy_expect(is_string($support), 'Catalog support bootstrap is missing.');
bucket_policy_expect(is_string($epicRedirect), 'Strict Epic redirect dispatcher is missing.');
bucket_policy_expect(is_string($uz2Stream), 'Streamed Epic UZ2 decoder is missing.');

bucket_policy_expect(str_contains($page, "require_once __DIR__ . '/lib/GameProfiles.php';"), 'Upload bucket does not load game profile helpers.');
bucket_policy_expect(!str_contains($page, "require_once __DIR__ . '/lib/CatalogEpicRedirect.php';"), 'Whole-file Upload Bucket fallback still loads the new strict redirect dispatcher.');
bucket_policy_expect(str_contains($page, 'catalog_redirect_archive_decompress_to_temp('), 'Whole-file Upload Bucket fallback does not use the original redirect decoder.');
bucket_policy_expect(!str_contains($page, 'catalog_epic_redirect_decompress_to_temp('), 'Whole-file Upload Bucket fallback still uses the new strict redirect path.');
bucket_policy_expect(str_contains($page, 'original working whole-file decompression path'), 'Upload Bucket does not describe the restored redirect route.');
bucket_policy_expect(str_contains($page, 'function upload_bucket_allowed_extensions'), 'Upload bucket profile extension policy helper is missing.');
bucket_policy_expect(str_contains($page, 'foreach (gp_all_profiles($db) as $profile)'), 'Upload bucket does not read active profiles.');
bucket_policy_expect(str_contains($page, 'foreach (gp_extensions($profile) as $extension)'), 'Upload bucket does not use allowed_extensions_json through the profile helper.');
bucket_policy_expect(str_contains($page, 'if ($extensions === [])'), 'Legacy global extensions are not restricted to fallback use.');
bucket_policy_expect(str_contains($page, 'not allowed by any active game profile'), 'Extension errors do not identify the effective policy.');

bucket_policy_expect(str_contains($page, 'function upload_bucket_stats'), 'Upload bucket lacks a lightweight physical-folder statistics scan.');
bucket_policy_expect(!str_contains($page, 'uvf_list($db, $config, 0)'), 'Upload bucket still hashes and parses every queued file merely to render totals.');
bucket_policy_expect(str_contains($page, 'FilesystemIterator($bucketDir'), 'Physical bucket statistics are not calculated from the bucket folder.');
bucket_policy_expect(str_contains($page, 'bucket-path') && str_contains($page, 'overflow-wrap:anywhere'), 'Physical bucket path is not allowed to wrap.');
bucket_policy_expect(str_contains($page, 'grid-template-columns:minmax(125px,.55fr)'), 'Bucket count and storage cards were not narrowed for the path card.');

bucket_policy_expect(str_contains($page, 'bucket-overall-progress-bar'), 'Overall upload progress bar is missing.');
bucket_policy_expect(str_contains($page, 'bucket-progress-bar'), 'Current-file upload progress bar is missing.');
bucket_policy_expect(str_contains($page, 'data-chunk-url="api/v1/upload-bucket-chunk.php"'), 'Upload bucket does not retain the chunk endpoint for normal packages.');
bucket_policy_expect(str_contains($javascript, 'async function chunkedUpload'), 'Browser client lacks the chunked upload implementation.');
bucket_policy_expect(str_contains($javascript, 'file.slice(start, end)'), 'Browser client does not send bounded file chunks.');
bucket_policy_expect(str_contains($javascript, 'received_chunks'), 'Browser client cannot resume already stored chunks.');
bucket_policy_expect(str_contains($javascript, 'function isRedirectArchive'), 'Browser client cannot identify redirect wrappers.');
bucket_policy_expect(str_contains($javascript, 'function wholeFileUpload'), 'Browser client lacks the restored whole-file redirect upload.');
bucket_policy_expect(str_contains($javascript, '? await wholeFileUpload(file'), 'Redirect wrappers are not routed through the original whole-file handler.');
bucket_policy_expect(str_contains($javascript, ': await chunkedUpload(file'), 'Normal packages are not routed through resumable chunks.');
bucket_policy_expect(str_contains($javascript, "url.searchParams.set('ajax', '1')"), 'Restored whole-file uploads do not call upload-bucket.php in AJAX mode.');

bucket_policy_expect(str_contains($endpoint, "catalog_api_require_admin(false)"), 'Bucket chunk endpoint is not admin-only.');
bucket_policy_expect(str_contains($endpoint, "catalog_api_require_csrf('upload_bucket_chunk')"), 'Bucket chunk endpoint lacks CSRF protection.');
bucket_policy_expect(str_contains($endpoint, "['max_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint still applies the normal upload size cap.');
bucket_policy_expect(str_contains($endpoint, "['max_container_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint still applies the container size cap.');
bucket_policy_expect(str_contains($endpoint, 'stageBucketUpload('), 'Completed chunks are not passed through duplicate-safe bucket staging.');
bucket_policy_expect(str_contains($endpoint, 'CatalogEpicRedirect.php'), 'Chunk endpoint does not retain the strict redirect dispatcher for direct API callers.');
bucket_policy_expect(str_contains($endpoint, 'catalog_epic_redirect_decompress_to_temp('), 'Chunk endpoint does not retain its strict redirect dispatcher.');

bucket_policy_expect(str_contains($support, "['upload-bucket.php', 'upload-bucket-chunk.php']"), 'Upload Bucket pages do not receive the uncapped redirect-output policy.');
bucket_policy_expect(str_contains($support, 'UNREALDB_REDIRECT_MAX_OUTPUT_BYTES'), 'Upload Bucket redirect-output policy is missing.');

bucket_policy_expect(str_contains($epicRedirect, "if (\$extension === 'uz2')"), 'Strict dispatcher does not isolate UE2 UZ2.');
bucket_policy_expect(str_contains($epicRedirect, 'CatalogRedirectArchiveStream::decompressUz2('), 'UZ2 does not use the streamed Epic record decoder in the chunk API.');
bucket_policy_expect(str_contains($epicRedirect, "catalog_legacy_uz_header(\$archive, 1234)"), 'UE1 UZ does not require the 1234 Epic FCodec wrapper.');
bucket_policy_expect(str_contains($epicRedirect, "\$signature !== 5678"), 'UE3 UZ3 does not require the 5678 Epic tag.');

bucket_policy_expect(str_contains($uz2Stream, 'private static function decodePayload'), 'Streamed UZ2 decoder lacks its PHP decoder dispatcher.');
bucket_policy_expect(str_contains($uz2Stream, "['zlib_decode', 'gzuncompress', 'gzinflate', 'gzdecode']"), 'Streamed UZ2 decoder does not enumerate its PHP decoder functions.');
bucket_policy_expect(str_contains($uz2Stream, 'offset='), 'UZ2 failures do not report the failing record offset.');

bucket_policy_expect(str_contains($page, 'function upload_bucket_post_limit_error'), 'Whole-file oversized POST detection is missing.');
bucket_policy_expect(str_contains($page, "ini_get('post_max_size')"), 'Whole-file handler does not inspect PHP post_max_size.');
bucket_policy_expect(str_contains($page, 'request_id'), 'Whole-file upload errors do not expose a request reference.');

echo "Upload bucket profile policy contract tests passed.\n";
