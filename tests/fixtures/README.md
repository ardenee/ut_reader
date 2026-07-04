# UnrealDB package fixtures

Do not commit copyrighted retail Unreal packages to the public repository.

Each engine family needs at least one redistributable or locally supplied fixture and a JSON manifest containing the expected catalog result:

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

Store locally supplied fixtures outside Git and point the integration test runner at them with `UNREALDB_FIXTURE_ROOT`. The intended matrix is:

- UE1 valid and malformed package
- UE2 valid and legacy-compatible texture package
- UE3 uncompressed and LZO-compressed package
- UE4 versioned, unversioned, and `.uasset` + `.uexp` package set
- UE5 currently supported metadata package
- dependency pair A → B plus a duplicate-MD5 case

A fixture is accepted only when its manifest has been checked against a known-good reader/editor result.
