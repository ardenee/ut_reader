<?php
declare(strict_types=1);

/**
 * Minimal UT4-era UE4 asset-registry identity reader.
 *
 * This intentionally reads only the package identity fields written by
 * FAssetData::operator<< in the package AssetRegistryDataOffset section:
 * ObjectPath, PackagePath, AssetClass, GroupNames, PackageName, AssetName,
 * tags, ChunkIDs, PackageFlags.
 */
final class CatalogUE4AssetRegistryBinaryReader
{
    private string $buf;
    private int $len;
    private int $pos = 0;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->len = strlen($buf);
    }

    public function tell(): int { return $this->pos; }
    public function seek(int $pos): void { $this->pos = max(0, min($pos, $this->len)); }
    public function remaining(): int { return $this->len - $this->pos; }

    public function bytes(int $count): string
    {
        if ($count < 0 || $this->pos + $count > $this->len) {
            throw new OutOfBoundsException('AssetRegistry read overrun count=' . $count . ' pos=' . $this->pos . ' len=' . $this->len);
        }
        $out = substr($this->buf, $this->pos, $count);
        $this->pos += $count;
        return $out;
    }

    public function u16(): int { return unpack('v', $this->bytes(2))[1]; }
    public function u32(): int { return (int)unpack('V', $this->bytes(4))[1]; }
    public function i32(): int { $v = $this->u32(); return ($v & 0x80000000) ? $v - 0x100000000 : $v; }

    public function fstring(): string
    {
        $length = $this->i32();
        if ($length === 0) {
            return '';
        }
        if ($length > 0) {
            if ($length > 1048576 || $length > $this->remaining()) {
                throw new OutOfBoundsException('Bad AssetRegistry FString length=' . $length . ' pos=' . $this->pos);
            }
            $raw = $this->bytes($length);
            if ($raw !== '' && substr($raw, -1) === "\0") {
                $raw = substr($raw, 0, -1);
            }
            $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,ISO-8859-1,Windows-1252');
            return $out === false ? $raw : $out;
        }

        $chars = -$length;
        $bytes = $chars * 2;
        if ($chars > 524288 || $bytes > $this->remaining()) {
            throw new OutOfBoundsException('Bad AssetRegistry wide FString length=' . $length . ' pos=' . $this->pos);
        }
        $raw = $this->bytes($bytes);
        if (substr($raw, -2) === "\0\0") {
            $raw = substr($raw, 0, -2);
        }
        $out = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        return $out === false ? '' : $out;
    }
}

function catalog_ue4_asset_registry_name_text(array $names, int $index, int $number = 0): string
{
    if ($index < 0 || !isset($names[$index])) {
        return '';
    }
    $text = (string)($names[$index]['name'] ?? $names[$index]['text'] ?? '');
    if ($number > 0 && $text !== '') {
        return $text . '_' . ($number - 1);
    }
    return $text;
}

/** @return array{index:int,number:int,text:string} */
function catalog_ue4_asset_registry_read_fname(CatalogUE4AssetRegistryBinaryReader $reader, array $names): array
{
    $index = $reader->i32();
    $number = $reader->i32();
    return ['index' => $index, 'number' => $number, 'text' => catalog_ue4_asset_registry_name_text($names, $index, $number)];
}

function catalog_ue4_asset_registry_normalize_package_name(string $value): string
{
    $value = trim(str_replace('\\', '/', $value));
    if ($value === '' || !str_starts_with($value, '/')) {
        return '';
    }
    $value = preg_replace('#/+#', '/', $value) ?? $value;
    $value = rtrim($value, '. ');
    return $value;
}

function catalog_ue4_asset_registry_package_from_object_path(string $objectPath): string
{
    $objectPath = catalog_ue4_asset_registry_normalize_package_name($objectPath);
    if ($objectPath === '') {
        return '';
    }
    $dot = strrpos($objectPath, '.');
    if ($dot === false || $dot <= 0) {
        return $objectPath;
    }
    return substr($objectPath, 0, $dot);
}

/**
 * @return array{ok:bool,package_name:string,object_path:string,asset_name:string,asset_class:string,package_path:string,asset_count:int,assets:list<array<string,string>>,error:string}
 */
