# Database-backed unverified staging

Unverified Unreal package files are stored physically in the upload bucket or a game's `unverified` folder and receive a `ue_files` row as part of the writer operation.

## Explicit staging contract

New queue writers use `UnverifiedFileStager`. A successful call returns the exact queue filename, physical path, unverified file ID, stored size and package-table parse status before the controller sends its response.

The Upload Bucket now uses this contract directly. Folder uploads also record the browser-relative path for later UE4/UE5 package identity analysis while physical storage continues to use a safe generated queue filename.

A package-table parsing failure does not discard the file. The row is retained with hashes, detected header metadata and a parse error. A database/storage failure is reported to the writer; if the file has already reached queue storage, a `Database staging failed` note is appended so the existing-queue importer can recover it without data loss.

Profiled Upload failures and HTTP source-scan failures remain on the temporary shutdown-index compatibility hook. The hook is restricted to those two routes and scans only per-game unverified folders. It no longer watches Upload Bucket or unrelated federation pages.

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

## Promotion

Importing an unverified file promotes it into a verified game assignment:

1. validate the chosen game profile
2. check verified duplicate/alias identity
3. move the physical package to verified storage
4. set the verified game and status
5. clear queue fields
6. rebuild its dependencies and affected dependencies

## Existing queues

Run the database migrations before using database-backed staging:

```text
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Open `unverified-database-import.php` to index physical queue files created by older application versions or files retained after an infrastructure failure. The page processes one file per request and reports progress.

The former `catalog/upgrade-unverified-index.sql` file is retained only as historical reference. Numbered migrations are the supported upgrade path.
