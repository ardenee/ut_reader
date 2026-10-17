# B013 / UE5-583-SCRIPT-HASH-001 — independent UE5.8.3 script-import hashes

**Original operation:** `UE5-583-SCRIPT-HASH-001` (existing **B013**, no new batch). **Source-defined hash calculation verified**, not a claim about the live Unreal Engine global script registry or registration events.

## Pinned Epic source and exact mapping

| Local UE5.8.3 source (beneath `Engine/Source/Runtime`) | SHA256 | Contract |
|---|---|---|
| `CoreUObject/Private/Serialization/AsyncLoading2.cpp` | `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9` | `FPackageObjectIndex::GenerateImportHashFromObjectPath` and `GenerateImportHashFromVersePath`, both clear top two bits |
| `Core/Private/Hash/CityHash.cpp` | `035965ffa9875cc2ad93694a44a45cd8bdc042101e4b84d355b22d089f070d62` | Actual **CityHash64** arithmetic at all byte lengths |
| `Core/Public/Hash/CityHash.h` | `591ef28603dea447d857d5eccea607386f0b4a9b9df283160a5d375cdc6648be` | `CityHash128to64` helper and 64-bit source contract |
| `Core/Public/Misc/Char.h` | `b0a3b64758caddf5151a0396fdb042476bba4d9c9d1eaaeda94318b4b069a0e1` | **`TChar<TCHAR>::ToLower` converts ASCII only**, leaving accented Unicode code units unchanged |

Epic **ordinary script object path** normalization visits each `TCHAR` code unit, maps periods and colons to `/`, lowercases *only ASCII A–Z*, runs `CityHash64` over `Len*sizeof(TCHAR)` **UTF-16LE bytes on the audited Windows platform**, and masks `Hash &= ~(3ull<<62ull)`. This 62-bit payload is stored inside typed `FPackageObjectIndex::ScriptImport`; it is **not** a public-export hash, nor a proof of a live UObject. Epic **Verse path** hashing uses exact `FUtf8StringView` bytes as-is (no separator conversion, no lowercasing) then CityHash64 and the same upper-bit mask.

New pure-PHP source-equivalent implementations:

- `catalog/src/Infrastructure/Metadata/Uedb5Ue5CityHash64.php`: unsigned 64-bit CityHash64 using 32-bit word pairs and 16-bit multiplication limbs; no precision-losing PHP integer overflow, native DLL, FFI, GMP, system shell or external service.
- `catalog/src/Infrastructure/Metadata/Uedb5Ue5ScriptImportPathHasher.php`: `fromObjectPath(string): string` validates UTF-8 (rejects malformed input instead of silently altering Unicode), normalizes source-defined separators and ASCII case, hashes UTF-16LE bytes; `fromVersePath(string): string` hashes unchanged original byte string. Returns exact uppercase 16-nibble **62-bit payload**, top nibble 0–3. Neither computes a package ID nor declares a runtime object registered. UTF-8 PHP API cannot represent a deliberately unpaired UTF-16 surrogate code unit; this is a documented API input limitation, not silently substituted normalization.
- Existing `Uedb5ZenPackageReader` and `Uedb5Ue5ZenDependencyResolver` correctly retain the **serialized** ScriptImport object index. No automatic rehash of imported metadata was introduced. Two read-only diagnostics are explicitly reviewed dynamic hasher callers: `Uedb5Ue5ScriptRuntimeEnumerationAudit.php` (B015-1 expected-key audit) and `Uedb5Ue5ScriptRuntimeTraceInspector.php` (untrusted captured-log index/path validation). Import, restore and resolver paths still consume serialized keys without recomputation. The source inventory is enforced by B013's executable caller allowlist. Downstream `Uedb5SqlProjectionContract::OBJECT_KEY_SCRIPT_IMPORT_HASH = 3` preserves the same raw payload distinctly from public-export hashes, per D027.

## Reproducible independent native differential

`catalog/bin/verify-uedb5-b013-script-hash-native-source.php` checks all four exact Epic source hashes and symbol/branch semantics. It then builds a **disposable native C++ oracle** using the *exact bytes* of Epic's local `CityHash.cpp`, a minimal header shim matching pinned `CityHash.h::CityHash128to64`, a tiny UTF-8-to-Windows-UTF-16 driver and GNU C++ on the authorized Windows development environment. Source is copied to a temporary directory only; **Epic CityHash.cpp, binary game data and executable compiler output are not committed** to GitHub. It compares **98 deterministic independent Unicode/ASCII, separator/case, variable-length (including 16/17/32/33/64/65 and over 256 bytes) vectors** in **both script and Verse modes**; all **196 hash outputs** must match the separately compiled Epic arithmetic. Native execution does not run the UE UObject loader. Compiler/source absence fails acceptance instead of being silently skipped.

Grounded exact values:

- `/Script/CoreUObject.ObjectRedirector` → `1D39669A89BAECB6`, matching the existing source-anchored ObjectRedirector ScriptImport key used in UE5 resolver tests.
- `/Script/FactoryGame.FGRecipeUnlockedDependency:GetRecipes` → `167E29465232A0A9`, matching the **real retained Satisfactory cooked global ScriptObjects** index's 62-bit payload `567E29465232A0A9`.
- `/Script/Engine.Actor` → `245926F283DC695C`; separator and ASCII case variations hash identically as *script* inputs, but not identically as *Verse* UTF-8 bytes.
- `CAFÉ` does not lowercase `É` like Unicode casefolding would; `Café` and `café` hash identically because only the ASCII C changes. Invalid UTF-8 ordinary paths are rejected instead of yielding fabricated source-equivalent hashes; Verse raw-byte hashing is unaffected.

`catalog/bin/verify-uedb5-b013-operation-acceptance.php` combines native oracle, B004 object-index source/consumer acceptance, B003 distinct ScriptImport SQL identity regression and B003 registration source branch inventory; **four independent drivers and 13 acceptance checks**, including an exhaustive traversal of production `catalog/src` PHP files (PRD observed **562**, DEV may differ). Exactly two production paths consume the serialized `script_import_hash`: `Uedb5ZenPackageReader.php` and `Uedb5Ue5ZenDependencyResolver.php`. No existing production code calls the explicit path generator or rehashes package imports. Both hash classes have LF-pinned bytes in `.gitattributes` for auditable Windows checkouts.

## UE5-D029 and compliance boundary

**D029:** Previously UnrealDB could *consume* serialized `script_import_hash` but had no independent source-equivalent ordinary/Verse path hashing implementation or native differential: a known missing source-defined operation. The new implementation supplies exact callable hash functionality, native and authentic vectors; **fixed and regression-verified for this original hashing operation**.

**Not claimed:** actual Epic `ScriptObjects.FindRef` lookup, editor/UHT `NRP_Finished` registration, destroyed/unreachable handling, public nested objects, whether an object is loaded, or package runtime store arbitration. Those remain the separate original SCRIPT-003/004/005 and B015-1 obligations. Global UnrealDB compliance remains **unverified**; no storage backfill, package scan, production DB write, file-version gate change or live engine invocation was performed.
