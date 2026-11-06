# UE4 / Unreal Tournament 4: historical native binary evidence (6 November 2026)

**Purpose:** Provenance and binary evidence for source-led UE4/UT4 format specification and addendum work. This is not a format specification, UnrealDB path inventory, or runtime acceptance certification.

## DEV-only archive

Location: D:\Temp\UnrealDB\UE4\native-version-archive

| Distinct evidence set | Files | Source |
|---|---:|---|
| UT4 packaged client, Shipping | 25 | Existing UT4 Installer v1.1.0 ZIP under M:\iso |
| UT4 packaged WindowsServer, Shipping | 25 | Same package, distinct UE4Server binaries |
| Installed UT4 Editor | 24 | H:\Epic Games\UnrealTournamentEditor |
| UT4UU v10.1.6 mod overlay | 22 | L:\Source\Updates\ut4\UT4UU-v10_1_6.zip |
| **Total** | **96** | **263.64 MiB, all individual files SHA-256 verified** |

The source ZIPs are preserved unchanged. Entire original archive SHA-256 checksums:

- Packaged UT4 Installer v1.1.0 ZIP: 2cf35660cd3a6aa77222e11e101418ec5734f8e2e251afadbed5a2a30aea078b
- UT4UU-v10_1_6.zip: 6f76aea5c8c18347ff6ff58a48764f53fa730aaca1d2e2ebffede8134af5915b

All selected executable, DLL, module, target and build-version metadata paths, file sizes, CRC32 (ZIP entries), and SHA-256 hashes are in the local manifest.json. Local comparison-audit.json records native-module identity. Reproduction and independent auditing scripts are under D:\Temp\UnrealDB\UE4. No PAKs, game content, full installations, or original source files were copied or altered. No installer was run or Sandbox session disturbed.

## Source and binary revision

Both the packaged Build.version and installed editor UnrealTournamentEditor.target identify **UE4 4.15.0, CL 3525360**, compatible CL 3525109, branch **++UT+Release-Next**. The available UT4 game source tree at L:\Source\Games\UT4\UnrealTournament has a README describing **UE4 4.12 preview**; UE4 4.27.2 is a distinct source revision. Neither should automatically be treated as exact 4.15 compiled code.

## Critical original-versus-mod caveat

The UT4 Installer v1.1.0 ZIP **already contains UT4UU plugin binaries and descriptors for client and server**. It is therefore a **community-packaged, mod-bearing distribution, NOT a certified pristine Epic build**. The original UT4 source directory inspected has no UT4UU plugin directory.

Comparison of the separate UT4UU v10.1.6 mod overlay to the already-packaged client/server or installed editor yields:

- **15 matching file names with byte-identical SHA-256**, including all eight selected shipping HTTP, SSL, XMPP and replay modules, six client/server UT4UU/Funchook/Helper DLLs and the UT4UU plugin descriptor.
- **4 differing installed-editor networking DLLs:** HTTP, HttpNetworkReplayStreaming, SSL and XMPP.
- **3 UT4UU/Funchook/Helper editor plugin DLL names** absent from the selected installed-editor baseline.

These comparisons prove byte identities only, **not** which original Epic build produced a file or which UE4 loader or package-format behaviour was executed. Do not treat modified package modules as original Epic behavioural oracles.

## Outstanding authority gap

A clean original official UT4 4.15 build or exactly source-linked Epic UE4 4.15/CL3525360 native reference has **not yet been certified** from these artifacts. For any remaining file-format gap, rely on the matching Epic engine/game source revision and, where source is genuinely absent, targeted native runtime differential evidence. The UE4 format specification must describe serialized types and structures; UnrealDB's implementation path matrix remains a separate scope. UE4 reader code, PRD database and UEDB5 files were untouched.
