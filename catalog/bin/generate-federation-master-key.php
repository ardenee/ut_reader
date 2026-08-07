<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for generate federation master key.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

echo 'base64:' . base64_encode(random_bytes(32)) . PHP_EOL;
