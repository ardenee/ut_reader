# Database-backed unverified staging

Unverified Unreal package files are stored physically in the upload bucket or a game's `unverified` folder and receive a `ue_files` row as part of the writer operation.

## Explicit staging contract

Queue writers use `UnverifiedFileStager`. A successful call returns the exact queue filename, physical path, unverified file ID, stored size and package-table parse status before the writer finishes.

The service supports two failure-storage modes:

- move temporary or incoming files into unverified storage;
- copy configured source-library files into unverified storage while preserving the source.

Both modes use the same safe queue naming, metadata parsing, hashing and database persistence.

## Writer behaviour

### Upload Bucket

The Upload Bucket stages files directly. Folder uploads record the browser-relative path for later UE4/UE5 package identity analysis while physical storage uses a safe generated queue filename.

Before a new bucket file is stored, the stager calculates its size and MD5 and compares it with database-backed Upload Bucket rows. The check is serialized with a database lock so simultaneous uploads of the same bytes cannot both be retained. When an identical size+MD5 file already exists physically in the bucket, the incoming temporary file is deleted and the existing unverified file ID is returned with `status=duplicate`. No second physical file, queue note, or `ue_files` row is created. Redirect archives are decompressed first, so the real package content is compared rather than the `.uz`, `.uz2`, or `.uz3` wrapper.

### Profiled Upload and PAK entries

Profiled Upload routes rejected Unreal packages through the shared scanner failure primitive, including failed extracted PAK entries. The database row is created before the request finishes. Non-package failures are deleted rather than filling unverified storage.

### Local Source Scan

Local Source Scan uses copy-preserving staging. A failed valid package is copied into the selected game's unverified queue and indexed, while the configured source-library file remains untouched. Source-relative identity context is retained on the staged row.

### HTTP Source Scan

HTTP Source Scan does not create unverified files. It reads a trusted remote manifest, optionally downloads bounded temporary files for GUID inspection and always deletes those temporary files.

### Federation receive and import

A successfully imported federation download clears the transfer job's incoming path. A duplicate download removes the redundant incoming file and records the existing file identity.

When federation import fails for a valid package, the incoming file is moved into the selected or detected game's unverified queue. The failed transfer job records the staged unverified file ID and its new queue path. If staging itself fails, the job remains linked to the original incoming path and a separate staging failure is logged.

## Removed shutdown indexing

The former shutdown-time directory snapshot hook has been removed from the application bootstrap and deleted. Writers must stage explicitly. This removes cross-request races where one request could accidentally index a file created by another request.

A package-table parsing failure does not discard the file. The row is retained with hashes, detected header metadata and a parse error. If database staging fails after the file reaches queue storage, a `Database staging failed` note is appended. If the database is unavailable before queue storage is completed, the scanner uses a final filesystem fallback with a reconciliation note so the existing-queue importer can recover the package without data loss.

## Row state

- `scan_status = unverified`
- `game_id = NULL`
- `unverified_queue_game_id` records the physical queue owner (`0` means Upload Bucket)
- `unverified_queue_name` and `unverified_queue_key` identify the physical queue file
- readable Names, Imports and Exports are stored in their normal tables
- dependencies are not resolved as catalogue dependencies until the row is promoted

Using a null `game_id` keeps staging rows outside normal game lists even if an older read query omits the status condition. Search and download entry points additionally require `scan_status = verified`.

## Reviewing files

`unverified-files.php` shows identity duplicates, stored N/I/E counts, game-profile compatibility, the number of verified catalogue files requiring the package, and exact required-object/export matches.

`unverified-file-details.php` shows the complete staged metadata and paginated stored package tables.

Existing duplicates created by older versions can be removed through the unverified duplicate-cleanup action. New Upload Bucket requests are rejected before a second bucket copy or row is created.

## Promotion

Importing an unverified file promotes it into a verified game assignment:

1. validate the chosen game profile;
2. check verified duplicate or alias identity;
3. move the physical package to verified storage;
4. set the verified game and status;
5. clear queue fields;
6. rebuild its dependencies and affected dependencies.

## Existing queues and recovery

Run the database migrations before using database-backed staging:

```text
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Open `unverified-database-import.php` to index physical queue files created by older application versions or files retained after an infrastructure failure. The page processes one file per request and reports progress.

A queue note containing `Database staging was unavailable` indicates that the physical package was deliberately retained by the final failure fallback and still needs reconciliation.

The former `catalog/upgrade-unverified-index.sql` file is retained only as historical reference. Numbered migrations are the supported upgrade path.
