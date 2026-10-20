# UE4.27.2 P07 — VerifyImport/VerifyImportInner native and production audit (ACTIVE)

**Ledger: 6/10 VERIFIED, U427-P07 ACTIVE.** P01–P06 are closed. Do not certify P07 from the currently passing UT4-specific static matcher or a post-load observation. P08/P09/P10 remain separate fixed operations.

## Pinned source and existing static contract

- Epic reference: `L:/Source/Engine/UE4/UE 4.27.2` revision `d94b38ae3446da52224bedd2568c078f828b4039`, especially `CoreUObject/Private/UObject/LinkerLoad.cpp::VerifyImport` and `VerifyImportInner`, `ObjectResource.*` and `Linker.cpp`.
- Current catalog resolver: `catalog/src/Infrastructure/Persistence/PdoUe4VerifyImportProjectionResolver.php` expressly pins `PROFILE_UT4_CLEAN_MASTER = 'ue4-ut4-clean-master-v511'`. This is **UT4 clean-master V511**, not a proven stock UE4.27.2 profile; its existing static decisions must not be generalized without actual Epic/native evidence.
- The existing matcher tests (`php catalog/bin/verify-ue4-dependency-matching-contract.php`) pass **37/37** deterministic checks: import object/class/class-package/outer, `RF_Public`, editor containment, redirector candidate and descendant payload-unresolved, provider choice boundaries, short fallback and source outcomes. Those are supported static tests, not compiled `VerifyImportInner` execution.
- **Confirmed test-maintenance defect corrected:** the verifier still required exact source strings from removed `catalog/bin/audit-ue4-missing-verifyimport.php`, retired during V5 cutover at `ac5e4a8d`. Removed that false positive dependency; the new assertion ensures the removed V4 CLI is absent and the live resolver/source-outcome path exists. The dated `specs/ue4-4.27.2-verifyimport-conformance-audit.md` now explicitly labels its old residual-audit discussion historical. The passing 37-check denominator is unchanged.

## Independent compiled UE4.27.2 package observation

- `catalog/compliance/ue427-native-oracle/Source/UE427Oracle/Private/UE427OracleP07Runtime.h` adds a read-only `-P07Runtime=...` commandlet mode using compiled `LoadPackage`, `FLinkerLoad::FindExistingLinkerForPackage`, `LoadAllObjects` and post-load `FObjectImport` bookkeeping. Uses installed Editor `H:/Epic Games/UE_4.27/Engine/Binaries/Win64/UE4Editor-Cmd.exe`; no canonical Epic file edits.
- Genuine `/Engine/Animation/DefaultAnimBoneCompressionSettings` and `/Engine/ArtTools/RenderToTexture/MaterialFunctions/CheckerPattern` each loaded with a linker. The native probe saw **7 + 15 = 22 import rows**, **16 loaded `XObject` references**, **0 retained positive `SourceIndex`/`SourceLinker` provider bindings** at post-full-load inspection.
- Independently `php catalog/bin/verify-ue427-p07-native-runtime-imports.php` checks **22/22 imports, 110 source-serialized identity fields**, and the observed runtime XObject/linker-state counts against UnrealDB's actual reader; **zero mismatches**. Native log `C:/Temp/ue427-p07-native-full-load.log` remains on DEV.
- **Interpretation constraint:** the non-exported/private `VerifyImportInner` is **not called directly** by this probe. A populated `XObject` without a retained SourceIndex cannot be called a confirmed file-backed static match; the 22 observations cannot establish all wrapper/redirector or failure branches. Rebuilding the plugin and replaying the two real packages supplies repeatable but deliberately narrow runtime evidence.

### Repeatable partial acceptance

`php catalog/bin/verify-ue427-p07-partial-acceptance.php` currently passes **3/3 bounded checkpoints**: UT4 v511 static matcher 37/37, UE4.27.2 native loaded-package 22/22 import-identity comparison, and V5 dependency-resolution regression. It also checks the explicit UT4 profile boundary and UE4.27 serialized `PackageName` retention. **This is a checkpoint, not P07 operation-level acceptance**.

## Explicit outstanding P07 source branches

1. Execute/directly instrument the compiled `VerifyImport` and `VerifyImportInner` source decision branches in an isolated UE4.27.2 fixture. Log `SourceLinker`, `SourceIndex`, optional/missing provider acquisition, `FObjectImport::PackageName` outer cases and warning/error statuses *at the decision point*, not only after `LoadAllObjects` has finished.
2. Verify ObjectRedirector second-pass `DestinationObject` handling and class/superclass validity, plus redirector-outer propagation; classify absent UObject payload as `payload_unresolved`, not missing or resolved. No static `ObjectRedirector` export should be treated as the destination without the actual payload.
3. Compare native-positive and native-negative source decisions with UnrealDB source outcome classes using genuine linked packages and controlled missing/private/optional provider variants. Preserve editor-only runtime, CoreRedirect config, package mount selection and instancing states explicitly rather than inventing deterministic rules.
4. Confirm P07 static input shape preserves any P07-required `PackageName` context when active UE4.27 package ingestion is introduced; the actual production dispatch and V5 retention are fixed P08/P09 responsibilities. Do not certify them from UT4 clean-master v511 tests.

**Result:** meaningful progress and a retired-verifier fix; P07 remains ACTIVE with an unambiguous test/evidence boundary. No production databases or canonical Epic binaries were modified.
