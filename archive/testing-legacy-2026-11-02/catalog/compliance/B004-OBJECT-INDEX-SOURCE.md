# B004 — FPackageObjectIndex encoding source audit

**Original obligation:** `UE5-583-OBJECT-INDEX-001`, not a new B batch.

**Pinned Epic baseline:** UE5.8.3
`Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h`
SHA256 `088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2`,
lines 34–159. Actual import/export object consumers reside in
`AsyncLoading2.cpp`; source rules are the type and value encoding, not an
external file-version gate.

## Exact source semantics and UnrealDB correspondence

| C++ entry/branch | Source-defined representation | UnrealDB implementation |
|---|---|---|
| `IndexBits=62`, `TypeShift=62` | 64-bit TypeAndId uses high two bits to distinguish 0 Export, 1 ScriptImport, 2 PackageImport, 3 Null | `Uedb5ZenPackageReader::decodeObjectIndex` masks high two bits; preserves raw hex and low 62 bits |
| `Invalid=~0ull`; `IsNull()` | **Only** `FFFFFFFFFFFFFFFF` is null. Type tag 3 without exact sentinel is not null. | Exact sentinel maps to Null; malformed type-3 non-sentinel rejected |
| `FromExportIndex(int32)`; `ToExport()` | Valid local export index read from low uint32 bits | Export index decoded, bounded by ordinary + cell export counts |
| `FromScriptPath` / `FromVersePath` | A 62-bit masked global hash occupies the ScriptImport value | 62-bit value retained as exact 16-character hex `script_import_hash` |
| `FromPackageImportRef`; `ToPackageImportRef()` | ImportedPackageIndex (30 usable high value bits) and ImportedPublicExportHashIndex (low uint32) | Index pair preserved and resolved against **ordered** source package-store imported IDs and header public export hash table |
| Missing package/hash entry | Epic's runtime path checks index validity when attempting resolution | UnrealDB raises explicit malformed package reference (does not invent a provider) |

**Production caller inventory for this *encoding* operation:** `Uedb5ZenPackageReader::readObjectIndexSpan` (main/optional ImportMap and CellImportMap) and four `decodeObjectIndex` reads in `readExportMap` (OuterIndex, ClassIndex, SuperIndex and TemplateIndex). Both entry routes are shared between main and optional segments. `Uedb5Ue5ZenIoStoreSnapshotBuilder::classifiedObjectIndexes` preserves the type and provider keys, and `Uedb5Ue5ZenDependencyResolver` consumes the resulting metadata. Provider availability, public export lookup, mounted backend priority and script object registration are **different original obligations** (B005/B007/B015/script operations), not implied complete by B004.

## Independent mechanical reference

`catalog/bin/verify-uedb5-zen-object-index-source-oracle.php` independently derives source-equivalent tags and indices using hexadecimal 32-bit halves. It does **not** ask the production decoder for the expected answer; it invokes that decoder only for comparison. It pins the Epic header source hash and tests all four tags, valid boundaries and malformed package/export/index values, plus scans the five direct source decode sites and their downstream classification/reference routes.

The result of that comparison was **no identified source difference in this bounded decoding scope**. Therefore no invented code fix, audit-escape ID or fake correction commit is justified. The original implementation gate is satisfied with a verified independent **code-path comparison**, and the formal verifier checks that the same oracle passes whenever this source is audited.

Relevant tests: `verify-uedb5-zen-object-index-source-oracle.php`,
`verify-uedb5-zen-object-index-boundaries.php`,
`verify-uedb5-ue5-zen-package.php` (actual synthetic `.utoc/.ucas` parsing, optional segment, persisted V5 reference sections) and
`verify-uedb5-ue5-zen-dependency-resolution.php`.

**Acceptance boundary:** The source contract, byte decoder and bounded source-to-UnrealDB callers are verified; this is not proof that all UE5 import resolution and UObject class loading behave exactly like Epic. The original B004 **operation-level verification is now complete**: `verify-uedb5-b004-operation-acceptance.php` reruns four separate drivers with ten required assertions (source oracle, boundary cases, synthetic full IoStore header-to-V5 round-trip and dependency consumers). The formal verifier independently runs that acceptance driver and invalidates this operation on source/implementation changes. This does not certify sibling UE5 backend/provider/object-loading operations. Do not claim the whole engine conforms on this evidence.
