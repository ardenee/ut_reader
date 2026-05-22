<?php

namespace net\shrimpworks\unreal\packages;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/TUnrealPackage.php';

/**
 * Compatibility wrapper for older code that expected a Package class.
 *
 * The previous version of this file was an incomplete Java-to-PHP port and was
 * not valid PHP in this project. This wrapper delegates parsing to the active
 * TUnrealPackage.php reader and exposes simple header/name/import/export access.
 */
class Package
{
    public const PKG_SIGNATURE = 0x9E2A83C1;

    private string $filePath;
    private object $reader;

    public int $version = 0;
    public int $license = 0;
    public int $engineVersion = 0;
    public int $flags = 0;

    public array $header = [];
    public array $names = [];
    public array $exports = [];
    public array $imports = [];
    public array $objects = [];
    public array $fields = [];

    public function __construct(string $packageFile)
    {
        if (!is_file($packageFile)) {
            throw new RuntimeException('File not found: ' . $packageFile);
        }
        if (!is_readable($packageFile)) {
            throw new RuntimeException('File is not readable by PHP/Web Station: ' . $packageFile);
        }

        $this->filePath = $packageFile;
        $this->reader = \TPackageReader::open($packageFile);

        if (method_exists($this->reader, 'annotateTablesWithText')) {
            $this->reader->annotateTablesWithText();
        }

        $this->header = $this->reader->getHeader();
        $this->names = $this->reader->getNames();
        $this->exports = $this->reader->getExports();
        $this->imports = $this->reader->getImports();

        $this->version = (int)($this->header['version'] ?? 0);
        $this->engineVersion = $this->version;
        $this->license = (int)($this->header['licensee'] ?? $this->header['licenseeVersion'] ?? 0);
        $this->flags = (int)($this->header['pkgFlags'] ?? $this->header['packageFlags'] ?? 0);
    }

    public function close(): void
    {
        // TPackageReader currently loads/parses from a file path and does not expose a close method.
    }

    public function sha1Hash(): string
    {
        $hash = sha1_file($this->filePath);
        if ($hash === false) {
            throw new RuntimeException('Unable to calculate SHA1 for: ' . $this->filePath);
        }
        return $hash;
    }

    public function flags(): array
    {
        return $this->decodePackageFlags($this->flags);
    }

    public function packageImports(): array
    {
        $out = [];
        foreach ($this->imports as $i => $import) {
            $row = self::rowRaw((array)$import);
            $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['packageIndex'] ?? $row['PackageIndex'] ?? 0);
            if ($outer === 0) {
                $out[$i] = $import;
            }
        }
        return $out;
    }

    public function rootExports(): array
    {
        $out = [];
        foreach ($this->exports as $i => $export) {
            $row = self::rowRaw((array)$export);
            $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['packageIndex'] ?? $row['PackageIndex'] ?? 0);
            if ($outer === 0) {
                $out[$i] = $export;
            }
        }
        return $out;
    }

    public function exportsByClassName(string $className): array
    {
        $out = [];
        foreach ($this->exports as $i => $export) {
            $row = (array)$export;
            $view = self::rowView($row);
            $class = (string)($view['classNameText'] ?? $row['classNameText'] ?? '');
            if ($class !== '' && strcasecmp($class, $className) === 0) {
                $out[$i] = $export;
            }
        }
        return $out;
    }

    public function objectsByClassName(string $className): array
    {
        return $this->exportsByClassName($className);
    }

    public function objectByRef($ref)
    {
        $index = is_int($ref) ? $ref : (int)($ref->index ?? 0);
        if ($index <= 0) {
            return null;
        }
        return $this->exports[$index - 1] ?? null;
    }

    public function objectByName($name)
    {
        $needle = is_string($name) ? $name : (string)($name->name ?? '');
        foreach ($this->exports as $export) {
            $row = (array)$export;
            $view = self::rowView($row);
            $objectName = (string)($view['objectNameText'] ?? $row['objectNameText'] ?? $row['name'] ?? '');
            if ($objectName !== '' && strcasecmp($objectName, $needle) === 0) {
                return $export;
            }
        }
        return null;
    }

    public function objectByExport($export)
    {
        return $export;
    }

    public function getReader(): object
    {
        return $this->reader;
    }

    private static function rowRaw(array $row): array
    {
        return isset($row['raw']) && is_array($row['raw']) ? $row['raw'] : $row;
    }

    private static function rowView(array $row): array
    {
        return isset($row['view']) && is_array($row['view']) ? $row['view'] : [];
    }

    private function decodePackageFlags(int $flags): array
    {
        $map = [
            0x00000001 => 'PKG_AllowDownload',
            0x00000002 => 'PKG_ClientOptional',
            0x00000004 => 'PKG_ServerSideOnly',
            0x00000008 => 'PKG_BrokenLinks',
            0x00000010 => 'PKG_Unsecure',
            0x00008000 => 'PKG_Need',
        ];

        $out = [];
        foreach ($map as $bit => $name) {
            if (($flags & $bit) !== 0) {
                $out[] = $name;
            }
        }
        return $out;
    }
}

