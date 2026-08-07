<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the historical Upload Bucket URL while the v2 uploader is the canonical implementation.
 * Why: Existing bookmarks and internal links may still target this route during the retirement period.
 * Role: Compatibility redirect only; all upload behavior lives in `upload-bucket-v2.php`.
 * Audit: Remove this redirect only after old external links no longer need compatibility.
 */
declare(strict_types=1);

header('Location: upload-bucket-v2.php', true, 302);
exit;
