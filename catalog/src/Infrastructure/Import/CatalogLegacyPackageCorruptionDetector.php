<?php
/**
 * Detects a deterministic legacy Unreal package corruption pattern where binary
 * NUL bytes appear to have been converted to ASCII spaces before import.
 *
 * The expensive whole-file byte scan is only performed after a highly specific
 * legacy package-header signature is present, so ordinary package inspection
 * remains header-only.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogLegacyPackageCorruptionDetector
{
    /**
     * @return array<string,int|string>|null
     */
    public static function detectZeroToSpace(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $header = @file_get_contents($path, false, null, 0, 64);
        if (!is_string($header) || strlen($header) < 36) {
            return null;
        }
        if (!\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedBytes($header)) {
            return null;
        }

        $actualVersion = (int)(unpack('v', substr($header, 4, 2))[1] ?? 0);
        $actualLicensee = (int)(unpack('v', substr($header, 6, 2))[1] ?? 0);
        if (function_exists('gp_engine_from_version') && \gp_engine_from_version($actualVersion) !== null) {
            return null;
        }

        $bytes = array_values(unpack('C*', substr($header, 0, 36)) ?: []);
        if (count($bytes) < 36 || $bytes[5] !== 0x20 || $bytes[7] !== 0x20) {
            return null;
        }

        $candidateVersion = $bytes[4];
        $candidateLicensee = $bytes[6];
        $candidateEngine = function_exists('gp_engine_from_version')
            ? (string)(\gp_engine_from_version($candidateVersion) ?? '')
            : '';
        if (!in_array($candidateEngine, ['UE1', 'UE2', 'UE3'], true)) {
            return null;
        }

        $integerOffsets = [8, 12, 16, 20, 24, 28, 32];
        $spacePaddedFields = 0;
        foreach ($integerOffsets as $offset) {
            if (
                ($bytes[$offset + 1] ?? -1) === 0x20
                && ($bytes[$offset + 2] ?? -1) === 0x20
                && ($bytes[$offset + 3] ?? -1) === 0x20
            ) {
                $spacePaddedFields++;
            }
        }
        if ($spacePaddedFields < 4) {
            return null;
        }

        $stream = @fopen($path, 'rb');
        if (!is_resource($stream)) {
            return null;
        }

        $physicalSize = 0;
        $zeroBytes = 0;
        $spaceBytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 1024 * 1024);
                if (!is_string($chunk) || $chunk === '') {
                    break;
                }
                $physicalSize += strlen($chunk);
                $zeroBytes += substr_count($chunk, "\x00");
                $spaceBytes += substr_count($chunk, "\x20");
            }
        } finally {
            fclose($stream);
        }

        if ($physicalSize < 36 || $zeroBytes !== 0 || $spaceBytes < 16) {
            return null;
        }

        return [
            'package_version' => $actualVersion,
            'licensee_version' => $actualLicensee,
            'candidate_package_version' => $candidateVersion,
            'candidate_licensee_version' => $candidateLicensee,
            'candidate_engine_hint' => $candidateEngine,
            'zero_bytes' => $zeroBytes,
            'space_bytes' => $spaceBytes,
            'physical_size' => $physicalSize,
            'space_padded_header_fields' => $spacePaddedFields,
            'header_hex' => strtoupper(bin2hex($header)),
        ];
    }
}
