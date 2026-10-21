# UE1 — acceptance evidence

Started 2026-10-21. **No UE1 operation is accepted** under the new definition of done. O01 is implementing.

## Verified bounded defect: UE1-D001

Source anchors: UT99 v400 `Core/Inc/UnObjVer.h:20-26` and `Core/Src/UnLinker.h:354-376`; Unreal v227 `Core/Inc/UnObjVer.h:23`.

Procedure on DEV:

```powershell
cd C:\Users\arden\source\repos\ut_reader
php catalog/bin/verify-ue1-package-tag-source.php
php catalog/bin/verify-killing-floor-package-tag.php
php catalog/bin/verify-ue1-pre50-import-layout.php
php catalog/bin/verify-ue1-root-import-integrity.php
php catalog/bin/verify-ue1-verify-import-profile-contract.php
php catalog/bin/verify-unreal-v227-summary-contract.php
php catalog/bin/verify-epic-ue123-reader-parity.php
php catalog/bin/verify-uedb5-ut99-persistence.php
```

**Baseline before fix:** 2 of 6 checks failed (UE1 v400 and v227 incorrectly accepted tag 0x9E2A83C2). **After fix:** all 6 checks pass. All 8 executed scripts exit 0.

The new fixture tests intentionally hold the summary at UE1 version 68, zero tables and a deterministic GUID to isolate the Epic tag decision. The expected tag derives from the pinned C++ source, not UnrealDB.

**Evidence limit:** these are PHP source-anchored negative/positive tests, not a comparison with a running compiled Epic engine. They do not prove all O01 branches, production admission, actual caller-visible outcomes, genuine package corpus parity, or the whole engine.

## O01 acceptance checklist (OPEN)

- [ ] Pin exact supported UE1/UT99 game source revisions and provenance.
- [ ] Agree the supported engine/package version and game-fork matrix.
- [x] Identify reference tag branch in inspected v400 / v227 source.
- [x] Compare and fix shared UE1 parser's cross-engine tag admission.
- [x] Verify UE2/Killing Floor behavior remains intact.
- [ ] Trace all real production file identification, dispatch, profile, upload and inspection paths.
- [ ] Verify exact applicable version-gate behavior and supported product policy.
- [ ] Compare sufficient genuine package inputs and failure paths independently.
- [ ] Verify normalized data and observable production outputs.
- [ ] Complete O01 evidence and mark ACCEPTED only when every agreed requirement passes.

Deployment and commit details are recorded in the master dashboard when verified.
