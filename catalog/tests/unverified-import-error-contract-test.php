<?php
declare(strict_types=1);

function unverified_import_error_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$action = file_get_contents(__DIR__ . '/../unverified-files-action.php');
$client = file_get_contents(__DIR__ . '/../assets/unverified-file-actions.js');
unverified_import_error_expect(is_string($action), 'Unverified action endpoint could not be read.');
unverified_import_error_expect(is_string($client), 'Unverified action client could not be read.');

unverified_import_error_expect(
    str_contains($action, 'JSON_INVALID_UTF8_SUBSTITUTE')
        && str_contains($action, "'request_id' => \$requestId"),
    'Unverified action errors are not guaranteed to return valid JSON with a request reference.'
);
unverified_import_error_expect(
    str_contains($action, 'register_shutdown_function')
        && str_contains($action, 'The server stopped unexpectedly while processing this file.'),
    'Fatal unverified import failures are not converted into a useful JSON response.'
);
unverified_import_error_expect(
    str_contains($action, 'DELETE d FROM ue_dependencies d INNER JOIN ue_imports i ON i.id=d.import_id WHERE i.file_id=?')
        && str_contains($action, 'DELETE FROM ue_dependencies WHERE import_id=?')
        && str_contains($action, 'uq_ue_deps_import'),
    'Stale dependency/import collisions are not cleaned before or after promotion.'
);
unverified_import_error_expect(
    str_contains($action, 'scan_status="verified"')
        && str_contains($action, 'File verification completed, but dependency refresh failed:')
        && str_contains($action, 'unverified_action_recover_verified_dependencies'),
    'A post-promotion dependency failure can still be reported as a complete import failure.'
);
unverified_import_error_expect(
    str_contains($client, 'compactServerText')
        && str_contains($client, 'the server returned a non-JSON progress response')
        && str_contains($client, 'refresh before retrying because the import may already have completed')
        && str_contains($client, 'payload.request_id'),
    'The progress client still hides the HTTP response and request reference behind a generic parse error.'
);

echo "Unverified import error contract tests passed.\n";
