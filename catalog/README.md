# UnrealDB

This folder adds a database-backed catalog on top of the existing `UE1/`, `UE2/`, `UE3/`, and `UE4/` readers.

It is intended to answer questions such as:

- Which files does this map/package depend on?
- Which dependency objects are missing?
- Which files provide a required exported object?
- Is this uploaded package valid enough for the current parser to scan?
- Do we already have this exact file by MD5?

## Install

1. Create a MariaDB/MySQL database.
2. Import `catalog/install.sql` into the new empty database.
3. Copy `catalog/config.example.php` to `catalog/config.php`.
4. Edit the DB credentials and upload limit in `catalog/config.php`.
5. Run `php catalog/bin/migrate.php migrate` from a trusted shell.
6. Run `php catalog/bin/migrate.php verify`.
7. Make `catalog/storage/` writable by PHP.
8. Create the initial administrator with `php catalog/bin/create-admin.php --username=admin`.
9. Open `catalog/index.php` and sign in.

For an existing database, do not re-import `install.sql`. Back up the database and storage, then use the same migration command. See `docs/database-migrations.md`.

## Dependency policy

The catalog is deliberately strict.

A dependency is marked `resolved` only when an import path can be matched to a stored export path for another verified file in the same game.

Package-name-only matches are marked `package_only`, not `resolved`, because they do not prove that the exact required object exists.

Common engine packages can be marked `common`, but they are still stored in the database so they can be searched and audited.

## Upload behaviour

Uploads are scanned immediately through the existing version-specific reader for the selected game.

- Verified files are stored under `catalog/storage/games/<game>/verified/`.
- Failed valid Unreal packages are retained in database-backed unverified staging for review.
- Unsupported files are rejected rather than retained as catalogue entries.
- Duplicate physical files are not stored again; logical package aliases may point to an existing identity.

## Important limitation

Unreal package references are package/object based, not filename based. The catalog does not use filenames as dependency proof.

However, each stored file still needs a `package_name` value so imports such as `SomePackage.SomeObject` can be linked to the file that represents package `SomePackage`. By default this is taken from the uploaded filename because that is how classic Unreal package loading normally identifies packages. Admins can correct the package name on the file details page; saving it rebuilds dependency links for that game.
