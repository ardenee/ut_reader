# B016 — Epic UE5.8.3 public Verse-cell lookup source contract

**Original operation:** `UE5-583-B016-1`. This records the bounded source-defined offline lookup/dependency behavior, not execution of Epic's live Verse VM or a fabricated `FGlobalImportStore` / `FPackageCells` state.

## Pinned source and control flow

`Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` SHA256 `43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9` was independently read from the UE5.8.3 engine tree. `verify-uedb5-b016-public-cell-source.php` verifies 19 distinct source and implementation contracts.

| Epic source operation | Exact branch/identity | UnrealDB evidence and mapping |
| --- | --- | --- |
| `FAsyncPackage2::GetPublicCellExportIndexAsLocalIndex` | Scans **only** `HeaderData.CellExportMap`, first matching public hash; returns `CellExportIndex + HeaderData.ExportMap.Num()`; -1 if absent. Unlike ordinary public UObject lookup, it does **not** search the optional segment header. | Main provider cell map remains distinct. `selected_provider_object.local_export_index` is main ordinary export count + main cell index. A source-header first candidate is diagnostic, not live runtime ownership. |
| `ConditionalCreateCellImport` / `ConditionalSerializeCellImport` | Combined local import index is converted by subtracting ordinary `Header.ImportMap.Num()`; cell imports use `FindOrGetImportCell` and cell-specific serialize path. | `source_local_import_index` is ordinary import count + cell import index; dependency bundle combined indices remain preserved. |
| `FGlobalImportStore::FindPublicExportCell`, `FPackageCells::StorePublicCellExport` | Resolves by **package ID and export hash** in a distinct Verse cell store; same-hash `PublicExportMap.Add` can replace the stored cell. A header candidate cannot prove which cell is currently registered. | Duplicate cell hashes remain `unresolved/public_cell_export_hash_runtime_collision_ambiguous`; record `source_header_first_cell_export` with `runtime_lookup_authoritative=false`. Do not coalesce with ordinary public UObject exports. |
| `EventDrivenCreateCellExport` | Registration is conditional on importable-package identity and nonzero hash, with `WITH_VERSE_VM` build guard. | An optional segment cell-export hash alone cannot prove a live registration. In an explicitly editor-capable context return `unresolved/optional_segment_public_cell_runtime_registration_required`; non-editor rejects optional-only cell export. |
| `FPublicExportKey::FromPackageImport` | Uses source package ID plus public export hash, not filename/ordinary UObject export hash as a substitute. | Exact selected physical provider and hash remain mandatory; B007/B008/B012 guard key, identity and optional UObject lookup separately. |

## Confirmed differences, linked to the existing operation

- **UE5-D021 (optional cell false resolution):** before correction, optional-segment cell metadata could directly resolve an editor-mode cell import. Epic's main-only header lookup and separate live cell registry do not authorize this. The resolver now reports the missing runtime-registration evidence explicitly.
- **UE5-D022 (cell combined indices and collision provenance):** before correction, ordinary-export offsets and ordinary-import offsets were not retained in resolved cell row provenance; duplicate cell hashes lacked Epic's first main header candidate. The resolver now retains combined indices, returns the first source-header candidate as nonauthoritative, and keeps hash collisions unresolved.

`verify-uedb5-b016-historical-regression.php` loads the unmodified resolver from commit `4e4eaf9e` (source blob SHA256 `9cd03b4322bbc56ed5f6998f143cd65403d55474dd66dde544d02837628cf990`) under a distinct class name and executes the **same fixtures** on baseline and corrected classes. Four previously incorrect behaviors are demonstrated, including optional cell `resolved` before and `unresolved` afterwards. It requires local Git history; failure to retrieve the pinned baseline is a failing test, not silent success.

## Executable acceptance and limitations

`verify-uedb5-b016-operation-acceptance.php` runs nine distinct driver programs and enforces 20 checks: pinned Epic source branches; main, optional and ambiguous lookups; combined import/export offsets; V5 metadata persistence of exact keys and collision provenance; other established B007/B008/B012 acceptance; rebuilder; session-temporary SQL and compact-reader results. `verify-uedb5-b016-public-cell-source.php` inventories **all 559 production PHP files**, finding exactly two direct resolver consumers: `PdoUedb5PhysicalProviderSelector.php` and `Uedb5DependencyRebuilder.php`.

The test builds a **real compressed temporary UEDB5 metadata file** and reads it back. The SQL test uses isolated/session-temporary SQL, not full production table changes. These establish the source-defined *offline* resolver contract, not an executed Verse VM, actual mounted editor, all real-world cooked Verse packages or whole-engine UE1–UE5 compliance. Static data must never be promoted into authoritative live-cell resolution. A change to Epic source, implementation, direct callers or downstream consumers must reopen B016 until the checks are rerun.
