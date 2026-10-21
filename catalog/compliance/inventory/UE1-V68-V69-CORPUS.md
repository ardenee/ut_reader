# UE1 packages version 68/69 — installed genuine-file corpus (B05)

Scanned on DEV game-amd on 2026-10-21, recursively under
- `L:\Source\Engine\UE1`
- `L:\Source\Games\Unreal`
- `L:\Source\Games\UT99`

The discovery limited the search to recognized Unreal package extensions and files beginning with the original standard little-endian `PACKAGE_FILE_TAG` bytes `C1 83 2A 9E`, then selected header version 68 or 69. The source root, relative filename, byte size, package and licensee versions, and per-file SHA-256 were recorded as `ue1-packages-v68-v69.csv`. Binary files were **not** committed.

| Location | Header version | Licensee | Count |
|---|---:|---:|---:|
| Unreal v1.227 source tree | 68 | 0 | 2 |
| Unreal v1.227 source tree | 69 | 0 | 1 |
| Unreal v1.227 source tree | 69 | 227 | 1 |
| UT99 v1.400 source tree | 68 | 0 | 27 |
| **Total** | | | **31** |

Source serialization references:
- UT99 v1.400 `Core/Src/UnLinker.h:144–204` — v68+ inline GUID, generation count and generation records
- Unreal v1.227 `Core/Inc/UnLinker.h:97–170` — `GetFileVersion()` masks the low 16 bits, while the Legend-specific licensee occupies the upper 16; loader clamps generation count in that source
- Both compared against `CatalogUE1PackageReader`, actual `gp_read_legacy_summary`, header-route helper and the file-examine inspector

Verified by `php catalog/bin/verify-ue1-v68-v69-genuine-corpus.php`: **31/31 pass** with independent direct-byte checks of serialized summary fields, inline GUID, reader table counts and source-specific v227 constructor. SHA-256 manifest checked each run.

Combined corpus across B04/B05: **92 independently inventoried installed package-file paths**, 15 distinct package versions (35,37,41,42,44,54,55,56,57,58,59,60,61,68,69), two game-source builders (Unreal and UT99). `php catalog/bin/verify-ue1-genuine-production-paths.php` demonstrates **92/92** for real classification, profile engine selection, canonical reader, appropriate source-specific V5 snapshot construction and full isolated `.uedb5` write/read roundtrip. The source roots are not independently authenticated release distributions and the test does not exercise real SQL publication or compiled Epic engine runtime.

The same production-path driver checks negative paths: a UE2-only tag must fail UE1 admission while remaining allowed by UE2 classification; a source-format name-table offset outside the file must be rejected during reader/snapshot construction even though the cheap header classification sees the standard tag.

**O01 remains IMPLEMENTING:** old string-tagged Unrealfile binary corpus and reader are absent; complete cross-branch acceptance, compiled Epic oracle and real SQL persistence/search validation remain outstanding.
