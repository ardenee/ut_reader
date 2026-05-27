# UnrealDB / UT Reader

UnrealDB is a PHP/MySQL-MariaDB web application for cataloging Unreal Engine package files, inspecting their package data, and helping complete game libraries by finding missing dependencies.

The project started as standalone UE package viewers for UE1, UE2, UE3, and UE4 files. It now also includes a catalog/database application under `catalog/` for managing Unreal game libraries, scanning files, recording imports/exports, resolving dependencies, and optionally sharing inventory between deployments through a parent/child federation system.

> **Status:** Active development. The package viewers are useful for inspection and parser debugging. The catalog app is usable for testing, but schema/features are still changing and should be treated as pre-release.

## Main Goals

- Build a searchable database of Unreal package files.
- Store MD5/SHA1 hashes and package GUIDs to avoid duplicate storage.
- Parse package headers, names, imports, exports, and selected metadata.
- Use imports/exports to identify missing dependency packages and objects.
- Keep dependency matching based on real package/object data, not filenames alone.
- Group files needed by maps/mods so a library can be completed without missing objects.
- Support multiple Unreal games and engine generations through editable game profiles.
- Allow deployments to connect to a parent/master catalog for inventory comparison and controlled missing-file transfers.
- Provide optional public download control and external mirror/shared-provider link handling.

## Project Areas

### `catalog/` — UnrealDB Catalog App

The catalog app is the main application for building and managing a database of Unreal files.

Current catalog features include:

- Game manager and game profile system.
- Profiled upload scanner with engine/version/extension checks.
- Local source scanner that can link known files by MD5/GUID and optionally import unknown files through the profiled scanner.
- Search by file name, package name, MD5, SHA1, GUID, imports, and exports.
- Game/file browsing with dependency summaries.
- Import/export/name table storage in MySQL/MariaDB.
- Missing dependency tracking.
- Duplicate detection by MD5 and package GUID.
- File storage under `catalog/storage/`.
- Admin dashboard grouped by workflow: Dashboard, Library, Setup, Missing Files, Federation, Transfers, Downloads, Settings.
- Parent/child federation tables and pages for comparing inventory and transferring approved/missing files.
- Public download mode controls and external mirror link queue/fulfilment.

### `UE1/`, `UE2/`, `UE3/`, `UE4/` — Package Viewers

The version-specific viewers remain useful for direct parser debugging and package inspection.

| Folder | Viewer | Current purpose |
|---|---|---|
| `UE1/` | `UE1.php` | UE1 / Unreal Tournament era package inspection |
| `UE2/` | `UE2.php` | UE2 / UE2.5 package inspection |
| `UE3/` | `UE3.php` | UE3 / UT3 package inspection, including compressed package handling where supported |
| `UE4/` | `UE4.php` | UE4 `.uasset` / `.umap` package summary, table, and export map inspection |

These viewer folders should be treated as parser/viewer references. The catalog app can reuse parsing logic but should not require destructive edits to the working version-specific viewers.

## Catalog Workflow

A normal setup/use flow is:

1. Install the catalog database.
2. Add or edit games in **Setup → Game Manager**.
3. Configure scanner profiles with engine key, file extensions, and known package version ranges.
4. Upload files using **Setup → Profiled Upload Scanner**.
5. Add local/HTTP source locations where required.
6. Run source scans to link known files or import unknown files through the profiled scanner.
7. Review the library in **Library**.
8. Review missing packages/objects in **Missing Files**.
9. Optionally connect to a parent/master catalog through **Federation**.
10. Request/download/import missing files through controlled transfer queues.
11. Configure public download behaviour under **Downloads** if the site is public-facing.

## Game Profiles and Scanner Rules

Game profiles make the scanner data-driven so additional Unreal games can be added later without rewriting scanner logic.

Each profile stores:

