<?php
/**
 * Content classifier for files extracted from archive containers.
 *
 * Archive member extensions are not authoritative. Historic mirrors contain
 * nested RAR/ZIP/7z or Unreal redirect streams renamed with package extensions,
 * while some sidecar files use package-like extensions without containing a
 * package. Classification is deliberately conservative: valid Unreal package
 * magic always wins and unknown bytes continue through the established parser.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

final class CatalogArchiveMemberContentClassifier
{
    private const PROBE_BYTES = 131072;
    private const UE2_BLOCK_BYTES = 32768;
    private const MAX_UZ2_COMPRESSED_BLOCK = 131072;

    /**
     * @return array{kind:string,format:string,reason:string}
     */
    public function classify(string $path, string $originalName, string $archiveEntryPath = ''): array
    {
        if (!is_file($path) || !is_readable($path) || is_link($path)) {
            return $this->result('unknown', '', 'Archive member source is unavailable for content classification.');
        }

        $size = filesize($path);
        if ($size === false || $size < 1) {
            return $this->result('unknown', '', 'Archive member is empty.');
        }

        $prefix = $this->readPrefix($path, min((int)$size, self::PROBE_BYTES));
        if ($prefix === '') {
            return $this->result('unknown', '', 'Archive member prefix is unavailable.');
        }

        // A real Unreal package must never be excluded merely because its filename
        // uses an unusual extension such as .md5.
        if ($this->hasUnrealPackageMagic($prefix)) {
            return $this->result('package', '', 'Unreal package magic detected.');
        }

        $entryPath = str_replace('\\', '/', $archiveEntryPath !== '' ? $archiveEntryPath : $originalName);
        if ($this->isAppleDoubleMetadata($prefix, $entryPath, $originalName)) {
            return $this->result('skip', '', 'AppleDouble/macOS metadata member.');
        }

        $archiveFormat = $this->archiveFormat($path, $prefix);
        if ($archiveFormat !== '') {
            return $this->result('archive', $archiveFormat, strtoupper($archiveFormat) . ' container detected by content.');
        }

        $redirectFormat = $this->redirectFormat($path, $prefix, (int)$size);
        if ($redirectFormat !== '') {
            return $this->result('redirect', $redirectFormat, strtoupper($redirectFormat) . ' redirect stream detected by content.');
        }

        if ($this->isMasterMd5Sidecar($prefix, $originalName)) {
            return $this->result('skip', '', 'Unreal MasterMD5 sidecar text; no Unreal package magic present.');
        }

        $placeholderReason = $this->placeholderReason($prefix, $originalName, (int)$size);
        if ($placeholderReason !== '') {
            return $this->result('skip', '', $placeholderReason);
        }

        // Unknown bytes deliberately continue through the production package
        // parser. This preserves detection of genuine corruption and uncommon
        // package variants instead of turning the classifier into a new parser.
        return $this->result('unknown', '', 'No special archive-member content signature detected.');
    }

    public function hasUnrealPackageMagic(string $bytes): bool
    {
        if (strlen($bytes) < 4) {
            return false;
        }
        return \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedBytes($bytes);
    }

    private function isAppleDoubleMetadata(string $prefix, string $entryPath, string $originalName): bool
    {
        if (!str_starts_with($prefix, "\x00\x05\x16\x07")) {
            return false;
        }
        $normalized = '/' . ltrim(str_replace('\\', '/', $entryPath), '/');
        $base = basename(str_replace('\\', '/', $originalName));
        return str_contains($normalized, '/__MACOSX/') || str_starts_with($base, '._');
    }

    private function archiveFormat(string $path, string $prefix): string
    {
        if (str_starts_with($prefix, "Rar!\x1A\x07\x00") || str_starts_with($prefix, "Rar!\x1A\x07\x01\x00")) {
            return 'rar';
        }
        if (str_starts_with($prefix, "7z\xBC\xAF\x27\x1C")) {
            return '7z';
        }
        if (str_starts_with($prefix, "PK\x03\x04")
            || str_starts_with($prefix, "PK\x05\x06")
            || str_starts_with($prefix, "PK\x07\x08")) {
            return 'zip';
        }

        // Legal ZIP self-extractors may have a prepended stub. Let libzip prove
        // the complete file is a ZIP rather than relying only on byte zero.
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $opened = $zip->open($path, \ZipArchive::RDONLY);
            if ($opened === true) {
                $zip->close();
                return 'zip';
            }
        }
        return '';
    }

    private function redirectFormat(string $path, string $prefix, int $fileSize): string
    {
        if ($this->looksLikeUz2($path, $prefix, $fileSize)) {
            return 'uz2';
        }
        if ($this->looksLikeUz3($prefix, $fileSize)) {
            return 'uz3';
        }
        return '';
    }

    private function looksLikeUz2(string $path, string $prefix, int $fileSize): bool
    {
        if (strlen($prefix) < 10 || $fileSize < 10) {
            return false;
        }
        $sizes = unpack('Vcompressed/Vuncompressed', substr($prefix, 0, 8));
        $compressed = (int)($sizes['compressed'] ?? 0);
        $uncompressed = (int)($sizes['uncompressed'] ?? 0);
        if ($compressed < 1
            || $compressed > self::MAX_UZ2_COMPRESSED_BLOCK
            || $uncompressed < 1
            || $uncompressed > self::UE2_BLOCK_BYTES
            || 8 + $compressed > $fileSize) {
            return false;
        }

        $payload = strlen($prefix) >= 8 + $compressed
            ? substr($prefix, 8, $compressed)
            : $this->readRange($path, 8, $compressed);
        if (strlen($payload) !== $compressed || !$this->hasZlibHeader($payload)) {
            return false;
        }
        if (!function_exists('gzuncompress')) {
            // The structural record signature is already strong, but without a
            // decoder do not reclassify an extension-mismatched package.
            return false;
        }
        try {
            $decoded = @gzuncompress($payload, $uncompressed);
        } catch (\Throwable) {
            $decoded = false;
        }
        return is_string($decoded)
            && strlen($decoded) === $uncompressed
            && $this->hasUnrealPackageMagic($decoded);
    }

    private function looksLikeUz3(string $prefix, int $fileSize): bool
    {
        if (strlen($prefix) < 10 || $fileSize <= 8) {
            return false;
        }
        $header = unpack('Vtag/Vuncompressed', substr($prefix, 0, 8));
        $tag = (int)($header['tag'] ?? 0);
        $uncompressed = (int)($header['uncompressed'] ?? 0);
        if ($tag !== 5678 || $uncompressed < 1) {
            return false;
        }
        return $this->hasZlibHeader(substr($prefix, 8, 2));
    }

    private function hasZlibHeader(string $bytes): bool
    {
        if (strlen($bytes) < 2) {
            return false;
        }
        $cmf = ord($bytes[0]);
        $flg = ord($bytes[1]);
        return ($cmf & 0x0F) === 8 && (($cmf << 8) + $flg) % 31 === 0;
    }

    private function isMasterMd5Sidecar(string $prefix, string $originalName): bool
    {
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'md5') {
            return false;
        }
        $text = $this->normalizedTextPrefix($prefix);
        return str_contains($text, 'executing class engine.mastermd5');
    }

    private function placeholderReason(string $prefix, string $originalName, int $fileSize): string
    {
        $text = $this->normalizedTextPrefix($prefix);
        foreach ([
            'this file is a place holder',
            'this file is a placeholder',
            'this is a place-holder file',
            'this is a placeholder file',
            'this is not the map',
        ] as $marker) {
            if (str_contains($text, $marker)) {
                return 'Intentional non-package placeholder text detected: ' . $marker . '.';
            }
        }

        // Some historical map-pack placeholders contain only the package stem,
        // e.g. a file named ONS-Dinora-32p.unr whose entire payload is the text
        // "ONS-Dinora-32p". Restrict this rule to very small printable files.
        if ($fileSize <= 512 && $this->isPrintableText($prefix)) {
            $payload = trim(str_replace(["\r", "\n", "\0"], '', $prefix));
            $stem = (string)pathinfo($originalName, PATHINFO_FILENAME);
            if ($payload !== '' && $stem !== '' && strcasecmp($payload, $stem) === 0) {
                return 'Intentional filename-only non-package placeholder detected.';
            }
        }
        return '';
    }

    private function isPrintableText(string $bytes): bool
    {
        if ($bytes === '') {
            return false;
        }
        $sample = substr($bytes, 0, 4096);
        $printable = preg_replace('/[\x09\x0A\x0D\x20-\x7E]/', '', $sample);
        return $printable === '';
    }

    private function normalizedTextPrefix(string $bytes): string
    {
        $sample = substr($bytes, 0, 8192);
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            $sample = substr($sample, 3);
        }
        return strtolower(trim($sample));
    }

    private function readPrefix(string $path, int $bytes): string
    {
        return $this->readRange($path, 0, max(1, $bytes));
    }

    private function readRange(string $path, int $offset, int $bytes): string
    {
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            return '';
        }
        try {
            if ($offset > 0 && fseek($handle, $offset, SEEK_SET) !== 0) {
                return '';
            }
            $data = fread($handle, max(1, $bytes));
            return is_string($data) ? $data : '';
        } finally {
            fclose($handle);
        }
    }

    /** @return array{kind:string,format:string,reason:string} */
    private function result(string $kind, string $format, string $reason): array
    {
        return ['kind' => $kind, 'format' => $format, 'reason' => $reason];
    }
}
