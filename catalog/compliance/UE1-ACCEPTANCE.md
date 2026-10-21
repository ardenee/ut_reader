# UE1 — acceptance evidence and runnable checkpoints

Updated 2026-10-21. No UE1 operation has passed the new acceptance definition. **O01 remains IMPLEMENTING.**

## B01 — numeric UE1 package tag boundary

Pinned source: UT99 v400 `Core/Inc/UnObjVer.h:20-27`, `Core/Src/UnLinker.h:354-376`; Unreal v227 `Core/Inc/UnObjVer.h:23`.

Before: 2/6 checks failed because a UE1 reader admitted 0x9E2A83C2. After: 6/6 passed with UE2/Killing Floor acceptance retained. Eight relevant regression scripts passed. Committed/deployed in `698f028f`; DEV/PRD SHA-256 matched for six files.

## B02 — early Unrealfile detection and header-inspector parity

Pinned original-source evidence: `specs/epic-source/ue1/unreal-v0867/01-unrealfile-entry.md`; exact v0.867 C++ linker header and tag defined by `UnBuild.h:108` and `UnLinker.h:258-310,428-454`.

Before changes: 5/9 expected cases failed in `verify-ue1-early-format-identification.php`, including generic unrecognised-magic outcome and UE1-tag misclassification in file-examine. After: **9/9 passed**. Commit `b5f4a214` pushed to GitHub. All seven changed B02 code/test/source/evidence files deployed to PRD through targeted Robocopy, with SHA-256 parity **7/7**.

The test verifies: early fixed-string magic detection, meaningful unsupported error, UE1 identity, version byte 32 (23), negative near-miss, modern v68 header unaffected, UE1 rejection of UE2-only tag, and preserved UE2 acceptance.

Additional selected regressions: **12/12 scripts exited 0**, including `verify-header-only-staging-first-upload.php`, `verify-killing-floor-package-tag.php`, `verify-ue1-package-tag-source.php`, `verify-ue1-pre50-import-layout.php`, `verify-ue1-root-import-integrity.php`, `verify-ue1-verify-import-profile-contract.php`, `verify-unreal-v227-summary-contract.php`, `verify-epic-ue123-reader-parity.php`, `verify-uedb5-ut99-persistence.php`, `verify-ue427-legacy-summary-inspector.php`, and `verify-ue5-583-workflow-identify-inspector.php`. Production modifications and verifier pass PHP syntax checks.

Run all of these on DEV via the local PHP CLI:

```powershell
cd C:\Users\arden\source\repos\ut_reader
$tests = @(
    'verify-ue1-early-format-identification.php',
    'verify-ue1-header-route-validation.php',
    'verify-ue1-package-tag-source.php',
    'verify-header-only-staging-first-upload.php',
    'verify-killing-floor-package-tag.php',
    'verify-ue1-pre50-import-layout.php',
    'verify-ue1-root-import-integrity.php',
    'verify-ue1-verify-import-profile-contract.php',
    'verify-unreal-v227-summary-contract.php',
    'verify-epic-ue123-reader-parity.php',
    'verify-uedb5-ut99-persistence.php',
    'verify-ue427-legacy-summary-inspector.php',
    'verify-ue5-583-workflow-identify-inspector.php',
    'verify-source-scan-boundaries.php',
    'verify-profile-reader-admission.php'
)
foreach ($t in $tests) { php ("catalog/bin/" + $t); if ($LASTEXITCODE -ne 0) { throw $t } }
```

## B03 — header-only production callers must reject failed UE1 parses

Native source: UT99 v400 `Core/Src/UnLinker.h:354-369` (bad package tag aborts). UnrealDB `catalog/lib/CatalogParser.php` is used by source-scan, HTTP-scan and queue-identity callers. Before correction, it silently returned the canonical UE1 reader's fallback blank header instead of examining `validatePackage()` failures.

`php catalog/bin/verify-ue1-header-route-validation.php` reproduced **three failing assertions out of four** before the patch: the UE2-only tag, truncated data and old Unrealfile header did not throw. After the UE1-only validation guard, **4/4 pass**, including unchanged success on a valid v68 summary. A combined selection of **15/15** scripts passed, including B01/B02 tests, source-scan boundaries, profile reader admission, UE2, UE4 and UE5 checks. Both changed PHP files lint.

B03 committed/pushed as `aa3eb737`; all 5 changed code/test/documentation files deployed to PRD and SHA-256 verified 5/5. B03 remains a narrow source-corresponding entry-point correction, not full engine or workflow acceptance.

**Evidence limits:** The early v23 fixture is synthetic but source-anchored; no authentic compiled v0.867 linker oracle or genuine matching binary package has yet been compared. The `.u` files sampled from v0.86x/v0.867 `Classes` are text source files and must not be represented as binary-package test inputs. Do not mark O01 accepted on classification alone.

## O01 complete acceptance conditions (OPEN)

- [ ] Pin/classify each applicable UE1 game and community source branch; define supported old-format policy.
- [x] Source-confirm original v0.867 and UT99 v400 tag decisions.
- [x] Correct cross-engine tag leaks in reader and file-examine inspection.
- [x] Recognise early Unrealfile signature with honest unsupported reporting.
- [x] Ensure real UE1 header-only callers cannot promote a failed parser's blank fallback header.
- [ ] Implement or explicitly agree support boundaries for the genuinely different early format.
- [ ] Trace every real production entry point, profile rule, reader selection, validation and V5 published output.
- [ ] Source-check version/minimum/recovery decisions for each supported revision.
- [ ] Independently compare genuine package and native behaviours wherever possible, including errors and version boundaries.
- [ ] Verify production persistence/search paths and acceptance after patch.
- [ ] Commit, push, deploy and verify final accepted implementation.

One passed helper test is not a whole-operation acceptance. The master dashboard contains exact next actions and operational recovery.
