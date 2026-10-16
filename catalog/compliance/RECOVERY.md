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
* UE5 formal batch manifests: **15 containing 18 operations; 12 verified (B001, B004, B005, B006, B007, B008, B009, B010, B011, B012, B015-2, B016), 6 partial**.
  Baseline was 33 formal open evidence gates. B005 now has exhaustive scoped
  source/inventory/implementation acceptance, including the pinned hybrid
  editor branch model, real V5 selector/rebuilder and isolated temporary MySQL.
  B004, B009 and B010 now have complete source-defined, operation-level
  executable acceptance. **10 original evidence gates remain open.** Full application/engine compliance remains unverified.
* D001-D026: twenty-six known differences linked to original operations or
  explicitly categorized as cross-cutting or previously uncovered behavior.
  D010/D011 are now marked fixed and regression-verified. Original B009 and
  B010 now pass source-contract, implementation, caller, downstream V5 and
  operation-level acceptance with five test drivers each. The pinned UE5.8.3 runtime now provides a
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
* B004 `UE5-583-OBJECT-INDEX-001`: **entire source-defined encoding operation
  verified** by independent 62-bit type/index oracle, negative boundaries,
  all direct parser callers, full synthetic Zen header/V5 persistence and
  dependency-consumer tests. No code defect was found; **no fictitious fix
  was created**. See [B004 source record](B004-OBJECT-INDEX-SOURCE.md).
* Source completeness denominator is unknown: **no percentage** is valid.

## Operation closure order (original work, no new B numbers)

| Order | Existing obligation | Unfinished acceptance scope |
|---|---|---|
| 1 | UE5-583-ZEN-VERSIONING-001 | **VERIFIED**: pinned Epic source, versioned/unversioned and custom GUID wire layouts, malformed headers, V5 round-trip, source-to-caller inventory |
| 2 | UE5-583-ZEN-CONDITIONAL-LAYOUT-001 | **VERIFIED**: Epic threshold 1009/1015, eight-byte scratch pad, main/optional chunks, negative boundaries, final V5 validator |
| 3 | UE5-583-OBJECT-INDEX-001 | **VERIFIED**: exact Epic bit encoding, malformed types, source-derived independent oracle, direct consumers and V5 metadata round-trip |
| 4 | UE5-583-IMPORTED-PACKAGE-NAMES-001 | **VERIFIED**: source-pinned name batch + separate int32 per name, UTF16, empty names, trailing-header allowance D014, malformed bounds, synthetic IoStore and V5 round-trip |
| 5 | UE5-583-OPTIONAL-EXPORT-SEGMENT-001 | **VERIFIED**: separate main/optional package IDs and index-1 chunks, editor/runtime loading gates, main-first public export hash (D015), V5 and dependency consumers |
| 6 | UE5-583-BACKEND-STATUS-001 | **VERIFIED**: ordinary and hybrid source branch oracles, exhaustive caller inventory, selector/rebuilder and real temporary-table SQL test. Runtime authority is explicit, never guessed. |
| 7 | UE5-583-FILE-STORE-001 | **VERIFIED**: pinned Epic source order/sequence, file-entry selection, NAME_None redirect/localization refill (D016), backend/physical consumers; no runtime session fabricated |
| 8 | UE5-583-IMPORT-REDIRECT-001 | **VERIFIED**: CoreRedirect/instancing/loose localization ordering, explicit global store authority D017, explicit identity result D018, editor/runtime ambiguity, PDO/SQL consumer acceptance |
| 9 | UE5-583-PUBLIC-EXPORT-001 | **VERIFIED**: exact valid package/hash keys D019, header-first candidate vs mutable live store D020, collision kept unresolved, V5/SQL/consumer acceptance |
| 10 | UE5-583-B016-1 | **VERIFIED**: pinned Epic main-only cell lookup and combined indices, separate live Verse registry, four negative-before fixtures, compressed V5/SQL and two direct production callers. |
| 11 | UE5-583-B015-2 | **VERIFIED**: Epic's offline ImportedPackageIds enumeration, source identity, optional editor gate, runtime status conservatism and exact provider requirements; original runtime scheduling is not simulated. |
| 12 | UE5-583-SCRIPT-001 | **VERIFIED (offline debug reader):** Source-pinned FScriptObjectEntry, mapped-name lower-30-bit GetIndex D025, TMap last-wins D026, no strict EOF D024, real Satisfactory global.utoc 54,968 cooked entries; full 559-file PHP reachability inventory and runtime exclusion. |
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
