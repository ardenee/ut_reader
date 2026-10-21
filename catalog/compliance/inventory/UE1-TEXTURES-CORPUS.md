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
