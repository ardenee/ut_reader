# UE1 legacy texture package corpus — genuine on-disk headers

Checked 2026-10-21 on DEV `game-amd`, root `L:\Source\Engine\UE1\Source\Unreal\Textures`.

**Scope:** read-only enumeration and first-eight-byte header extraction from genuine available `.utx` data. Not a reader acceptance test or an Epic runtime comparison.

| Outcome | Count |
|---|---:|
| All files recursively | 146 |
| PCX image assets (not Unreal packages) | 85 |
| Unreal texture package files (`.utx`) | 61 |
| Valid standard package magic `C1 83 2A 9E` | 61 |
| Package version below 61 | 59 |
| Package version exactly 61 | 2 |
| Package version above 61 | 0 |
| Nonzero licensee versions | 0 |

Version histogram: 35:13, 37:14, 41:4, 42:4, 44:8, 54:1, 55:1, 56:5, 57:4, 58:1, 59:3, 60:1, 61:2.

The version-61 files are `DispExpl.utx` and `MenuGr.utx`.

The entire sorted per-file inventory, including relative path, byte length, serialized package version, licensee version and SHA-256, is stored alongside this document as `ue1-textures-v35-v61.csv`. The inventory does **not** copy binary assets to GitHub.

**Reproducibility:** `.utx` little-endian bytes 0–3 are the standard tag `0x9E2A83C1`; bytes 4–5 contain the package version, bytes 6–7 the licensee version. In this observed corpus, the licensee high word is zero. `.pcx` files have independent PCX format signatures and no Unreal package version.

**Relevance to UE1 O01/O02:** provides 61 real pre-v62 package fixtures, especially 59 older than the current v61 baseline. Next acceptance work should exercise UnrealDB's actual canonical UE1 reader against all 61 files, compare decoded fields to source-backed expectations, and separately test early original `Unrealfile\x1A` format if genuine binary instances are found. Header signatures alone do not establish successful loading or authenticity of file provenance.

## Completed limited parser validation (B04, 2026-10-21)

The existing reader was executed, not just the header signature scanner. `catalog/bin/verify-ue1-genuine-texture-corpus.php` passed on 61/61 genuine packages: inventory hash/size, all 11 source-specified summary fields independently unpacked from file bytes, canonical UE1 name/import/export table count checks, real production header reader, and file-examine GUID agreement.

All 61 genuine cases have `HeritageCount=1`, `HeritageOffset=44`. This apparent success alone cannot detect a bug in other heritage layouts. A separate source-based synthetic five-case test `verify-ue1-pre68-heritage-inspector.php` reproduced four incorrect inspector outcomes before correction and passed all five after correction.

These results remain **bounded verification**, not a native Epic C++ runtime oracle, not proof of property/object payload equivalence, and not acceptance of UE1 O01.
