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
2. Import `catalog/install.sql`.
3. Copy `catalog/config.example.php` to `catalog/config.php`.
4. Edit the DB credentials and upload limit in `catalog/config.php`.
5. Make `catalog/storage/` writable by PHP.
6. Open `catalog/index.php` in the browser.
7. Click **Admin Login**. If no user exists, the login page creates the first admin user.

## Dependency policy

The catalog is deliberately strict.

A dependency is marked `resolved` only when an import path can be matched to a stored export path for another verified file in the same game.

Package-name-only matches are marked `package_only`, not `resolved`, because they do not prove that the exact required object exists.

Common engine packages can be marked `common`, but they are still stored in the database so they can be searched and audited.

## Upload behaviour

Uploads are scanned immediately through the existing version-specific reader for the selected game.

- Verified files are stored under `catalog/storage/games/<game>/verified/`.
- Failed uploads are moved to `catalog/storage/games/<game>/unverified/` with a text file containing the failure reason.
- Duplicate MD5 files are not stored again.

## Important limitation

Unreal package references are package/object based, not filename based. The catalog does not use filenames as dependency proof.

However, each stored file still needs a `package_name` value so imports such as `SomePackage.SomeObject` can be linked to the file that represents package `SomePackage`. By default this is taken from the uploaded filename because that is how classic Unreal package loading normally identifies packages. Admins can correct the package name on the file details page; saving it rebuilds dependency links for that game.
