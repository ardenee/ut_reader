# UnrealDB

UnrealDB is a catalogue and preservation system for Unreal Engine game files.

Its purpose is to identify packages correctly, show how files relate to each other, find missing dependencies, reduce duplicate storage, and make verified files easier to preserve and distribute responsibly.

> **Development status:** UnrealDB is still under active development. The public site is available so users can explore the catalogue and see what the finished service will provide, but some functions are incomplete, experimental or available only to administrators.

## Public site functions

### Browse games and files

Users can browse the files recorded for each supported game.

This provides a central view of known packages, filenames, versions, sizes and file identities instead of relying on scattered download sites or filenames alone.

### Search the catalogue

Users can search by:

- package or filename
- GUID
- MD5 or SHA-1
- Names, Imports and Exports
- dependency or object path

This helps identify an unknown file, locate another copy of a package, or find the package that contains a required object.

### View package information

Each file can show its package header, version, GUID, hashes, Names, Imports, Exports and related files.

This allows users to understand what a package contains and gives administrators stronger evidence when deciding whether two files are identical or compatible.

### Find missing dependencies

UnrealDB records which packages require other packages and which dependencies are missing.

The aim is to make broken maps, mods and game installations easier to repair by showing exactly what is required and which files depend on it.

### Download verified files

Verified files can be made available through controlled local downloads or external mirrors.

Download controls exist to protect the server, prevent bulk scraping and avoid redistributing protected base-game content.

### Generate dependency packages

Where enabled, users can generate a package containing a selected file and its required dependencies.

Supported outputs include ZIP files and game-specific package formats such as UMOD-family installers and Unreal Tournament 4 PAK files.

The purpose is to reduce the manual work of finding and downloading every required file separately.

### Send feedback

When enabled, the Feedback page lets users report:

- broken functions
- incorrect file information
- missing packages or dependencies
- feature suggestions

Feedback is sent to the site administrator through the configured mail server.

## Catalogue and administration functions

### File uploads and source scanning

Administrators can add files through:

- Profiled Upload
- Upload Bucket
- Local Source Scan
- HTTP Source Scan
- PAK Import
- federation transfer
- Game Backup restore

These different methods allow UnrealDB to handle individual files, very large folders, remote archives and transfers between catalogue installations.

### Upload Bucket

Upload Bucket is intended for large unsorted collections.

It checks files before upload, calculates hashes, detects known duplicates, uploads files in resumable chunks and records failures for later review.

Its purpose is to avoid uploading unnecessary files and to keep large browser uploads manageable.

### Redirect archive support

Unreal redirect files such as `.uz`, `.uz2` and `.uz3` can be decompressed and scanned as their real package type.

This allows files collected from redirect servers to be added without manually decompressing them first.

### Unverified files

Files that appear to be valid Unreal packages but cannot yet be assigned confidently to a game are kept in an Unverified area.

Administrators can review possible matches, inspect identity information and then import, move or delete the file.

This prevents uncertain files from being mixed into the verified catalogue.

### Duplicate detection and aliases

UnrealDB compares file size and content hashes rather than assuming files with similar names are duplicates.

Byte-identical files can share one stored physical copy while retaining legitimate alternative package names as aliases.

This reduces wasted storage without losing package names needed by games or dependencies.

### Dependency rebuilding

Administrators can rebuild dependencies for one file, affected files or an entire game.

This keeps dependency information accurate after files are added, removed, renamed or repaired.

### UE3 UPK management

UE3 `.upk` packages are listed separately and their internal exports can be examined.

This gives administrators a clearer view of UPK package contents without pretending that individual exports are standalone package files.

### UE4 and UE5 PAK management

Supported, unencrypted PAK files can be retained, indexed and extracted.

UnrealDB records the original PAK, its entries and the relationship between extracted files and their source archive.

This preserves the original container while still allowing individual package files to be catalogued.

### Base-game protection

Administrators can maintain lists of official base-game package GUIDs.

Protected files remain available for dependency analysis but can be excluded from public downloads, generated packages and federation requests.

This allows UnrealDB to help identify required files without redistributing content that users should obtain from the original game installation.

### Game Backups

Game Backups create independent copies of a game's managed files together with a manifest describing their identities and original names.

They are intended for migration, disaster recovery and safe reset/reimport work.

### Federation

A parent UnrealDB installation can exchange inventories, dependency requests and approved files with child installations.

Federation exists so separate archives can help each other fill missing dependencies without manually comparing entire collections.

### Background jobs

Long-running work is handled by durable PHP worker processes rather than keeping a browser request open.

Administrators can select between one and eight workers and review queued, running, completed, failed and dead-letter jobs.

This is used for imports, dependency rebuilding, package generation, backups and other maintenance tasks that may continue after the browser is closed.

### Upload Issues and System Errors

Upload Issues records failed validation, transfer and finalisation results.

System Errors records application, API and browser-side errors that need administrator attention.

These pages provide a persistent review history instead of relying only on temporary browser messages or server logs.

### Public access controls

Administrators can control:

- download and generated-package limits
- local download speed
- crawler and scripted-download blocking
- rapid-request temporary blocks
- the public development notice
- feedback availability and mail delivery

These controls keep the public service usable while protecting the server from accidental or automated abuse.

## Supported Unreal Engine generations

UnrealDB includes support for Unreal Engine 1, 2, 2.5, 3, 4 and 5 workflows.

Support varies by generation and file type. UE1–UE3 package parsing is more complete, while some UE4/UE5 loose-package and container features remain limited or experimental.

Encrypted PAK files, unsupported compression methods and UE5 IoStore `.utoc`/`.ucas` containers are not currently fully supported.

## Why file identity matters

Unreal packages are often renamed, copied between servers or distributed with duplicate suffixes. Two files with the same name may be different, while two files with different names may contain identical data.

UnrealDB therefore uses package GUIDs, hashes, package structure and dependency evidence wherever possible instead of trusting filenames alone.

## Documentation

Technical installation, migration and administration documentation is available in the [`docs`](docs/) directory.

Useful references include:

- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/pak-archive-management.md`](docs/pak-archive-management.md)
- [`docs/upk-package-management.md`](docs/upk-package-management.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/production-deployment.md`](docs/production-deployment.md)
