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
bucket_policy_expect(is_string($javascript), 'Chunked upload bucket browser client is missing.');
bucket_policy_expect(is_string($support), 'Catalog support bootstrap is missing.');
bucket_policy_expect(is_string($epicRedirect), 'Strict Epic redirect dispatcher is missing.');
bucket_policy_expect(is_string($uz2Stream), 'Streamed Epic UZ2 decoder is missing.');

bucket_policy_expect(str_contains($page, "require_once __DIR__ . '/lib/GameProfiles.php';"), 'Upload bucket does not load game profile helpers.');
bucket_policy_expect(str_contains($page, "require_once __DIR__ . '/lib/CatalogEpicRedirect.php';"), 'Upload bucket fallback does not load the strict Epic redirect dispatcher.');
bucket_policy_expect(str_contains($page, 'catalog_epic_redirect_decompress_to_temp('), 'Upload bucket fallback does not use the strict Epic redirect dispatcher.');
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
bucket_policy_expect(str_contains($page, 'data-chunk-url="api/v1/upload-bucket-chunk.php"'), 'Upload bucket does not select the chunk endpoint by default.');
bucket_policy_expect(str_contains($page, 'No UnrealDB total-file-size limit is applied'), 'Upload bucket still advertises an application file-size cap.');
bucket_policy_expect(str_contains($javascript, 'async function chunkedUpload'), 'Browser client lacks the chunked upload implementation.');
bucket_policy_expect(str_contains($javascript, 'file.slice(start, end)'), 'Browser client does not send bounded file chunks.');
bucket_policy_expect(str_contains($javascript, 'received_chunks'), 'Browser client cannot resume already stored chunks.');
bucket_policy_expect(!str_contains($javascript, 'standardUpload('), 'Browser client still defaults some files to whole-file multipart uploads.');

bucket_policy_expect(str_contains($endpoint, "catalog_api_require_admin(false)"), 'Bucket chunk endpoint is not admin-only.');
bucket_policy_expect(str_contains($endpoint, "catalog_api_require_csrf('upload_bucket_chunk')"), 'Bucket chunk endpoint lacks CSRF protection.');
bucket_policy_expect(str_contains($endpoint, "['max_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint still applies the normal upload size cap.');
bucket_policy_expect(str_contains($endpoint, "['max_container_upload_bytes'] = PHP_INT_MAX"), 'Bucket chunk endpoint still applies the container size cap.');
bucket_policy_expect(str_contains($endpoint, 'stageBucketUpload('), 'Completed chunks are not passed through duplicate-safe bucket staging.');
bucket_policy_expect(str_contains($endpoint, 'CatalogEpicRedirect.php'), 'Chunk endpoint does not load the strict Epic redirect dispatcher.');
bucket_policy_expect(str_contains($endpoint, 'catalog_epic_redirect_decompress_to_temp('), 'Chunk endpoint does not use the strict Epic redirect dispatcher.');
bucket_policy_expect(str_contains($endpoint, 'PHP_INT_MAX'), 'Chunk redirect decompression still inherits the ordinary upload output limit.');
bucket_policy_expect(!str_contains($endpoint, 'CatalogRedirectArchivePayload.php'), 'Chunk endpoint loads the compatibility payload decoder.');
bucket_policy_expect(!str_contains($endpoint, 'catalog_redirect_archive_decompress_payload_to_temp('), 'Chunk endpoint uses the compatibility payload path.');

bucket_policy_expect(str_contains($support, "['upload-bucket.php', 'upload-bucket-chunk.php']"), 'Upload Bucket pages do not receive the uncapped redirect-output policy.');
bucket_policy_expect(str_contains($support, 'UNREALDB_REDIRECT_MAX_OUTPUT_BYTES'), 'Upload Bucket redirect-output policy is missing.');

bucket_policy_expect(str_contains($epicRedirect, "if (\$extension === 'uz2')"), 'Strict dispatcher does not isolate UE2 UZ2.');
bucket_policy_expect(str_contains($epicRedirect, 'CatalogRedirectArchiveStream::decompressUz2('), 'UZ2 does not use the exact streamed Epic record decoder.');
bucket_policy_expect(str_contains($epicRedirect, "catalog_legacy_uz_header(\$archive, 1234)"), 'UE1 UZ does not require the 1234 Epic FCodec wrapper.');
bucket_policy_expect(str_contains($epicRedirect, "\$signature !== 5678"), 'UE3 UZ3 does not require the 5678 Epic tag.');
bucket_policy_expect(str_contains($epicRedirect, "'decoder' => 'epic-uz3-zlib'"), 'UE3 UZ3 does not use the exact single-zlib decoder.');
bucket_policy_expect(!str_contains($epicRedirect, 'ZLIB_ENCODING_GZIP'), 'Strict dispatcher includes a gzip fallback.');
bucket_policy_expect(!str_contains($epicRedirect, 'ZLIB_ENCODING_RAW'), 'Strict dispatcher includes a raw-deflate fallback.');

bucket_policy_expect(str_contains($uz2Stream, 'catalog_redirect_archive_inflate_epic_zlib('), 'UZ2 records do not try exact zlib.');
bucket_policy_expect(str_contains($uz2Stream, "elseif (\$compressed === \$uncompressed)"), 'UZ2 records do not support the equal-size verbatim path.');
bucket_policy_expect(strpos($uz2Stream, 'catalog_redirect_archive_inflate_epic_zlib(') < strpos($uz2Stream, "elseif (\$compressed === \$uncompressed)"), 'Equal-size records are treated as stored before exact zlib is tested.');
bucket_policy_expect(str_contains($uz2Stream, 'offset='), 'UZ2 failures do not report the failing record offset.');

bucket_policy_expect(str_contains($page, 'function upload_bucket_post_limit_error'), 'Fallback oversized POST detection is missing.');
bucket_policy_expect(str_contains($page, "ini_get('post_max_size')"), 'Fallback handler does not inspect PHP post_max_size.');
bucket_policy_expect(str_contains($page, 'request_id'), 'Fallback upload errors do not expose a request reference.');

echo "Upload bucket profile policy contract tests passed.\n";
