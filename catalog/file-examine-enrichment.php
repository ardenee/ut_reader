<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Legacy endpoint retained so stale examiner pages do not trigger expensive compact-metadata scans.
 * Why: Name usage/name-link reconstruction by text is not an Unreal object-reference lookup and can require
 *      scanning whole metadata sections. Current examiner navigation uses stored numeric object references.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'disabled' => true,
    'name_usage' => [],
    'name_links' => [],
    'dependencies' => [],
], JSON_THROW_ON_ERROR);
