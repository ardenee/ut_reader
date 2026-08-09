# UE3 UPK package management

Unreal Engine 3 `.upk` files are Unreal package containers. They are not equivalent to UE4/UE5 PAK archives internally: a UPK normally contains serialized UObject exports, not a directory of independent child package files.

UnrealDB therefore manages UE3 UPKs as original package containers while exposing their parsed internal exports as the container contents.

## Storage and identity

A UE3 UPK continues to use normal verified package storage:

```text
<storage_path>/games/<game-slug>/verified/<md5>.upk
```

The original filename, source-relative path, package GUID, MD5, SHA-1, package version and licencee version remain stored in `ue_files`.

Unlike a PAK import, no second container copy is required because the verified `.upk` record is already the original self-contained package file.

## Parsed contents

The normal UE3 reader parses the package Names, Imports and Exports tables during import. Verified package tables are persisted in the per-file format-2 compressed metadata container rather than row-per-object legacy tables.

Current lookup projections provide the indexed views needed by runtime queries:

- `ue_export_lookup` — compact indexed export/object-path lookup data
- `ue_dependency_links` — compact import/dependency resolution links
- `ue_dependency_package_summaries` — per-file/package dependency counts used by list and reporting pages

The compressed metadata snapshot retains each export's class, object name, outer reference, local/full path, object flags, serial offset and serial size. These exports are the internal contents shown by the UPK management pages.

Export payloads are not inserted as separate `ue_files` rows. A raw serialized UObject export is not a valid standalone `.upk`; presenting it as an independent package file would create an unusable or misleading file.

## Browsing

UE3 game pages expose two separate views:

- **Files** — maps and other non-UPK game files
- **UPK packages** — original `.upk` package containers

`game-files.php` excludes `.upk` rows for UE3 games so the two representations are not mixed.

`game-upks.php` lists the UPKs for one game, including:

- original filename and logical package name
- package GUID and MD5
- package/licencee version
- internal compression state
- export count and serialized export payload size
- original UPK download
- administrator deletion

`upk-info.php` loads the package's compact metadata snapshot and shows complete package identity plus a pageable/filterable list of every parsed export. Export links open the exact entry in the normal package examiner.

The global `upks.php` page groups UPK packages by UE3 game.

## Import and existing data

No separate UPK import job is required. Profiled Upload, folder upload, Local Source Scan and other normal package-ingestion paths already parse `.upk` files, publish the format-2 compact metadata snapshot, and build the current export/dependency lookup projections.

Existing UE3 UPKs appear in the views when their current format-2 metadata is present. Re-import or rebuild only packages whose compact metadata or lookup projections are incomplete.

## Downloads and deletion

The original `.upk` is downloaded through the normal catalog download controller, so base-game protection and public download policy remain enforced.

Deleting a UPK uses the normal file-maintenance path and removes:

- the stored UPK
- its compact metadata container
- current export/dependency lookup and summary projections
- file locations and aliases
- associated asset metadata

The game itself and unrelated package files remain intact.