if (PHP_SAPI !== 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '1');

    function package_h($s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $file = isset($_GET['file']) ? trim((string)$_GET['file']) : '';
    if ($file === '') {
        foreach ([__DIR__ . '/test.utx', __DIR__ . '/oldtest.utx', __DIR__ . '/uploads/test.utx'] as $candidate) {
            if (is_file($candidate)) {
                $file = $candidate;
                break;
            }
        }
    }

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Package.php</title>';
    echo '<style>body{font-family:system-ui;margin:24px;background:#111;color:#ddd}input{padding:6px 8px;margin:4px;background:#1b1b1b;color:#ddd;border:1px solid #444}table{border-collapse:collapse;width:100%;margin:12px 0 24px}th,td{border:1px solid #333;padding:6px 8px;text-align:left;vertical-align:top}th{background:#222}.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace}.err{color:#ff9f9f;background:#2a1116;border:1px solid #55303a;border-radius:8px;padding:10px}.muted{color:#999}</style>';
    echo '</head><body><h1>Package.php compatibility viewer</h1>';
    echo '<form method="get"><label>File: <input type="text" name="file" value="' . package_h($file) . '" style="width:620px"></label><input type="submit" value="Open"></form>';

    if ($file === '') {
        echo '<p class="muted">Enter a full Synology path, for example <span class="mono">/volume1/web/ut_reader/uploads/test.utx</span>.</p></body></html>';
        exit;
    }

    try {
        $pkg = new Package($file);
        echo '<p class="mono">' . package_h($file) . '</p>';
        echo '<table><tbody>';
        echo '<tr><th>Version</th><td>' . package_h($pkg->version) . '</td></tr>';
        echo '<tr><th>License</th><td>' . package_h($pkg->license) . '</td></tr>';
        echo '<tr><th>Flags</th><td class="mono">0x' . package_h(str_pad(strtoupper(dechex($pkg->flags)), 8, '0', STR_PAD_LEFT)) . ' ' . package_h(implode(', ', $pkg->flags())) . '</td></tr>';
        echo '<tr><th>Names</th><td>' . count($pkg->names) . '</td></tr>';
        echo '<tr><th>Imports</th><td>' . count($pkg->imports) . '</td></tr>';
        echo '<tr><th>Exports</th><td>' . count($pkg->exports) . '</td></tr>';
        echo '<tr><th>SHA1</th><td class="mono">' . package_h($pkg->sha1Hash()) . '</td></tr>';
        echo '</tbody></table>';

        echo '<h2>Header</h2><pre class="mono">' . package_h(print_r($pkg->header, true)) . '</pre>';
    } catch (Throwable $t) {
        echo '<div class="err"><strong>Error:</strong> ' . package_h($t->getMessage()) . '</div>';
    }

    echo '</body></html>';
}
