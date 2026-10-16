# Compliance recovery: accountable source-first closure

**Status: recovery baseline only, NOT an Epic-compliance certificate.**
The machine-readable register is [RECOVERY.json](RECOVERY.json). The original
behavioral obligations remain [batches/](batches/); the existing fixes and
evidence remain [DIFFERENCES.md](DIFFERENCES.md). No new checkpoint sequence or
second competing audit system is introduced.

## Confirmed baseline (2026-10-16)

* Git starting revision: `f3fde99b`.
* Active UEDB5 game profile registry: 7 game IDs (Unreal Gold, UT99,
  Unreal II, UT2003, UT2004, UT3, UT4). Their historical source audits are
  **not accepted** until source reachability and branch inventories are
  reconciled. UE4.27.2 classic and UE5.8.3 classic/IoStore format targets
  are listed separately because the seven-game registry is not a complete
  list of supported format readers.
* UE5 formal batch manifests: **15 containing 18 operations; 1 verified (B005), 17 partial**.
  Baseline was 33 formal open evidence gates. B005 now has exhaustive scoped
  source/inventory/implementation acceptance, including the pinned hybrid
  editor branch model, real V5 selector/rebuilder and isolated temporary MySQL.
  B004, B009 and B010 have bounded inventory subgates. **28 original evidence
  gates remain open.** Full application/engine compliance remains unverified.
* D001-D013: thirteen known differences linked to original operations or
  explicitly categorized as cross-cutting or previously uncovered behavior.
  D010/D011 are now marked fixed and regression-verified. Original B009 and
  B010 each have source-contract and implementation subgates closed by
  executable branch checks. The pinned UE5.8.3 runtime now provides a
  separate source-verified full version-to-export-archive handoff; a
  real cooked versioned-header fixture is optional empirical differential
  evidence, not a prerequisite to establish Epic's actual source rules.
  A correction is not proof that its parent operation is complete.
* D007 IoContainerHeader.cpp behavior is genuinely **unregistered** by the
  existing formal manifests. Add its source-backed obligation in the
  appropriate *existing* source area after reviewing its complete call path,
  rather than disguising it as B006 mounted file-store precedence.
* D009 was a **rediscovered existing B005 open check**, not a new audit
  operation. D013 fixed the remaining selector/rebuilder evidence mismatch.
  **B005 is fully verified as the original backend-status/priority operation**
  with an executable acceptance driver and no fabricated mounted editor state;
  see [B005 source contract](B005-BACKEND-STATUS-SOURCE.md).
* Sixteen required file-processing paths are listed as unverified until
  every relevant source path, UnrealDB caller and outcome is traced.
* B004 `UE5-583-OBJECT-INDEX-001`: original 62-bit encoding inventory,
  source contract and implementation equivalence subgates are verified by
  an independently implemented source reference decoder. No code defect was
  found within its bounded encoding contract, so **no fake correction commit
  or invented difference ID** was created. See
  [B004 source record](B004-OBJECT-INDEX-SOURCE.md); independent final
  acceptance remains open.
* Source completeness denominator is unknown: **no percentage** is valid.

## Operation closure order (original work, no new B numbers)

| Order | Existing obligation | Unfinished acceptance scope |
|---|---|---|
| 1 | UE5-583-ZEN-VERSIONING-001 | Versioning and custom-version serializations, real positive/negative headers |
| 2 | UE5-583-ZEN-CONDITIONAL-LAYOUT-001 | Version-dependent CellOffsets/BulkDataMap and no-versioning branches |
| 3 | UE5-583-OBJECT-INDEX-001 | PackageObjectIndex encoding, null/reserved/boundary handling |
| 4 | UE5-583-IMPORTED-PACKAGE-NAMES-001 | Batch names, per-name numbers, malformed boundaries |
| 5 | UE5-583-OPTIONAL-EXPORT-SEGMENT-001 | Segment-specific import/store entries and editor/runtime behavior |
| 6 | UE5-583-BACKEND-STATUS-001 | **VERIFIED**: ordinary and hybrid source branch oracles, exhaustive caller inventory, selector/rebuilder and real temporary-table SQL test. Runtime authority is explicit, never guessed. |
| 7 | UE5-583-FILE-STORE-001 | Complete mounted family, order/sequence, first-wins maps |
| 8 | UE5-583-IMPORT-REDIRECT-001 | Core redirect, localization, package-store arbitration and identity rewrites |
| 9 | UE5-583-PUBLIC-EXPORT-001 | Exact package + export hash lookup and collision scenarios |
| 10 | UE5-583-B016-1 | Public cell exports separate from ordinary public exports |
| 11 | UE5-583-B015-2 | Recursive imported-package scheduling and optional-context behavior |
| 12 | UE5-583-SCRIPT-001 | Cooked ScriptObjects chunk and diagnostics |
| 13 | UE5-583-SCRIPT-002 | Global ScriptObjects name-map evidence |
| 14 | UE5-583-SCRIPT-003 | Script-import resolution vs live object store |
| 15 | UE5-583-SCRIPT-004 | Script registration, nested public object identities |
| 16 | UE5-583-SCRIPT-005 | FindRef/diagnostic fallback distinctions |
| 17 | UE5-583-SCRIPT-HASH-001 | TCHAR path normalization, CityHash64 and hash bit mask |
| 18 | UE5-583-B015-1 | Script/Verse VNI enumeration, editor/runtime registration branches |

