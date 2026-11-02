<?php
/**
 * O01 entry-to-result acceptance for the actual source/queue/HTTP-scanning
 * helper catalog_try_read_package_header(), compared against the UT99 v400
 * ULinkerLoad tag failure path (Core/Src/UnLinker.h:354-369).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/lib/CatalogParser.php';

$checks = [];
$failed = [];
$check = static function (string $label, bool $passed) use (&$checks, &$failed): void {
    $checks[$label] = $passed;
    if (!$passed) { $failed[] = $label; }
};
$reader = static function (string $path): array {
    return catalog_try_read_package_header([], 'UE1', $path);
};
$rejects = static function (string $path) use ($reader): bool {
    try {
        $reader($path);
        return false;
    } catch (RuntimeException $exception) {
        return str_contains($exception->getMessage(), 'UE1');
    }
};
$path = tempnam(sys_get_temp_dir(), 'ue1_route_');
if (!is_string($path)) { throw new RuntimeException('Failed to create fixture.'); }
try {
    // Canonical UE1 format v68, zero tables and empty generation array.
    $make = static fn(int $tag): string =>
        pack('V', $tag) . pack('V', 68) . pack('V', 0)
        . pack('V6', 0, 0, 0, 0, 0, 0)
        . pack('V4', 1, 2, 3, 4) . pack('V', 0);
    file_put_contents($path, $make(0x9E2A83C1));
    $valid = $reader($path);
    $check('valid_standard_ue1_header_returns', ($valid['version'] ?? null) === 68 && ($valid['signature'] ?? null) === 0x9E2A83C1);

    file_put_contents($path, $make(0x9E2A83C2));
    $check('ue2_only_tag_cannot_escape_as_blank_header', $rejects($path));

    file_put_contents($path, str_repeat("\0", 6));
    $check('truncated_header_cannot_escape_as_blank_header', $rejects($path));

    file_put_contents($path, pack('a32', "Unrealfile\x1A") . pack('V6', 23, 0, 0, 0, 0, 0));
    $check('early_unrealfile_cannot_escape_as_blank_header', $rejects($path));
} finally {
    @unlink($path);
}
echo json_encode(['ok'=>$failed===[], 'checks'=>$checks, 'failures'=>$failed], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed===[] ? 0 : 1);
