# UT Reader

UT Reader is a utility project for reading, inspecting, decompressing, and processing Unreal Tournament / Unreal Engine package-related files.

> **Status:** This project is still in active development. The code, behaviour, supported file formats, script names, and documentation may change while the project is being built and tested.

## Project Description

UT Reader is intended to provide browser-based PHP tools for inspecting and testing Unreal package data, including older Unreal Tournament package files and newer UE3 package files.

The project currently includes experimental readers/viewers and helper scripts for:

- Unreal package tables: names, imports, exports, headers, flags, offsets, and sizes.
- UE1 / UE2 / UE3 package inspection.
- Compressed UE3 package handling.
- UZ / UZ3 helper workflows.
- UMOD inspection.
- Test/development parsing output.

The project is not yet a stable library or finished application. Many files are development/debug viewers used while the parser is being built.

## Current Purpose

The script/project is being developed to:

- Read UT / Unreal Engine package files.
- Parse useful package metadata.
- Display parsed data in a browser.
- Help test package decompression and table parsing.
- Provide a base for future conversion, extraction, or indexing workflows.

## Main Runtime Requirements

- PHP 8.2 or newer is recommended.
- Web server capable of running PHP, such as Synology Web Station, Apache, nginx + PHP-FPM, or local PHP development server.
- Writable `uploads/` folder for browser upload tools.
- Optional but recommended for compressed UE3 packages: native LZO support through PHP FFI and `liblzo2`.

## LZO Dependency for Compressed UE3 Packages

Some UE3 packages use LZO compression. In this project, codec `2` is handled through:

- `UE_LZO1X_register.php`
- `lzo_runtime.php`
- the bundled fallback class `UE_LZO1X` inside `TUnrealPackage.php`

The preferred path is native LZO through PHP FFI because it is faster and more reliable. If native LZO is not available, the project attempts to fall back to the bundled pure-PHP decoder.

### Files involved

`viewer2.php` loads:

```php
require_once __DIR__ . '/TUnrealPackage.php';
require_once __DIR__ . '/UE_LZO1X_register.php';
```

`UE_LZO1X_register.php` then loads `lzo_runtime.php` if present and registers codec `2`.

`lzo_runtime.php` tries to find a usable LZO shared library from:

- `LZO_DLL` constant, if defined.
- `LZO_DLL` environment variable, if set.
- repo-local files such as `liblzo2.so`, `liblzo2.so.2`, `lzo2.dll`, or `liblzo2-2.dll`.
- common Linux paths such as `/usr/lib/...` or `/usr/local/lib/...`.
- common Windows test paths such as `D:/php8/ext/liblzo2-2.dll`.

## Synology / Linux LZO Setup

### 1. Install Entware if `opkg` is missing

Check:

```bash
opkg update
```

If you get `opkg: command not found`, install Entware. On x86_64 Synology / DSM VM:

```bash
cd /tmp
wget -O entware-install.sh https://bin.entware.net/x64-k3.2/installer/generic.sh
sudo sh entware-install.sh
export PATH=/opt/bin:/opt/sbin:$PATH
```

For other CPU architectures, use the matching Entware installer. Check architecture with:

```bash
uname -m
```

### 2. Install LZO

On Entware, the package name is usually `liblzo`, not `lzo`:

```bash
sudo opkg update
sudo opkg list | grep -i lzo
sudo opkg install liblzo
```

Find the installed library:

```bash
sudo find /opt -name "liblzo2.so*" -o -name "*lzo*.so*"
```

Expected example:

```text
/opt/lib/liblzo2.so.2
/opt/lib/liblzo2.so.2.0.0
```

Copy it into the project folder:

```bash
sudo cp /opt/lib/liblzo2.so.2 /volume1/web/ut_reader/liblzo2.so
sudo chmod 755 /volume1/web/ut_reader/liblzo2.so
```

Do **not** commit `liblzo2.so` to GitHub. It is a local native binary and is OS/CPU-specific.

### 3. Enable PHP FFI on Synology

In the PHP profile used by Web Station, FFI must be enabled.

Use Linux/Synology settings like:

```ini
extension=ffi
ffi.enable=true
```

Do **not** use Windows paths on Synology, such as:

```ini
extension_dir = "D:/php8/ext"
auto_prepend_file="D:/php8/preload/lzo_runtime.php"
```

`auto_prepend_file` is not needed for this project because the viewer loads `UE_LZO1X_register.php` directly.

Restart Web Station / PHP-FPM after changing PHP settings.

### 4. Test LZO from SSH

CLI PHP and Web Station PHP can use different PHP configs, but this is still useful:

