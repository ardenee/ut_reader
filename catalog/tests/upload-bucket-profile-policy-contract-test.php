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

$path = dirname(__DIR__) . '/upload-bucket.php';
$source = file_get_contents($path);
bucket_policy_expect(is_string($source), 'Upload bucket page is missing.');

bucket_policy_expect(str_contains($source, "require_once __DIR__ . '/lib/GameProfiles.php';"), 'Upload bucket does not load game profile helpers.');
bucket_policy_expect(str_contains($source, 'function upload_bucket_allowed_extensions'), 'Upload bucket profile extension policy helper is missing.');
bucket_policy_expect(str_contains($source, 'foreach (gp_all_profiles($db) as $profile)'), 'Upload bucket does not read active profiles.');
bucket_policy_expect(str_contains($source, 'foreach (gp_extensions($profile) as $extension)'), 'Upload bucket does not use allowed_extensions_json through the profile helper.');
bucket_policy_expect(str_contains($source, 'if ($extensions === [])'), 'Legacy global extensions are not restricted to fallback use.');
bucket_policy_expect(str_contains($source, 'not allowed by any active game profile'), 'Extension errors do not identify the effective policy.');

bucket_policy_expect(str_contains($source, 'function upload_bucket_post_limit_error'), 'Oversized POST detection is missing.');
bucket_policy_expect(str_contains($source, "ini_get('post_max_size')"), 'Upload bucket does not inspect PHP post_max_size.');
bucket_policy_expect(str_contains($source, "upload_bucket_php_limit_text('upload_max_filesize')"), 'Upload bucket does not report PHP upload_max_filesize.');
bucket_policy_expect(str_contains($source, 'HTTP_X_UPLOAD_BUCKET_AJAX'), 'AJAX detection does not survive an empty oversized POST body.');
bucket_policy_expect(str_contains($source, "url.searchParams.set('ajax', '1')"), 'Browser requests do not preserve the AJAX marker in the query string.');
bucket_policy_expect(str_contains($source, "xhr.setRequestHeader('X-Upload-Bucket-Ajax', '1')"), 'Browser requests do not preserve the AJAX marker in a header.');
bucket_policy_expect(str_contains($source, 'server returned non-JSON data'), 'Browser errors still discard non-JSON server responses.');
bucket_policy_expect(str_contains($source, 'request_id'), 'Upload errors do not expose a request reference.');

echo "Upload bucket profile policy contract tests passed.\n";
