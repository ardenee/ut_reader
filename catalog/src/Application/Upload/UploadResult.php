<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `UploadResult` for upload result.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Upload;

/**
 * Stable upload result contract shared by controllers and application services.
 *
 * Existing browser responses use associative arrays, so this class deliberately
 * preserves that public shape while removing repeated formatting logic.
 */
final class UploadResult
{
    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public static function create(
        string $status,
        string $file,
        string $message,
        array $metadata = []
    ): array {
        unset($metadata['duplicate_guid'], $metadata['duplicate_file_size_text']);

        return [
            'status' => $status,
            'file' => $file,
            'message' => $message,
        ] + $metadata;
    }

    /**
     * Preserve the existing flash/progress text exactly.
     *
     * @param array<string, mixed> $entry
     */
    public static function text(array $entry): string
    {
        $text = (string)($entry['file'] ?? '')
            . ': '
            . (string)($entry['status'] ?? '')
            . ' - '
            . (string)($entry['message'] ?? '');

        if (!empty($entry['file_size_text'])) {
            $text .= ' | size: ' . (string)$entry['file_size_text'];
        }
        if (!empty($entry['package_guid'])) {
            $text .= ' | GUID: ' . (string)$entry['package_guid'];
        }
        if (!empty($entry['duplicate_original_name'])) {
            $text .= ' | copy of: ' . (string)$entry['duplicate_original_name'];
        }

        return $text;
    }
}