- game ID,
- engine key, such as `UE1`, `UE2`, `UE3`, `UE4`, `UE5`,
- allowed file extensions,
- package version min/max,
- licensee version min/max,
- confidence policy,
- notes.

Seeded profile examples currently include:

| Engine | Example games | Extensions | Starting package version rule |
|---|---|---|---|
| UE1 | Unreal, Unreal Tournament | `u`, `unr`, `utx`, `umx`, `uax` | 60-69 |
| UE2 / UE2.5 | Unreal II, UT2003, UT2004 | `u`, `un2`, `ut2`, `utx`, `usx`, `ukx`, `uax` | 100-130 |
| UE3 | Unreal Tournament 3 | `ut3`, `upk`, `u` | 512 |
| UE4 | Unreal Tournament Alpha / UT4-style packages | `uasset`, `umap` | not fixed / may be unversioned |

These ranges are starting rules, not final proof for every custom/licensee build. Exact same-engine game routing can be ambiguous; for example, header data may identify UE2 but not always prove UT2003 versus UT2004. High-confidence auto-routing should therefore be limited to engine/profile matches, with admin review where needed.

## Dependency Matching

The catalog is intended to resolve dependencies using package/object references rather than filenames alone.

For each scanned file, the catalog stores:

- name table entries,
- import table entries,
- export table entries,
- package GUID,
- package version/licensee version,
- MD5 and SHA1,
- dependency rows derived from imports,
- resolved/missing/common/package-only status.

The goal is to determine which packages are actually required by a map/package and which files in the database can satisfy those references.

## Federation / Parent-Child Catalogs

Federation is intended for multiple catalog deployments.

A parent/master site can collect inventory from child sites and pull files it needs. Child sites can request missing dependency files from the parent. Transfers are managed through queues and worker pages so large libraries are not transferred all at once.

Current federation concepts include:

- site identity and fingerprint,
- parent/child peer records,
- join request workflow,
- inventory push/pull,
- missing dependency request generation,
- approval/denial of child requests,
- controlled transfer jobs,
- download/import workers,
- queue and log pages,
- configurable speed/delay/file-count limits,
- cron/DSM Task Scheduler worker endpoint.

Child sites can request missing dependency files. Parent sites are intended to have broader access so they can build a more complete master catalog.

## Public Downloads and External Mirrors

Public/user downloads are separate from federation transfers.

Public download modes currently include:

| Mode | Meaning |
|---|---|
| `local_direct` | Users download directly from this site. |
| `external_mirror` | Users only receive active external/shared-provider links. If no link exists, a mirror job can be queued. |
| `external_mirror_preferred` | Use an active external link if available; otherwise fall back to local direct download and queue a mirror job. |
| `disabled` | Disable public downloads. Federation transfers still use the federation API. |

External mirror support currently includes a manual provider workflow:

1. A public user requests a file.
2. The site queues a mirror job if no active link exists.
3. An admin uploads the file to a shared hosting provider manually.
4. The admin pastes the external URL into the mirror job page.
5. The link is reused until its stale/expiry days pass.

Provider-specific upload APIs can be added later.

## Current Package Viewer Features

The UE viewer pages can display:

- package summary fields,
- GUIDs,
- package flags where supported,
- name/import/export/generation tables,
- linked object/name references,
- export tree/details where supported,
- selected property/export data where supported,
- raw header data from bytes actually read from the file,
- unparsed header bytes as raw hex when a known header section has not yet been fully decoded.

Raw export/import grids and raw header data are generally collapsed by default so the normal package summary remains readable.

## Supported / Target File Types

Current target package types include:

- UE1-style packages: `.u`, `.utx`, `.umx`, `.uax`, `.unr`.
- UE2/UE2.5 packages: `.u`, `.ut2`, `.un2`, `.utx`, `.usx`, `.ukx`, `.uax`, `.umx`.
- UE3 packages: `.ut3`, `.upk`, `.u`.
- UE4 packages: `.uasset`, `.umap`.