```bash
php -m | grep -i ffi
php -i | grep -i "ffi.enable"
php -r "require '/volume1/web/ut_reader/lzo_runtime.php'; echo function_exists('lzo1x_decompress') ? 'LZO OK' : 'NO LZO';"
```

Expected:

```text
LZO OK
```

### 5. Test LZO through the browser

Create a temporary browser test file:

```bash
cat > /volume1/web/ut_reader/lzo-test.php <<'PHP'
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo 'FFI extension: ' . (extension_loaded('FFI') ? 'loaded' : 'not loaded') . "<br>";
echo 'ffi.enable: ' . ini_get('ffi.enable') . "<br>";

require __DIR__ . '/lzo_runtime.php';

echo 'lzo1x_decompress: ' . (function_exists('lzo1x_decompress') ? 'available' : 'missing') . "<br>";
echo 'lib local: ' . (is_file(__DIR__ . '/liblzo2.so') ? 'found' : 'missing') . "<br>";
PHP
```

Open:

```text
/lzo-test.php
```

Expected output:

```text
FFI extension: loaded
ffi.enable: true
lzo1x_decompress: available
lib local: found
```

Remove the test file when finished:

```bash
rm /volume1/web/ut_reader/lzo-test.php
```

## Windows LZO Setup

For Windows PHP development, use a Windows LZO DLL and enable FFI in `php.ini`.

### 1. Place the DLL

Known working/tested names include:

```text
D:/php8/ext/liblzo2-2.dll
D:/php8/ext/lzo2.dll
```

The DLL must match the PHP architecture:

- 64-bit PHP needs a 64-bit DLL.
- 32-bit PHP needs a 32-bit DLL.
- Thread-safe vs non-thread-safe usually matters for PHP extensions, less for FFI-loaded DLLs, but the DLL still must be compatible with the runtime.

### 2. Enable FFI in `php.ini`

Example Windows development config:

```ini
extension=ffi
ffi.enable=true
```

Older/test configs may use:

```ini
extension=php_ffi
ffi.enable=1
```

Use whichever matches your PHP build. Confirm with:

```powershell
php -m | findstr /I ffi
php -i | findstr /I "ffi.enable"
```

### 3. Make the DLL visible to the repo

The runtime checks common Windows paths automatically, including:

```text
D:/php8/ext/liblzo2-2.dll
D:/php8/ext/lzo2.dll
```

You can also copy the DLL beside the project as either:

```text
liblzo2-2.dll
lzo2.dll
```

or set an environment variable before running PHP:

```powershell
$env:LZO_DLL = "D:/php8/ext/liblzo2-2.dll"
php -r "require 'lzo_runtime.php'; echo function_exists('lzo1x_decompress') ? 'LZO OK' : 'NO LZO';"
```

### 4. Test on Windows

From the repo folder:

```powershell
php -r "require 'lzo_runtime.php'; echo function_exists('lzo1x_decompress') ? 'LZO OK' : 'NO LZO';"
```

Expected:

```text
LZO OK
```

## Git Ignore for Native Libraries

Native LZO libraries should not be committed because they are platform-specific binaries.

Add these to `.gitignore`:

```gitignore
# Local native libraries
liblzo2.so
liblzo2.so.*
liblzo2-2.dll
lzo2.dll
```

On PowerShell, append them with:

```powershell
Add-Content .gitignore ""
Add-Content .gitignore "# Local native libraries"
Add-Content .gitignore "liblzo2.so"
Add-Content .gitignore "liblzo2.so.*"
Add-Content .gitignore "liblzo2-2.dll"
Add-Content .gitignore "lzo2.dll"

git add .gitignore
git commit -m "Ignore local native LZO libraries"
git push
```

## Expected Usage

General workflow:

1. Pull/update the repository.
2. Ensure PHP can run the scripts through Web Station or a local PHP server.
3. Ensure `uploads/` exists and is writable by PHP.
4. Upload or place package files into the project.
5. Open a viewer script such as `viewer2.php`, `UE1.php`, `UE2.php`, or `readfile7d.php`.
6. Review names, imports, exports, headers, flags, and offsets.
7. For compressed UE3 files, ensure LZO is available or rely on the bundled PHP fallback.

## Development Notes

This repository is not yet considered stable.

Expect changes to:

- File names
- Function names
- Output format
- Configuration options
- Error handling
- Supported input data
- Documentation

Use the project as experimental/development code until a stable version is tagged or documented.

## Security and Privacy Notes

Before committing files to this repository, check that no private data is included.

Avoid committing:

- Personal data
- Credentials
- API keys
- Local configuration files
- Native binary libraries such as `.dll` or `.so` files
- Large generated output files
- Temporary test data
- Logs containing private paths or usernames

## License

No license has been specified yet. Add a license before publishing a stable release or accepting external contributions.