function catalog_ue4_asset_registry_identity_from_file(string $path, array $header, array $names): array
{
    $empty = [
        'ok' => false,
        'package_name' => '',
        'object_path' => '',
        'asset_name' => '',
        'asset_class' => '',
        'package_path' => '',
        'asset_count' => 0,
        'assets' => [],
        'error' => '',
    ];

    $offset = (int)($header['assetRegistryDataOffset'] ?? 0);
    if ($offset <= 0) {
        $empty['error'] = 'AssetRegistryDataOffset is empty.';
        return $empty;
    }
    if (!is_file($path)) {
        $empty['error'] = 'Package file is not readable.';
        return $empty;
    }
    $bytes = @file_get_contents($path);
    if (!is_string($bytes) || $bytes === '') {
        $empty['error'] = 'Package bytes could not be read.';
        return $empty;
    }
    if ($offset >= strlen($bytes)) {
        $empty['error'] = 'AssetRegistryDataOffset is outside the package.';
        return $empty;
    }

    try {
        $reader = new CatalogUE4AssetRegistryBinaryReader($bytes);
        $reader->seek($offset);
        $count = $reader->i32();
        if ($count < 0 || $count > 10000) {
            throw new RuntimeException('Invalid asset registry asset count ' . $count . ' at offset ' . $offset);
        }

        $version = (int)($header['version'] ?? 0);
        $assets = [];
        $packageNames = [];
        $objectPaths = [];
        for ($i = 0; $i < $count; $i++) {
            $objectPath = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];
            $packagePath = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];
            $assetClass = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];
            $groupNames = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];
            $packageName = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];
            $assetName = (string)catalog_ue4_asset_registry_read_fname($reader, $names)['text'];

            $tagCount = $reader->i32();
            if ($tagCount < 0 || $tagCount > 20000) {
                throw new RuntimeException('Invalid asset registry tag count ' . $tagCount . ' for asset #' . $i);
            }
            for ($tag = 0; $tag < $tagCount; $tag++) {
                catalog_ue4_asset_registry_read_fname($reader, $names);
                $reader->fstring();
            }

            if ($version >= 429) {
                $chunkCount = $reader->i32();
                if ($chunkCount < 0 || $chunkCount > 1048576) {
                    throw new RuntimeException('Invalid asset registry chunk count ' . $chunkCount . ' for asset #' . $i);
                }
                for ($chunk = 0; $chunk < $chunkCount; $chunk++) {
                    $reader->i32();
                }
            } elseif ($version >= 278) {
                $reader->i32();
            }

            if ($version >= 482) {
                $reader->u32();
            }

            $normalizedPackageName = catalog_ue4_asset_registry_normalize_package_name($packageName);
            $normalizedObjectPackage = catalog_ue4_asset_registry_package_from_object_path($objectPath);
            $normalizedObjectPath = catalog_ue4_asset_registry_normalize_package_name($objectPath);
            if ($normalizedPackageName === '' && $normalizedObjectPackage !== '') {
                $normalizedPackageName = $normalizedObjectPackage;
            }
            if ($normalizedPackageName !== '') {
                $packageNames[$normalizedPackageName] = true;
            }
            if ($normalizedObjectPath !== '') {
                $objectPaths[$normalizedObjectPath] = true;
            }
            $assets[] = [
                'object_path' => $normalizedObjectPath,
                'package_name' => $normalizedPackageName,
                'package_path' => catalog_ue4_asset_registry_normalize_package_name($packagePath),
                'asset_name' => $assetName,
                'asset_class' => $assetClass,
                'group_names' => $groupNames,
            ];
        }

        $packages = array_keys($packageNames);
        if (count($packages) !== 1) {
            $empty['asset_count'] = $count;
            $empty['assets'] = $assets;
            $empty['error'] = count($packages) === 0
                ? 'No package name was found in asset registry data.'
                : 'Multiple package names were found in one package asset registry: ' . implode(', ', $packages);
            return $empty;
        }

        $chosen = $packages[0];
        $primary = $assets[0] ?? [];
        foreach ($assets as $asset) {
            if (($asset['package_name'] ?? '') === $chosen && ($asset['asset_name'] ?? '') !== '') {
                $primary = $asset;
                break;
            }
        }

        return [
            'ok' => true,
            'package_name' => $chosen,
            'object_path' => (string)($primary['object_path'] ?? (array_key_first($objectPaths) ?: '')),
            'asset_name' => (string)($primary['asset_name'] ?? ''),
            'asset_class' => (string)($primary['asset_class'] ?? ''),
            'package_path' => (string)($primary['package_path'] ?? ''),
            'asset_count' => $count,
            'assets' => $assets,
            'error' => '',
        ];
    } catch (Throwable $e) {
        $empty['error'] = $e->getMessage();
        return $empty;
    }
}