Support is parser-dependent and still expanding. A file opening successfully does not mean every export payload or property type is fully decoded.

## Runtime Requirements

Recommended runtime:

- PHP 8.2 or newer.
- MySQL or MariaDB.
- PHP extensions: `pdo_mysql`; `zip` recommended for bundle downloads.
- A PHP-capable web server, such as Synology Web Station, Apache, nginx + PHP-FPM, or a local PHP development server.
- Writable `catalog/storage/` folder for catalog-managed files.
- Writable `UE1/uploads/`, `UE2/uploads/`, `UE3/uploads/`, `UE4/uploads/` folders if using the standalone viewers.
- Optional LZO support for UE3 compressed packages that use LZO compression.

On Synology/Linux, make sure the web server user can write to the relevant storage/upload folders.

Example:

```bash
cd /volume1/web/ut_reader
mkdir -p catalog/storage
chown -R http:http catalog/storage
chmod -R 775 catalog/storage
```

Adjust the user/group for your web server environment.

## Catalog Installation

1. Clone or pull the repository onto a PHP-capable web server.
2. Copy `catalog/config.example.php` to `catalog/config.php`.
3. Edit database settings in `catalog/config.php`.
4. Import `catalog/install.sql`.
5. Import update SQL files in order, where applicable:

```text
catalog/install_update_011_external_mirrors.sql
catalog/install_update_012_game_profiles.sql
```

6. Make `catalog/storage/` writable by PHP.
7. Open `/catalog/index.php` or `/catalog/dashboard.php`.
8. Create the first admin user on the login page.
9. Use **Setup → Game Manager** and **Setup → Profiled Upload Scanner**.

The root `/index.php` page is a simple UnrealDB landing page that links into the catalog.

## Standalone Viewer Usage

1. Ensure the relevant `uploads/` folder exists and is writable.
2. Open the correct viewer:
   - `/UE1/UE1.php`
   - `/UE2/UE2.php`
   - `/UE3/UE3.php`
   - `/UE4/UE4.php`
3. Upload or select a package file.
4. Review the Package tab first.
5. Use the Tables tab to inspect names, imports, exports, and generations.
6. Expand Raw Header Data only when comparing header layouts or debugging parser offsets.

## UE3 Compression / LZO Notes

Some UE3 packages use compression. LZO-compressed packages require LZO support.

The project may use native LZO through PHP FFI when available, with fallback code where supported. Native LZO is preferred because it is faster and more reliable.

Do not commit local native LZO binaries to GitHub. They are platform-specific.

Common local library names that should stay ignored include:

```text
liblzo2.so
liblzo2.so.*
liblzo2-2.dll
lzo2.dll
```

## Current Limitations

- This is active development, not a stable release.
- The catalog schema is still changing; update SQL files may be added over time.
- Not every export payload or property type is decoded.
- Some version-gated package header fields still need refinement.
- UE4 unversioned package parsing may rely on assumed versions for table parsing.
- `.uexp` handling is currently limited.
- Exact same-engine game identification is not always safe from package headers alone.
- Federation transfer handling is still being tested.
- External provider upload automation is not implemented yet; manual mirror links are supported.
- Large/chunked federation uploads may need future improvement.

## Development Notes

When adding parser fields, prefer this rule:

```text
Raw Header Data = bytes actually read from the file header/summary.
Normal Package Summary = interpreted, derived, or user-friendly display values.
```

Do not silently skip unknown header bytes. If a header byte range is valid but not decoded yet, show it with offset, size, and raw hex until it can be named correctly.

When adding scanner support for more games:

1. Add the game in **Game Manager**.
2. Add/edit the profile engine key and file extensions.
3. Add version ranges only when known from real samples.
4. Test with the profiled upload scanner.
5. Keep mismatches in unverified/review rather than force-importing them.

## License

No license has been specified yet. Add a license before publishing a stable release or accepting external contributions.
