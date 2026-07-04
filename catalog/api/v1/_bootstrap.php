<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use UnrealDb\Catalog\Presentation\Http\JsonResponse;

function catalog_api_application()
{
    return catalog_bootstrap();
}

function catalog_api_require_admin(): void
{
    if (!catalog_support_is_admin()) {
        JsonResponse::error('unauthorized', 'Administrator authentication is required.', 401);
    }
}

function catalog_api_require_csrf(string $scope): void
{
    $provided = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = catalog_csrf($scope);
    if ($provided === '' || !hash_equals($expected, $provided)) {
        JsonResponse::error('csrf_invalid', 'A valid X-CSRF-Token header is required.', 403);
    }
}

/**
 * @return array<string, mixed>
 */
function catalog_api_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        JsonResponse::error('invalid_json', 'Request body must be a JSON object.', 400);
    }

    if (!is_array($decoded)) {
        JsonResponse::error('invalid_json', 'Request body must be a JSON object.', 400);
    }

    return $decoded;
}
