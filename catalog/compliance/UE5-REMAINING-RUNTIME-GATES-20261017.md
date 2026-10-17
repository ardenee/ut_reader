# UE5.8.3 final runtime branches — bounded cook and Verse gate

17 October 2026. Original operation denominator unchanged: 18, with SCRIPT-005 and B015-1 still partial.

## Confirmed editor coverage

The original directly typed editor trace, stored in `catalog/tests/fixtures/ue5-script-runtime-20261017-original.log.gz`, passed 67,206/67,206 strict event inspection. It records 182 eligible `/Script/` packages, 23,376 editor-enumerated public objects, 9,782 nested-public entries, and actual GC script-map removal. Zero Verse VNI eligible-package events were observed. No Verse-UHT positive/negative cases, VM ScriptCells, or noneditor registration mode were covered.

SCRIPT-003 and SCRIPT-004 are formally verified. B015-1 remains partial, and the earlier claim that no disposable editor binary had ever been built is obsolete.

## Cooked diagnostic runtime blocker

A bounded cook using the *disposable* UE5.8.3 UnrealEditor-Cmd and temporary `D:\Temp\uedb5-ue583-runtime\UE583Audit.uproject` reached Windows cook setup and global changed-shader compilation, but was terminated by a 70-second watchdog before cook completion. The original local log `D:\Temp\uedb5-ue583-runtime\script005-minimal-cook.log` has SHA-256 `681B069B766A5D77CA272901AD52AE732F1C267F0284D3FE876156A2FB2EFA8C`. Only a Zen project-store stub and CookedSettings metadata were created; no runnable cooked project or cooked ScriptObjects chunk was generated.

Separately, the preserved original trace `catalog/tests/fixtures/ue5-script005-name-branches-original.log.gz` (uncompressed SHA-256 `CCFCAFFFD82731116AC75C27858224B61FFC1541B3FEE8A67DC6C85BFF90DDF8`) passed strict inspection 67,212/67,212. It exercised the actual live GetImportName branch and, after a real GC removal, the cooked-diagnostic fallback returning an **empty** name for the ephemeral object. That is not a cooked-data load or a nonempty path result.

## Precise outstanding original acceptance

- SCRIPT-005: complete a cooked game/runtime fixture with ScriptObjects chunk loading at PreCDO under RequiresCookedData/debug flags, then observe nonempty GetImportName fallback for a genuinely missing runtime object.
- B015-1: execute the compiled noneditor check-only and no-check branches, an eligible Verse VNI package containing both UHT-generated and excluded compiler-generated object cases, and the separate VM ScriptCells registry. Existing native stand-in C++ tests do not establish actual engine execution.

Do not repeat verified SCRIPT-003/004 editor work or accept a static source oracle as proof of these unobserved runtime cases. No canonical Epic or Launcher installation was modified in this bounded cook.

## Cleaned engine cook retry (17 October 2026)

The disposable `H:\UE5-583-runtime-probe` engine had retained an earlier deliberately transient `NewObject<UObject>` registration fixture. That fixture produced an object-allocation error in the earlier cook attempt. It was **removed only from the disposable engine source**, then a targeted `AsyncLoading2.cpp` MSVC compile and `UnrealEditor-CoreUObject.dll` relink succeeded (`BUILD_EXIT=0`), without touching the pinned canonical Epic source, installed Launcher engine, or UnrealDB production implementation.

A separate bounded cook of the temporary `UE583Audit.uproject` using the cleaned disposable `UnrealEditor-Cmd.exe`, `-run=cook -TargetPlatform=Windows -CookAll`, proceeded through Windows platform/Zen setup and into global shader compilation. Nine ShaderCompileWorker processes were active. The log reported `FTSRRejectShadingCS(permutation 43, format PCD3D_SM5)` exceeding the 43.062-second shader compile threshold. The 210-second watchdog terminated the cook. The original log was retained at `D:\Temp\uedb5-ue583-runtime\script005-clean-cook-retry.log` (SHA-256 `E064F73ED17850F18ACB1AD9F0E145FA23BDBFB513BC09DEBA10BA8E7DF637E9`). Two files existed in the cooked Windows output: `ue.projectstore` and `UE583Audit/Metadata/CookedSettings.txt`; **no cooked packages or ScriptObjects debug chunk**.

This proves the previous fixture allocation issue is separable from the remaining shader compilation bottleneck. Neither SCRIPT-005's authentic cooked PreCDO/nonempty-name result nor B015-1's noneditor/Verse/ScriptCells runtime branches were exercised, so **the audited operations remain 16/18 verified, two partial**. Do not rerun the editor register/GC tests; those are already accepted. A future cook attempt should address shader compile workload/cache or construct a genuine minimal cooked-data target before attempting a cooked runtime fallback.

## First completed UE5.8.3 cooked ScriptObjects container (18 October 2026)

The disposable editor successfully ran a **single-package Windows cook** using `-run=cook -TargetPlatform=Windows -CookSinglePackage -Map=/Engine/EngineMaterials/DefaultMaterial -ForceDisableSaveGlobalShaders`. Cooked **160/160 packages**, reported `LogCook: Display: Done!`, and exited with **code 0** and clean editor shutdown. Original local log `D:\Temp\uedb5-ue583-runtime\script005-single-package-cook.log`, SHA-256 `F09CF6783EF4F013660E8E4EEA134D5E7107FCBD8C044ACD87D6E071F7F4CE0A`.

Packaging through the disposable engine's AutomationTool produced an authentic `global.utoc/.ucas` IoStore pair and a project-specific IoStore pair. The AutomationTool staging process was **not completed** because it required missing D3D12 redistributables from the disposable tree; do not label the stage as ready for game execution. The generated global pair is nevertheless readable: Epic's own UnrealPak `-ListContainer` reported chunk ID `000000000000000000000005`, type `ScriptObjects`, uncompressed payload **1,534,424 bytes**. The originally produced global pair was SHA-256 `203C39D08CA160942637D344ED89518FD89AF71803801265FE8062346B048650` for the 482-byte `.utoc` and `AF44471C639D433E8552C77D9CEDD0C1EDEB1B1956A554FEAE2E62E92C827F3E` for the 1,534,432-byte `.ucas`.

Both bytes are preserved in Git under `catalog/tests/fixtures/ue5-583-cooked-global.utoc` and `ue5-583-cooked-global.ucas`. The standalone, six-check `catalog/bin/verify-ue5-583-cooked-scriptobjects-artifact.php` uses **UnrealDB's existing production IoStore and ScriptObjects readers** to decode the *actual freshly cooked Epic chunk*: **23,672 cooked script diagnostic entries**. It confirms nonempty offline reconstructed paths `/Script/Engine.MaterialFunctionMaterialLayerBlend` (index `4D0960BDE89F7B36`) and `/Script/ActionableMessage.ActionableMessageSubsystem` (index `5635D6648A5A6CB7`), with `runtime_authoritative=false`.

This clears the prior **cooked artifact creation/format** blocker. It does **not** close SCRIPT-005: neither a cooked `RequiresCookedData` runtime load at PreCDO nor an actual **nonempty** cooked fallback through the engine's `FPackageImportStore::GetImportName` has been observed. The 16/18 original-operation disposition remains unchanged. B015-1 still needs real Verse VNI/UHT and separate ScriptCells execution.
