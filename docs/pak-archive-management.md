# UE4 and UE5 PAK archive management

UnrealDB treats original Unreal Engine 4 and Unreal Engine 5 `.pak` containers separately from the package files extracted from them.

## Storage model

A successful PAK import retains an independent copy of the original container under:

```text
<storage_path>/games/<game-slug>/paks/<sha256>.pak
```

The copy is written through a temporary `.part` file and is verified by file size and SHA-256 before publication. The uploaded or local staged source remains governed by normal incoming-job retention and cleanup rules.

`ue_pak_archives` stores the container identity and PAK metadata, including:

- target game and its UE4/UE5 profile
- original filename
- retained storage path
- file size, MD5, SHA-1 and SHA-256
- PAK version and footer layout
- mount point
- index offset, size and hash
- entry, extracted and skipped counts
- processing, ready or failed state

`ue_pak_entries` stores every readable PAK index entry, including companion payloads that are not independent Unreal packages. Each entry records its path, sizes, compression information, hash, encryption state and import result.

When an extracted entry becomes a catalog file, `ue_pak_entries.file_id` links that entry to `ue_files.id`. A file can therefore identify the original PAK and entry path from which it was extracted, while a PAK can list all linked catalog packages.

## Import workflow

`Admin → Imports → PAK Import` accepts games assigned to UE4 or UE5 profiles.

The background job:

1. validates the staged PAK identity
2. confirms that the target game uses a UE4 or UE5 profile
3. reads a supported PAK footer and index
4. copies and verifies the original PAK into durable game storage
5. records every readable PAK index entry
6. extracts supported unencrypted entries
7. imports standalone package extensions through the normal scanner
8. records duplicate, alias, verified, unverified, rejected, skipped, encrypted and not-extracted outcomes
9. links successful package results back to their PAK entries
10. performs one game-wide dependency refresh after the import

The original PAK remains the preferred self-contained download. Extracted files remain independently searchable and usable for dependency analysis.

## Browsing

UE4 and UE5 game pages expose two separate views:

- **Files** — extracted packages in `ue_files`
- **PAK archives** — original retained containers in `ue_pak_archives`

PAKs are never inserted into the normal game-file table.

`game-paks.php` lists original containers for one game. `pak-info.php` shows complete container metadata and a paginated list of every indexed entry. Package entries link to their normal file information and examination pages.

File information and examination pages query the reverse relationship and display a **Source PAK archive** card with the original PAK, entry path, identity and download link.

## Downloads and protection

`pak-download.php` streams the retained original filename when local public delivery is allowed.

An entire PAK download is blocked when any linked extracted package is present in the selected game's base-game protection list. This prevents a self-contained PAK from bypassing the normal protected-file distribution rules.

## Administration

`Admin → PAK Archives` lists UE4 and UE5 games and their retained archive totals. Administrators can inspect and delete retained PAKs. Deleting a retained PAK removes its archive and entry records but intentionally leaves independently imported package files in the catalog.

## Deployment

The PAK archive tables are provided by migration `202607210001_pak_archive_management.php`:

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Restart or stop/start any detached worker that was already running before the update so it loads the UE4/UE5-aware PAK handler.

PAKs imported before original-container retention was added are not automatically reconstructed. Re-import the original `.pak` file once to create the retained container and entry links.

## Current format limits

The PHP extractor handles supported standard readable PAK footer/index layouts and supported compression methods. It does not claim universal UE5 container support.

The following remain separate or unsupported by this PAK importer:

- encrypted PAK indexes or entries without available keys
- Oodle-compressed payloads that cannot be decoded by the PHP extractor
- UE5 IoStore `.utoc` / `.ucas` containers
- packages whose companion data or engine version cannot be parsed by the selected reader

Unsupported entries remain recorded where their index metadata can be read, but they are not represented as successfully extracted standalone packages.
