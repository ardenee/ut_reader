<?php
/**
 * Retired optional browser-analytics endpoint.
 *
 * Keep an inexpensive no-op response for stale browser caches referencing
 * this route. Never create a session, connect to SQL or persist event data.
 */
declare(strict_types=1);

header('Cache-Control: no-store');
http_response_code(204);
