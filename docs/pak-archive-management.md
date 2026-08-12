# UE4 and UE5 PAK archive management

UnrealDB treats original Unreal Engine 4 and Unreal Engine 5 `.pak` containers separately from package files extracted from them.

## Storage model

A successful PAK import retains an independent copy of the original container under:

```text
<storage_path>/games/<game-slug>/paks/<sha256>.pak
```

The copy is written through a temporary `.part` file and verified by size and SHA-256 before publication.

`ue_pak_archives` stores container identity/PAK metadata, including target game, original filename, retained storage path, hashes, PAK/footer/index information, mount point, entry counts and processing state.

`ue_pak_entries` stores every readable PAK index entry, including companion payloads that are not independent Unreal packages. Entries record path, sizes, compression information, hash, encryption/extraction state and import outcome.

When an entry becomes a catalog file, `ue_pak_entries.file_id` links it to `ue_files.id`.

## Durable import workflow

PAK import is a recoverable parent/child workflow. A single archive is **not** processed as one long loop that must restart at entry 1 after a worker failure.

The parent job performs bounded preparation:

1. resolve/validate the complete staged PAK source;
2. confirm the target game has a UE4/UE5 profile;
3. locate a supported footer and parse/select the matching index;
4. extract supported content;
5. retain/verify the original PAK in game storage;
6. promote the selected index/extracted tree into a durable per-job recovery workspace;
7. create one child job per PAK entry;
8. wait for entry children;
9. invoke the normal resumable game dependency workflow if package imports changed the catalogue;
10. finalize the PAK record and clean the recovery workspace.

The durable recovery workspace lives under:

```text
<storage_path>/jobs/pak-import/job-<parent-job-id>/
```

It is retained while the parent is restartable and removed after successful finalization/stale completed-job cleanup.

## Entry jobs

Each `catalog.import_staged_pak_entry` child owns exactly one index entry.

A child resolves its durable extracted source and uses a disposable working link/copy for any scanner operation that may consume/move the working file. Therefore:

- one package import cannot consume another entry's recovery source;
- a failed entry can be restarted without re-extracting the whole PAK;
- completed entry children are never intentionally replayed because a sibling failed;
- the parent can be reclaimed repeatedly without duplicating child units because `(parent_job_id, workflow_unit_key)` is unique.

Expected entry outcomes such as encrypted, unsupported/not-extracted, companion payload, skipped extension, duplicate, alias, verified, unverified or rejected are recorded as entry results. Infrastructure/database failures are exceptions and fail only that entry child.

## Dependency refresh

The old implementation performed a whole-game dependency rebuild inline after looping every PAK entry. The current parent instead nests the normal durable `catalog.rebuild_game_dependencies` workflow after entry processing. Its per-file dependency units are independently restartable.

A parent that fails during PAK finalization does not need to repeat successful entry imports or the earlier extraction phase.

## Browsing

UE4/UE5 game pages expose separate views:

- **Files** — imported package files in `ue_files`;
- **PAK archives** — retained original containers in `ue_pak_archives`.

PAKs are not inserted into the normal game-file table.

`game-paks.php` lists original containers. `pak-info.php` shows container metadata and paginated indexed entries. Linked package entries can open normal file information/examination pages.

File pages can show a **Source PAK archive** relationship back to the original container/entry.

## Downloads and base-game protection

`pak-download.php` streams the retained original filename when local delivery is allowed.

A complete PAK download is blocked when protected/base-game package policy says the archive would expose protected content. Original-container retention does not bypass normal distribution policy.

## Administration

Administrators can inspect/delete retained PAK records. Deleting a retained PAK removes its archive/entry records and retained original archive, but independently imported package rows are intentionally preserved.

## Worker/resource behavior

PAK parent and entry work uses the `archive-import-heavy` resource class. The default limit is conservative (`1`) because archive extraction/import performs sustained filesystem/database work and because entry children share one parent workspace.

Routine successful/duplicate/skipped entry event logging can be disabled on the Job Logging page. Durable child results remain in the queue regardless of those event settings. Terminal entry failures remain visible/actionable and are promoted into System Errors.

## Upload/recovery boundary

Chunking an upload does not by itself make the browser session recoverable. PAK background recovery begins only after the complete archive exists in server-controlled staging/chunk storage and the PAK job has been created.

Once that boundary is crossed, browser presence is unnecessary.

## Deployment

PAK archive tables are part of the consolidated schema/history. Current durable parent/child recovery additionally requires migration `202608120001_job_workflow_recovery_logging.php`:

```bash
php catalog/bin/migrate.php migrate
```

Restart detached workers after deploying code that introduces `catalog.import_staged_pak_entry`, otherwise an old worker process will not know the new child type.

The architectural recovery contract can be checked with:

```bash
php catalog/bin/verify-resumable-job-workflows.php --database
```

## Current format limits

The extractor handles supported readable PAK footer/index layouts and supported compression methods. It does not claim universal UE5 container support.

Current limitations include:

- encrypted PAK indexes/entries without usable keys;
- compression methods the PHP extractor cannot decode (including unsupported Oodle cases);
- UE5 IoStore `.utoc` / `.ucas` containers;
- package/companion layouts not understood by the selected package reader.

Unsupported entries can still remain represented in `ue_pak_entries` when their index metadata is readable; they are not misrepresented as successfully imported standalone packages.
