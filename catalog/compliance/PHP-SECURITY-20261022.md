# PHP critical CVE remediation — 2026-10-22

**Security advisories:** CVE-2026-109618 / CVE-2026-109619 (critical unauthenticated remote code execution). PHP published patched releases 2026-10-21. Source: https://www.php.net/archive/2026.php#2026-10-21-5

## Installation inventory and tested results

| Item | DEV: game-amd | PRD: Lelly-pc |
|---|---|---|
| Before | PHP 8.5.9 VS17 x64 Thread Safe | PHP 8.5.9 VS17 x64 Thread Safe |
| Apache runtime | **8.5.12 — PATCHED** | **8.5.12 — PATCHED** |
| Apache service | `ApacheHTTPServer` RUNNING | `Apache2.4` RUNNING |
| Apache PHP module | `D:\php8.5\php8apache2_4.dll` (8.5.12) | `C:\php8.5.12\php8apache2_4.dll` (8.5.12) |
| CLI | `D:\php8.5\php.exe` **8.5.12** | `C:\php8.5.12\php.exe` 8.5.12 **available**; legacy `C:\php8.5\php.exe` remains **8.5.9**, locked by an unidentified process. |
| Validation | CLI `php -v` 8.5.12; Apache `httpd -t` Syntax OK; PDO, pdo_mysql, mysqli, mbstring, curl, rar, archive, FFI present; running service | Apache RUNNING; locally routed HTTPS headers `Server: Apache/2.4.68 ... PHP/8.5.12` and `X-Powered-By: PHP/8.5.12`, plus active `PHPIniDir C:/php8.5.12` and module path. |
| Open | None identified for web-serving PHP | **Residual legacy PHP 8.5.9 CLI tree:** do not consider all PHP executables patched until locks resolved and old folder removed or replaced. |

**Official package:** `php-8.5.12-Win32-vs17-x64.zip` (VS17 x64 **Thread Safe**).
Verified SHA-256 `660f3ff6ccbdf60f9607ef54bdba85ee22a58d61fd3633b38a93d57fb7c3d184` matches https://www.php.net/downloads.php?os=windows&version=8.5.
No binary hex-patching or unofficial micro-patch was attempted.

## Recovery and staging

DEV: `D:\PHP-updates\8.5.12\`, including complete backup at `backup-20261022-php-8.5.9`, official ZIP, extraction and upgrade scripts. Active PHP `D:\php8.5`, original `php.ini` and custom extensions retained. DEV Apache stopped/restarted successfully.

PRD via DEV SMB: `\\192.168.0.55\c$\PHP-updates\8.5.12\`, with backed-up `C:\php8.5` and `httpd.conf.backup-before-php-8.5.12`. First direct replacement of `C:\php8.5` failed on locked DLLs and the original Apache service was restarted (initial site still 8.5.9). To avoid locked in-place binaries, a complete independent `C:\php8.5.12` tree was built from original configuration, preserved custom `php_rar.dll`, `php_archive.dll`, `oo2core_9_win64.dll`, and `preload/lzo_runtime.php`, overlaid with verified official 8.5.12 files, and had its ini file paths updated. Apache `httpd.conf` now loads this new PHP 8.5.12 tree. Apache restarted and web-serving PHP 8.5.12 was independently observed.

**Residual task:** There are still locks in the original `C:\php8.5` tree *even though Apache loads the new installation*. A second attempt to copy official binaries into that original tree produced sharing violations on `php.exe`, `php8ts.dll` and supporting DLLs; it was **not accepted as a completed in-place update**. Some unlocked original-tree files may have been overwritten during the attempt, making it unsuitable to use as a coherent installation until repaired. Do not delete the old tree or force-kill unidentified processes. Identify those holders on PRD locally, redirect any scheduled PHP CLI jobs to `C:\php8.5.12\php.exe`, and retire/repair the old tree in a controlled maintenance window. Treat the old path as potentially vulnerable until this is done. PRD's public Apache is patched and unaffected because it uses the separate new tree.

Backups and deployment logs are retained only on the respective machines; do not publish PHP binaries into Git.

## Ongoing controls

Recheck from DEV:

```powershell
& D:\php8.5\php.exe -v
& sc.exe \\192.168.0.55 query Apache2.4
& curl.exe -sSkI --resolve unrealdb.com:443:192.168.0.55 https://unrealdb.com/
```

A successful website HTTP status isn't itself proof of the PHP module version; verify the PHP version in response headers or a narrowly scoped server-side check. Do not assume historical `php.exe` paths now point to the patched distribution.
