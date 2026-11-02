#!/usr/bin/env php
<?php
/** Read-only verifier: Background Jobs must not render the duplicate Error/result detail line. */
declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string)@file_get_contents($root . '/background-jobs.php');

$ok = $page !== '' && str_contains($page, '.jobs-detail-error{display:none!important}');
echo '[' . ($ok ? 'PASS' : 'FAIL') . '] background_jobs_redundant_error_result_hidden' . PHP_EOL;
exit($ok ? 0 : 1);
