# UnrealDB package fixtures

Do not commit copyrighted retail Unreal packages to the public repository.

## Synthetic baseline committed to Git

The repository includes original, generated reader fixtures that contain no retail game data:

- `catalog/tests/fixtures/SyntheticReaderFixtures.php` generates the binary package bytes.
- `tests/fixtures/synthetic-readers.json` records immutable size, SHA-256 and expected reader output.
- `catalog/tests/reader-fixtures-test.php` runs those bytes through the production reader resolver and UE1–UE4 readers.

The current synthetic matrix covers:

- UE1 version 69 summary, names, one import and one export
- UE2 version 128 summary, names, one import and one export
- UE2 legacy-compatible version 83/licensee 635 summary and tables
- UE3 version 334 uncompressed summary and detected name/export layouts
- UE4 version 511 summary and tables
- UE4 unversioned summary using the standard parser profile assumption
- malformed UE1–UE4 packages that must fail closed without exposing partial tables

The generated hashes make accidental fixture changes visible in CI. These fixtures protect structural reader behavior and reader isolation, but they are intentionally minimal and do not prove arbitrary serialized property parsing or game-specific quirks.

## External validated fixtures

Real or redistributable packages remain outside Git. Put each package beside a JSON manifest under one private fixture root. The runner accepts either one fixture object or a top-level `fixtures` array.

```json
{
  "engine": "UE4",
  "filename": "Content/Example.uasset",
  "companions": ["Content/Example.uexp"],
  "sha256": "EXPECTED-SHA256",
  "size": 123456,
  "header": {
    "version": 511,
    "guid": "EXPECTED-GUID"
  },
  "names": 80,
  "imports": 4,
  "exports": 3,
  "parser_profile": {
    "profile_key": "ut4-alpha",
    "label": "Unreal Tournament 4 Alpha UE4 parser",
    "assumed_unversioned_parser_version": 511
  },
  "allow_issues": false
}
```

Run the private fixture set with:

```text
UNREALDB_FIXTURE_ROOT=/path/to/private/fixtures php catalog/tests/external-reader-fixtures-test.php
```

On PowerShell:

```powershell
$env:UNREALDB_FIXTURE_ROOT = 'L:\UnrealDB-fixtures'
php catalog/tests/external-reader-fixtures-test.php
```

The runner:

- discovers JSON manifests recursively
- rejects package and companion paths escaping the configured root
- refuses symlink package files
- verifies optional size and SHA-256 values
- loads the same isolated UE1/UE2 and production UE3/UE4 reader classes used by the catalogue
- supports UE5 manifests through the currently configured UE4-family reader
- compares selected header fields, exact or counted names, import/export counts and expected reader issues
- naturally loads an adjacent `.uexp` when the production UE4 reader supports that package pair

The remaining validation matrix is:

- UE1 real valid package plus malformed/truncated edge cases
- UE2 and UE2.5 real packages, including legacy-compatible texture/effect packages
- UE3 uncompressed, zlib-compressed and LZO-compressed packages
- UE4 game-specific versioned and unversioned packages
- UE4 `.uasset` + `.uexp` package sets
- UE5 metadata packages within the currently supported range
- dependency pair A → B, aliases and a duplicate-MD5 case
- packages containing representative serialized properties and redirectors

An external fixture is accepted only when its manifest has been checked against a known-good reader, Unreal Editor or source-backed expected result. Retail assets must never be copied into this public repository.
