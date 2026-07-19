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

Each engine family also needs redistributable or locally supplied fixtures and a JSON manifest containing the expected catalog result:

```json
{
  "engine": "UE3",
  "filename": "Example.upk",
  "scan_status": "verified",
  "package_guid": "EXPECTED-GUID",
  "names": 0,
  "imports": 0,
  "exports": 0,
  "dependency_expectations": []
}
```

Store locally supplied fixtures outside Git and point the future integration runner at them with `UNREALDB_FIXTURE_ROOT`. The remaining validation matrix is:

- UE1 real valid package plus malformed/truncated edge cases
- UE2 and UE2.5 real packages, including legacy-compatible texture/effect packages
- UE3 uncompressed, zlib-compressed and LZO-compressed packages
- UE4 game-specific versioned and unversioned packages
- UE4 `.uasset` + `.uexp` package sets
- UE5 metadata packages within the currently supported range
- dependency pair A → B, aliases and a duplicate-MD5 case
- packages containing representative serialized properties and redirectors

An external fixture is accepted only when its manifest has been checked against a known-good reader, Unreal Editor or source-backed expected result. Retail assets must never be copied into this public repository.
