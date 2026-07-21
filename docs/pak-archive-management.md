# UE4 PAK archive management

UnrealDB treats an original Unreal Engine 4 `.pak` container separately from the package files extracted from it.

## Storage model

A successful PAK import retains an independent copy of the original container under:

```text
<storage_path>/games/<game-slug>/paks/<sha256>.pak
```

The copy is written through a temporary `.part` file and is verified by file size and SHA-256 before publication. The uploaded or local staged source remains governed by normal incoming-job retention and cleanup rules.

`ue_pak_archives` stores the container identity and PAK metadata, including:

- target game
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

`Admin → Imports → PAK Import` accepts only games assigned to a UE4 profile.

The background job:

1. validates the staged PAK identity
2. reads a supported PAK footer and index
3. copies and verifies the original PAK into durable game storage
4. records every PAK index entry
5. extracts supported unencrypted entries
6. imports standalone package extensions through the normal scanner
7. records duplicate, alias, verified, unverified, rejected, skipped, encrypted and not-extracted outcomes
8. links successful package results back to their PAK entries

The original PAK remains the preferred self-contained download. Extracted files remain independently searchable and usable for dependency analysis.

## Browsing

UE4 game pages expose two separate views:

- **Files** — extracted packages in `ue_files`
- **PAK archives** — original retained containers in `ue_pak_archives`

PAKs are never inserted into the normal game-file table.

`game-paks.php` lists original containers for one game. `pak-info.php` shows complete container metadata and a paginated list of every indexed entry. Package entries link to their normal file information and examination pages.

File information and examination pages query the reverse relationship and display a **Source PAK archive** card with the original PAK, entry path, identity and download link.

## Downloads and protection

`pak-download.php` streams the retained original filename when local public delivery is allowed.

An entire PAK download is blocked when any linked extracted package is present in the selected game's base-game protection list. This prevents a self-contained PAK from bypassing the normal protected-file distribution rules.

## Administration

`Admin → PAK Archives` lists UE4 games and their retained archive totals. Administrators can inspect and delete retained PAKs. Deleting a retained PAK removes its archive and entry records but intentionally leaves independently imported package files in the catalog.

## Deployment

After updating the code, apply the schema migration:

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Restart or stop/start any detached worker that was already running before the update so the new archive-aware PAK handler is loaded.

PAKs imported before this feature are not automatically reconstructed because the original container was not previously cataloged as a durable archive. Re-import the original `.pak` file to create the retained container and entry links.

## Current format limits

The existing extractor supports standard readable UE4 PAK indexes and supported compression methods. Encrypted entries, Oodle-compressed payloads that cannot be decoded by the PHP extractor, and IOStore containers remain visible only where their index metadata can be read; they are not extracted as standalone packages.
