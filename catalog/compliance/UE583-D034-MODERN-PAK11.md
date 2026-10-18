# UE5-D034 — modern PAK compact directory index (UE5.8.3)

**Status:** original UnrealDB flat-PAK parser bug corrected; tested against real Satisfactory Dedicated Server archive using both DEV local path and PRD SMB read-only fixture. This does **not** certify all Epic PAK versions or encrypted/unencoded layouts.

## Before
Epic UE5.8.3 `UnrealPak.exe -List FactoryGame-WindowsServer.pak` succeeds on the real version-11 archive with **4,761 entries**. UnrealDB `catalog_pak_parse_index` detects a v11 footer, then incorrectly reads its modern index as repeated FStrings and `FPakEntry` structures. It fails with `PAK FString read overrun`, which blocks the production PAK archive importer.

## Epic source and correction
Pinned UE5.8.3 `Engine/Source/Runtime/PakFile/Private/PakFile.cpp`, `FPakFile::LoadIndex`, `LoadIndexInternal`, `LoadIndexInternal_DirectoryIndex` and `DecodePakEntry`; entry-location scheme in `Engine/Source/Runtime/PakFile/Public/IPlatformFilePak.h`. Modern PAK versions >= 10 use SHA1-verified primary and secondary indexes, a directory map referring to compact bit-packed entry metadata, and `FPakInfo` compression method names. The old UnrealDB flat index remains for older layouts; `catalog/lib/CatalogPakModernIndex.php` handles verified **v10/v11** modern full-directory indexes. File payload extraction uses the existing `Uedb5IoStoreCodec` for declared Oodle, Zlib and Gzip methods, with the standard fail-closed missing-library behavior.

Explicit **not yet supported** in the compact index path: newer-than-v11 layouts, full-directory index absent (path-hash-only), the unencoded entry-list path, encrypted indexes or entries, and unverified compression methods. These are not silently treated as legacy layouts. Their Epic-equivalent coverage remains open.

## Reproducible real acceptance
`catalog/bin/verify-ue5-583-pak11-real-satisfactory.php` (read-only source, disposable tiny decoded files) passes for:
- `C:/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak` (DEV)
- `//192.168.0.55/c$/Program Files/Epic Games/SatisfactoryDSExperiment/FactoryGame/Content/Paks/FactoryGame-WindowsServer.pak` (PRD read-only)
- Real PAK v11; indexed **4,761/4,761** entries, matched independent Epic UnrealPak enumeration.
- Five pinned decoded-content SHA1 and byte-length checks, including **three Oodle-compressed files** and two uncompressed ones.
- Source byte parsing from Epic `LoadIndexInternal`; no production catalog import was performed.

## PHP/Oodle deployment
DEV-installed `D:/php8.5/ext/oo2core_9_win64.dll` loads with PHP FFI; `php.ini` already contains `extension_dir="D:/php8.5/ext"`, `extension=ffi` and `ffi.enable=1`. Apache `PHPIniDir="D:/php8.5"`; `httpd.exe -t` passed, so no Apache/PHP configuration edit was necessary. **Do not check in or distribute the proprietary DLL.** CLI Oodle extraction of five real IoStore chunks passed via that DLL with original SHA256 parity. PRD was separately reported as having the same DLL installed.

## Other conditions
The isolated real Satisfactory 27,240-member metadata/publication sweep was underway at this checkpoint; do not represent it as complete without final output. PRD `ue_iostore_packages` lacks columns added by existing migration 202610150002 and must be migrated safely before the user imports Satisfactory. `verify-upload-bucket-pak.php` contains an unrelated pre-existing missing `migrations/202608170001_unverified_pak_members.php` reference and did not pass; investigate independently. UE5 full workflow reachability remains unverified.
