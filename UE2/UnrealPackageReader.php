<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/TUnrealPackage.php';
require_once dirname(__DIR__) . '/UE_LZO1X_register.php';

final class UnrealPackageReader
{
    private string $path;
    private object $pkg;
    private array $header = [];
    private array $names = [];
    private array $imports = [];
    private array $exports = [];

    private const PKG_FLAGS = [
        0x00000001 => 'PKG_AllowDownload',
        0x00000002 => 'PKG_ClientOptional',
        0x00000004 => 'PKG_ServerSideOnly',
        0x00000008 => 'PKG_NoExportAllowed',
        0x00000010 => 'PKG_Cooked',
        0x00000020 => 'PKG_Encrypted',
    ];

    private const RF_FLAGS = [
        0x00000001 => 'RF_Transactional',
        0x00000002 => 'RF_Unreachable',
        0x00000004 => 'RF_Public',
        0x00000008 => 'RF_TagImp',
        0x00000010 => 'RF_TagExp',
        0x00000020 => 'RF_SourceModified',
        0x00000040 => 'RF_TagGarbage',
        0x00000200 => 'RF_NeedLoad',
        0x00000400 => 'RF_HighlightedName',
        0x00004000 => 'RF_Transient',
        0x00010000 => 'RF_LoadForClient',
        0x00020000 => 'RF_LoadForServer',
        0x00040000 => 'RF_LoadForEdit',
        0x00080000 => 'RF_Standalone',
        0x01000000 => 'RF_NeedPostLoad',
        0x04000000 => 'RF_Native',
    ];

    public function __construct(string $path)
    {
        $this->path = $path;
        $this->pkg = TPackageReader::open($path);

        if (method_exists($this->pkg, 'annotateTablesWithText')) {
            $this->pkg->annotateTablesWithText();
        }

        $this->names = $this->pkg->getNames();
        $this->imports = $this->pkg->getImports();
        $this->exports = $this->pkg->getExports();
        $this->header = $this->normaliseHeader($this->pkg->getHeader());
    }

    private function normaliseHeader(array $h): array
    {
        $h['signature'] = $h['signature'] ?? $h['tag'] ?? 0;
        $h['licensee'] = $h['licensee'] ?? $h['licenseeVersion'] ?? 0;
        $h['pkgFlags'] = $h['pkgFlags'] ?? $h['packageFlags'] ?? 0;

        if (isset($h['guid']) && is_array($h['guid'])) {
            $h['guidArray'] = $h['guid'];
            $h['guid'] = sprintf('%08X-%08X-%08X-%08X', $h['guidArray'][0] ?? 0, $h['guidArray'][1] ?? 0, $h['guidArray'][2] ?? 0, $h['guidArray'][3] ?? 0);
        }

        return $h;
    }

    public function getHeader(): array { return $this->header; }
    public function getNames(): array { return $this->names; }
    public function getImports(): array { return $this->imports; }
    public function getExports(): array { return $this->exports; }

    public function getFileSize(): string
    {
        return is_file($this->path) ? number_format(filesize($this->path)) . ' bytes' : '0 bytes';
    }

    public function getCompressionInfo(): array
    {
        return [
            'isCompressed' => (bool)($this->header['compressed'] ?? false),
            'flags' => (int)($this->header['compressionFlags'] ?? 0),
            'chunks' => $this->header['chunks'] ?? [],
        ];
    }

    public function validatePackage(): array
    {
        $issues = [];

        foreach (['nameCount', 'nameOffset', 'importCount', 'importOffset', 'exportCount', 'exportOffset'] as $k) {
            if (!array_key_exists($k, $this->header)) {
                $issues[] = 'Missing header field: ' . $k;
            }
        }

        if (($this->header['nameCount'] ?? 0) !== count($this->names)) {
            $issues[] = 'Name count mismatch: header=' . ($this->header['nameCount'] ?? 0) . ', parsed=' . count($this->names);
        }

        if (($this->header['importCount'] ?? 0) !== count($this->imports)) {
            $issues[] = 'Import count mismatch: header=' . ($this->header['importCount'] ?? 0) . ', parsed=' . count($this->imports);
        }

        if (($this->header['exportCount'] ?? 0) !== count($this->exports)) {
            $issues[] = 'Export count mismatch: header=' . ($this->header['exportCount'] ?? 0) . ', parsed=' . count($this->exports);
        }

        return $issues;
    }

    public function decodePKG(int $flags): array { return $this->decodeFlags($flags, self::PKG_FLAGS); }
    public function decodeRF(int $flags): array { return $this->decodeFlags($flags, self::RF_FLAGS); }

    private function decodeFlags(int $flags, array $map): array
    {
        $out = [];

        foreach ($map as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $out[] = $name;
            }
        }

        return $out;
    }

    public function getExportProperties(int $exportIndex): ?array { return []; }
    public function getExportProperty(int $exportIndex, string $name, $default = null) { return $default; }
    public function getPropertiesByClass(string $className): array { return []; }
    public function readPropertiesForExport(int $exportIndex): array { return []; }

    public function exportClassName(int $exportIndex): string
    {
        $ex = $this->exports[$exportIndex] ?? null;

        if (!$ex) {
            return '';
        }

        $text = $ex['text']['class'] ?? $ex['classNameText'] ?? '';
        return is_string($text) ? $text : '';
    }

    public function __call(string $name, array $arguments)
    {
        return null;
    }
}