The order is a dependency-driven *work queue*, not a claim that these
18 operations exhaust UE5. Source coverage needs to be expanded and
reconciled for Unreal Gold, UT99, Unreal II, UT2003, UT2004, UT3, UT4,
UE4.27.2 and UE5.8.3 separately. Do not import rules between sources
without explicit shared-source evidence.

## Acceptance contract

Every operation must demonstrate, with pinned applicable Epic source:

1. Enumerated reachable paths, conditional branches, version/error paths,
   with documented intentionally excluded runtime-only operations.
2. A source-to-UnrealDB mapping for all relevant branches, including caller
   coverage through upload, restore, resync, repair, Pass 2, search and reads.
3. Every difference linked both ways to the original operation, with a
   disposition and correction revision, or an explicit justified unsupported
   behavior. **Never silently skip.**
4. Failing-before/passing-after regressions for corrections and independent
   differential evidence from real packages/source-derived execution where
   feasible; synthetic checks alone are not parity acceptance.
5. V5 metadata and persisted SQL outcomes checked end to end. Runtime-only
   state (mount order, active culture, editor flags, script objects) must be
   independently supplied or remain explicitly unresolved, never guessed.
6. An independent audit reviewer checks the coverage map and acceptance
   evidence. Accepted behavior is reopened when the source baseline,
   implementation, or any consumer changes.

An omission in an **accepted** operation becomes a documented audit escape.
D009 was instead a rediscovery of a **previously open** B005 gate. Both are
tracked, but must not be conflated.

## Enforced commands

From the repository root, with PHP 8.5:

```powershell
& C:\php8.5\php.exe catalog\bin\verify-compliance-recovery.php
& C:\php8.5\php.exe catalog\bin\verify-compliance-recovery-regression.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-ue5-source-batches.php
$env:UNREALDB_EPIC_SOURCE_ROOT='L:\Source\Engine\UE5\UE 5.8.3'
& C:\php8.5\php.exe catalog\bin\verify-source-compliance.php --all
```

`verify-compliance-recovery.php` returns success when the *inventory is
internally consistent*, while its `compliance_accepted=false` and
`scope_denominator_known=false` remain explicit. `--strict` intentionally
returns nonzero until genuine accepted coverage is established. The existing
`verify-source-compliance.php --all` now includes the recovery consistency
check and fails if the register becomes internally inconsistent. It does not
turn an open/partial gate into a verified one.

The pinned UE5.8.3 Zen version-handling call path can be checked without
UnrealEditor: run `catalog/bin/verify-uedb5-zen-runtime-version-source.php`
and see [the runtime header source contract](UE5-ZEN-VERSIONED-HEADER-SOURCE.md).
These checks verify source control flow, not compiled UE runtime execution.

Eleven regression assertions exercise negative cases: missing original
operation, orphaned difference, invalid parent, missing game, false
compliance claim, missing workflow route, duplicate operation, lost
reciprocal link, and strict denial.

**Current implementation checkpoint:** original B009 and B010 now have
source-pinned D010/D011 corrections, three failing-before/passing-after
regressions (including the eight-byte scratch boundary), five version-threshold/no-versioning
byte fixtures, nine B009 and ten B010 enforced contract assertions, and two
real retained Satisfactory no-versioning snapshots, plus a read-only
15-member spread sampling of actual serialized versioning flags (15 unversioned,
zero versioned). The machine verifier
closes two source-contract, two implementation, two downstream Step 8
consumer-validation, and two source-to-production reachability subgates.
The reachability inventory [B009-B010-CALLPATHS.md](B009-B010-CALLPATHS.md)
scans all production PHP (559 files in the recorded run) and rejects
unmapped direct parser/builder calls. Main and optional header corruption
is now also tested through the IoStore snapshot builder. D012 records seven failing-before/passing-after
consumer checks. All six subgates are backed by executable evidence; the
complete operations remain partial. The absence of an UnrealEditor binary
and a particular cooked versioned sample are **not blockers to auditing the
pinned engine source**. The exact runtime call graph, versioned/unversioned
fallback and export archive handoff are documented in
[UE5-ZEN-VERSIONED-HEADER-SOURCE.md](UE5-ZEN-VERSIONED-HEADER-SOURCE.md).
Next: close the remaining unverified V5 production entry-point reachability,
independent inventory coverage, malformed-input behavior and independent
review. A cooked-file example is not a prerequisite for reading Epic source. No additional B
checkpoint was created.

No full-file backfill, production database write, destructive checkout,
or blanket source scan is authorized by this baseline.
