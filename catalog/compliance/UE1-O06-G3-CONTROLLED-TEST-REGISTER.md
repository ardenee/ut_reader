# UE1 O06 G3 — acceptance group / closure register (16 groups)

**IMPORTANT: This is NOT the individual test list.** The actual executable, finite **80-test T001–T080 backlog** is [UE1-O06-G3-EXECUTION-TEST-BACKLOG.md](UE1-O06-G3-EXECUTION-TEST-BACKLOG.md), with editable status JSON [here](UE1-O06-G3-EXECUTION-TEST-BACKLOG.json). Original source/version, exact stimulus, PASS expectation and evidence are listed **per test** there. This document retains only aggregate G3-XX acceptance decisions and the historical 52 planning criteria. Discovered tests append at the BOTTOM of the new backlog as S001, S002, ... and are handled after T001–T080. Do not keep treating G3-01..G3-16 as individual tests.

**Baseline:** 2026-10-28 · **State:** PROPOSED / USER REVIEW — EXECUTION STOPPED · **Primary authority:** original Epic sources and actual compiled behavior, not UnrealDB-only tests.

This document records the **16 broad G3 acceptance groups only**, not the individual test execution list. Use T001–T080 from the linked executable backlog. It supersedes the *workflow* in older expandable plan/status narratives, without deleting historical tests or changing accepted technical evidence. The 16 existing contracts are fixed, with 52 baseline acceptance conditions (14 supported by already accepted/subscope evidence; this subcondition count is **not** a test pass count). Exactly 5/16 gates are fully ACCEPTED; 11 remain OPEN. No test will be run merely because it is listed here until the user approves the baseline.

## Historical group-acceptance rules (test execution is controlled by T001–T080 backlog instead)

1. **No unplanned test execution.** Work through G3-01 → G3-16 **once**, reusing existing proof. Each gate gets ONE consolidated acceptance decision: `ACCEPTED`, `FAILED` (a named original-vs-UnrealDB defect), or `BLOCKED` (an exact missing prerequisite); `OPEN` denotes not decided. We do not create a new acceptance gate per discovery.
2. **Evidence reuse.** Hash/source/version-valid completed native evidence remains sealed. No repeat of previously passing original CDB runs, no benchmark/count farming, and no re-running an unchanged scope just to increase confidence. One focused changed-behavior check is allowed only after a diagnosed production fix.
3. **One pass through the baseline list.** If a gate cannot be decided, mark its blocker and proceed to the next planned gate rather than spending successive sessions generating subproofs. G3-16 cannot run while predecessors are blocked. Resolve recorded blockers after the initial pass by approved priority.
4. **New findings are appended only at the END** in the `Additional subtests` register, IDs `S-001`, `S-002`, ...; each must identify its parent `G3-XX`, original source/compiled branch, why baseline obligations do not cover it, proof required, execution owner, and whether its omission prevents closure. Do not insert a new test into the 16-row baseline or run it before it is reviewed and prioritized.
5. **Changes are controlled.** Once the baseline is approved, its IDs, order and acceptance wording are frozen. Update ONLY decision status, precise blocker, evidence SHA/commit and append-only subtest section. If a baseline wording change is necessary, document and explicitly approve a revision; do not silently amend it.
6. **Source is master.** Source-line/compiled-engine behavior is the acceptance criterion; synthetics alone do not prove original native results. Do not claim cross-version runtime LOAD succeeded when it failed before the target method. Assign inherited script errors to G3-12, general archive diagnostics to G3-14 and whole-corpus loaded equivalence to G3-15 without losing a source-owned behavior.
7. **Stop condition.** At each gate, stop immediately once the fixed criteria are conclusively satisfied, a real product defect is diagnosed, or a specific oracle is blocked. Do not spin on “continue” with incrementing test counters.

## Baseline: 16 gates, fixed order

| Gate | One acceptance decision | Current status | Already accepted evidence / exact outstanding obligation |
|---|---|---|---|
| **G3-01** | Epic source lineage and version provenance | **CLOSED / ACCEPTED** | 87/87 original acceptance; three SHA-pinned source/verifier/result files independently checked against sealed recorded hashes on 2026-10-28. No test rerun or added subtest. |
| **G3-02** | Complete Core serializer method and inheritance inventory | **CLOSED / ACCEPTED** | 1,318/1,318 previously accepted; 57 C227 symbols and 205 source declarations across five revisions. All three previously pinned manifest/verifier/DEV-result hashes independently matched on 2026-10-28; no test rerun. |
| **G3-03** | UField / UStruct serialization | **BLOCKED — NOT ACCEPTED** | One-pass conclusive evidence review: C98 100/96 and C227i 237/223 accepted subsets; original 469f 25 UStruct prefixes + nine direct objects reused; no complete original v432/227k direct UStruct loaded-value/SAVE comparison, C98 complete Children target mapping, or own source-specific invalid/ref/consumption closure. Do not rerun accepted evidence. |
| **G3-04** | UState / UFunction serialization | **OPEN** | 17 sealed original compiled subproofs: five original SAVE paths, 469f 1,422 object loads, 1998/469f/227k malformed behavior |
| **G3-05** | UClass serializer and actual class defaults | **OPEN** | G5 v432 UClass accepted separately; not yet whole C98/T99/C227 G3 accepted |
| **G3-06** | UConst / UEnum | **ACCEPTED** | 56/56; original loaded string/global name values C98/T99/C227; source negatives |
| **G3-07** | UProperty base metadata | **ACCEPTED** | 2,991/2,991; original C98 1,536, T99 490, C227 668 property loads |
| **G3-08** | Scalar property subclasses, strings and UAny | **OPEN** | 7 limited native subproofs: UAny null/zero and primitive types; nonzero refs/struct/NET/Button remain |
| **G3-09** | Object/class reference property declarations | **ACCEPTED** | 222/222; original C98/T99/C227 class targets/null behavior |
| **G3-10** | UStructProperty nested members | **OPEN** | Some nested layout evidence; full original source/native comparison missing |
| **G3-11** | Array, fixed-array and map property serialization | **OPEN** | Some source-specific inner/array evidence; no whole-method acceptance |
| **G3-12** | Script bytecode serialization and fixups | **OPEN** | v432 G5 bytecode evidence sealed; C98/C227 opcode branches and invalid cases pending |
| **G3-13** | Tagged property values and default/config effects | **OPEN** | G5 tagged/default evidence accepted separately; C98/C227 behavior not fully reconciled |
| **G3-14** | Crosscutting version and malformed FArchive behavior | **OPEN** | Original C98/469f/227k error branch and physical cursor evidence sealed, broader matrix open |
| **G3-15** | Native loaded-value and genuine-file parity | **OPEN** | Existing original C98/T99/C227 loaded-value samples substantial but not whole method/profile corpus |
| **G3-16** | Independent one-time whole-G3 acceptance and release | **OPEN** | Cannot execute before G3-01 through G3-15 are accepted |

**Execution order rule:** mark G3-01/G3-02/G3-06/G3-07/G3-09 accepted from existing sealed evidence; evaluate the other gates in numerical order. Do not return repeatedly to G3-04 while G3-05, G3-08, G3-10 through G3-15 have not had one conclusive planned assessment.

## Frozen acceptance checklist under each gate

A checked condition is **already supported or sealed**, not a request to execute the corresponding old script. Unchecked conditions are the **predeclared remaining obligations**, not new tests to invent. These are acceptance criteria within the 16 gates, not additional top-level gates.

### G3-01 — Epic source lineage and version provenance

**Final gate decision (2026-10-28): CLOSED / ACCEPTED.** 87/87 accepted original source-selection checks, without rerun. Both baseline acceptance conditions satisfied. **Sealed source manifest:** `catalog/compliance/UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json` SHA-256 `a6f737801ecb9634ed9ee797ef04bc0c5ced0d99ef6ac585d4e11a42eb683901`. **Sealed acceptance verifier:** `catalog/bin/verify-ue1-o06-g3-01-chronological-source-inheritance.php` SHA-256 `4ec651bb6f68b215188050f7541425cd2acc42cc5512dda751db843237a05f54`. **Original DEV 87/87 PASS result:** `catalog/compliance/evidence/o06/20261027T102503120Z-98b1e087-DEV-verify-ue1-o06-g3-01-chronological-source-inheritance.php.txt` SHA-256 `eefa95a39439544273fdcc31043665e4ee1aa8ad4f6f3a1a7e9ce09f77e35c58`. **All three actual hashes match their pre-existing pinned closure manifest.** No new test, no new native probe, no changed serializer. **Scope boundary:** source lineage, inheritance, explicit provenance selection and version eligibility ONLY. C227 altered headers/class-specific bodies remain owned by G3-02/G3-03..15; original compiled loaded-value parity is G3-15. **Reopening condition:** a demonstrable change in a pinned source/verifier/manifest hash or a source-selection defect; new ordinary serializer behavior cannot reopen G3-01. **Next gate:** G3-02 (already accepted; no rerun).

- [x] Freeze source-selection/version/build provenance evidence across UE1, Unreal revisions and UT99 v432 (v400 inherited), explicit 469f/227i/k overlays.
- [x] Demonstrate incorrect game, revision and shared package-version selection fails rather than silently borrowing another game's serializer.

### G3-02 — Complete Core serializer method and inheritance inventory

**Final gate decision (2026-10-28): CLOSED / ACCEPTED.** 1,318/1,318 original acceptance checks retained, no rerun; both frozen baseline conditions satisfied. **Sealed method register:** `catalog/compliance/UE1-O06-G3-CUMULATIVE-CORE-SERIALIZER-METHOD-REGISTER.json` SHA-256 `1422de5431bc3538e9f199b8163513d50fd18bd6ef4bb40bed02b97509af57d7`. **Sealed verifier:** `catalog/bin/verify-ue1-o06-g3-02-complete-cumulative-method-symbol-register.php` SHA-256 `37de321423dae3ec9e9b018e0ce6f72949b7cdcc29868714975a0e673b8400f8`. **Original DEV 1,318/1,318 PASS log:** `catalog/compliance/evidence/o06/20261027T115222126Z-ecb4bae9-DEV-verify-ue1-o06-g3-02-complete-cumulative-method-symbol-register.php.txt` SHA-256 `481bb0ee08dcc80b1d9c02874bdb9b341a6fd991479228cde6b9020f32a95ebb`. All three actual file hashes independently match the prior pinned closure manifest. This closes source symbol/method and inherited implementation inventory ONLY, including 57 actual C227 exports, 205 Unreal/UT99 declarations and source-defined exclusions; UButtonProperty actual runtime `Serialize` remains G3-08. Runtime LOAD/SAVE, version, native loaded values and archive errors belong to G3-03..G3-15, not G3-02. **Reopen only upon modified pinned source/verifier hashes or concrete omission from the source-method inventory.** No new tests or appended subtests. **Next gate:** G3-03.

- [x] Reconcile all relevant Core class/property methods, inherited archives, source revisions and legitimate exclusions.
- [x] Pin original source/compiled symbols and assign each distinct archive behavior to exactly one downstream G3 owner.

### G3-03 — UField / UStruct serialization

**One-pass gate decision (2026-10-28): BLOCKED — NOT ACCEPTED.** The existing evidence is sufficient to preserve checked baseline criterion 1 but NOT to check criteria 2–4. No new test was run or created. Original C98 retail compiler: 100 native UStruct-derived function exports, 96 real loaded scripts and 1,492/1,492 source-native scoped result; C227i shipped 2012 compiled Core: 237 loaded objects, 223 scripts, 232 native resolved Children pointers and 4,412/4,412; 469f: 25 UStruct-inherited live prefixes (including 21 Children links), nine separate direct UStructs (six Children links) and genuine NAME_None assertion case. **Existing source/native evidence manifests rechecked by SHA**: `catalog/compliance/UE1-O06-G3-03-C98-MAY1998-100-ORIGINAL-NATIVE-96-SCRIPTS.json` `481bc10b917d7eea128a1638d31be2a5afdd1f84eb6edbf2592359040a76e7d0`; `catalog/compliance/UE1-O06-G3-03-C227I-237-ORIGINAL-NATIVE-223-SCRIPTS.json` `cd8ff5a0198bfca58ec04aa0056af74927ec0dd31cff818b93c59e43dec77aa9`; 469f 25-value manifest `38d58e4391a83b24465904c6a9ed12863f125abfdbf1a4d5d8b12d84386389a9`, nine-direct manifest `7398260b9f32468c2c63e63d736ff9a4664018f5a31d4103326de6828c8ae4f2`, direct malformed field-name manifest `348c0c2aeb8d9c1dd39e51fbea1a57b16e751151a18c3044fd2af8b8efbb0fc7`. **Precisely unfulfilled direct contract obligations:** (A) actual source-selected UT99 v432 and original 2026 C227k `UField/UStruct::Serialize` direct/native loaded-value equivalence, including child/super and zero/nonzero script-size variants; 469f direct objects do not establish v432/c227k equivalent original engine behavior; (B) C98 nontrivial Children/Super target identity (the previously observed real retail ScriptText archive reference may be NULL in runtime; do not assert static archives are native pointers), plus method-specific original SAVE/LOAD across missing builds; (C) unresolved **UField/UStruct-owned** invalid reference/size/EOF and precise physical cursor. Opcode bodies and token interpretation are *separately* G3-12, generic cross-method FArchive/EOF diagnostic matrix is G3-14, and exhaustive whole-corpus native values are G3-15; no duplicate tests. **Decision:** BLOCKED, NOT release-ready; gate G3-16 must not accept G3-03. **Next action:** proceed to G3-04 in fixed order while the above exact three baseline conditions remain unchecked. New discovery can only be appended to the END as approved S-subtest, not inserted or executed automatically.

- [x] Reuse original compiled C98 and C227i actual script/struct loaded-value snapshots and applicable 469f native struct snapshots; do not rerun.
- [ ] Close missing build-specific direct UField/UStruct object, child/super reference, SAVE/LOAD and exact script-size coverage in T99 v432/469f and C227k using genuine existing native evidence or ONE conclusive new required comparison.
- [ ] Verify source-defined zero/nonzero scripts, malformed inherited struct refs, script length and serialized/memory-size rules; assign opcode-specific failures to G3-12 and archive diagnostics to G3-14 without double-counting.
- [ ] One consolidated G3-03 decision with source/exclusion/version matrix and original compiled loaded-value equivalence.

### G3-04 — UState / UFunction serialization

**Gate status:** OPEN. **Evidence known:** 17 sealed original compiled subproofs: five original SAVE paths, 469f 1,422 object loads, 1998/469f/227k malformed behavior.

- [x] Reuse accepted C98, T99 v432/469f and C227i/k original UState/UFunction LOAD/SAVE, FUNC_Net, masks/flags, computed parameter values, byte boundaries and real malformed-state/function results.
- [ ] **One specific outstanding direct version case:** on a *genuinely loadable* UT99 v60–v63 UFunction, prove compiled original legacy ParmsSize/NumParms/ReturnValueOffset serialization plus runtime parameter recomputation; do not use the excluded 469f-on-C98 script or invalid standalone synthetic attempts.
- [ ] Make ONE final G3-04 decision from the source-version/method inventory; crosscutting script bytecode belongs G3-12, general FArchive error semantics G3-14, and broader runtime loaded-object corpus G3-15. If a direct UState method requirement remains unproven, identify it by source line in the single decision, not by speculative repeated tests.

### G3-05 — UClass serializer and actual class defaults

**Gate status:** OPEN. **Evidence known:** G5 v432 UClass accepted separately; not yet whole C98/T99/C227 G3 accepted.

- [ ] Compare source-selected UClass::Serialize archive fields, inherited class/within/config flags and version gates for C98, T99 and C227.
- [ ] Compare original compiled default-object construction, inherited properties and nontrivial saved versus loaded values on each distinct method/profile.
- [ ] Verify class-related Config/Localized/Transient effects with genuine source-selected defaults; assign generic tagged stream to G3-13.
- [ ] Check source-defined invalid class/default/reference cases and make one G3-05 acceptance decision.

### G3-06 — UConst / UEnum

**Gate status:** ACCEPTED. **Evidence known:** 56/56; original loaded string/global name values C98/T99/C227; source negatives.

- [x] Archive LOAD/SAVE order, FString, enum name/count, source version/charset, null/error outcomes and original native loaded values all accepted; no reruns.

### G3-07 — UProperty base metadata

**Gate status:** ACCEPTED. **Evidence known:** 2,991/2,991; original C98 1,536, T99 490, C227 668 property loads.

- [x] Base UProperty declaration fields, version flag masking, source-legal references and original loaded metadata accepted.
- [x] Do not reopen this base serializer for linked ElementSize (G3-15) or global physical EOF (G3-14).

### G3-08 — Scalar property subclasses, strings and UAny

**Gate status:** OPEN. **Evidence known:** 7 limited native subproofs: UAny null/zero and primitive types; nonzero refs/struct/NET/Button remain.

- [x] Reuse original loaded/null primitive values and existing legacy string source tests.
- [ ] Close source-defined Byte/Bool/Int/Float/Name scalar positive/negative values, dimensions, signs, endian widths and version variants.
- [ ] Close original UStringProperty versus UStrProperty string conversion/count/NUL/UTF branches where source-defined.
- [ ] Close distinct source-defined UAny NAME/OBJECT/STRUCT/NET and UButton caption variants, including nonzero loaded values or explicit original stock-native exclusion.
- [ ] Consolidated original-native/source comparison and one G3-08 decision.

### G3-09 — Object/class reference property declarations

**Gate status:** ACCEPTED. **Evidence known:** 222/222; original C98/T99/C227 class targets/null behavior.

- [x] Original UObjectProperty/UClassProperty serialized referents, MetaClass and source-specific null handling accepted; preserve sealed compiled proof.
- [x] Broad import redirection and entire-corpus missing referents remain under G3-14/G3-15, not a reason to repeat G3-09.

### G3-10 — UStructProperty nested members

**Gate status:** OPEN. **Evidence known:** Some nested layout evidence; full original source/native comparison missing.

- [ ] Verify local and imported struct layout, inherited ancestry, member order, offset and original SerializeBin on actual nested values.
- [ ] Verify nested structs/strings with array combinations and actual original native-loaded bytes for distinct source variants.
- [ ] Verify source-legal invalid nested member/count/reference/EOF, including documented nondeterministic stock allocator exclusions.
- [ ] Consolidated G3-10 decision.

### G3-11 — Array, fixed-array and map property serialization

**Gate status:** OPEN. **Evidence known:** Some source-specific inner/array evidence; no whole-method acceptance.

- [ ] Prove original UArrayProperty dynamic element count, inner type dispatch and zero/nonzero nested data.
- [ ] Prove UFixedArrayProperty element count, member size and source-selected presence/version gates.
- [ ] Prove UMapProperty supported/no-data behavior or source-defined exclusion per build; never invent successful native values.
- [ ] Prove count overflow, negative/invalid input and physical EOF owned by methods, then one G3-11 decision.

### G3-12 — Script bytecode serialization and fixups

**Gate status:** OPEN. **Evidence known:** v432 G5 bytecode evidence sealed; C98/C227 opcode branches and invalid cases pending.

- [ ] Inventory source-defined SerializeExpr opcodes, native bytecode relocation of UObject/FName, size differences from archived bytes and applicable version gates.
- [ ] Verify nested expressions, script-sized positive/negative cases and original loaded script identity in C98 and C227 without re-running sealed v432 G5.
- [ ] Classify the observed original 469f rejection of genuine C98 v61 script (Bad expr token 15) under compatibility/source opcode rules; do not present it as evidence about G3-04 function fields.
- [ ] One consolidated G3-12 decision with source-defined malformed script cursor and exclusions.

### G3-13 — Tagged property values and default/config effects

**Gate status:** OPEN. **Evidence known:** G5 tagged/default evidence accepted separately; C98/C227 behavior not fully reconciled.

- [ ] Verify original UObject tagged property stream/terminator, source name/type tags and SerializeTaggedProperties dispatch.
- [ ] Verify actual loaded nontrivial defaults and source-defined Config, Localized, Transient and inheritance/override behavior.
- [ ] Verify invalid tag/type/count/EOF and version branches without double-testing general FArchive errors; one G3-13 decision.

### G3-14 — Crosscutting version and malformed FArchive behavior

**Gate status:** OPEN. **Evidence known:** Original C98/469f/227k error branch and physical cursor evidence sealed, broader matrix open.

- [x] Reuse native C98 Got42/40, Got40/41, State Got62/63 and 469f/227k physical Tell/error-order cases; no reruns.
- [ ] One source-matrix review for all remaining distinct version gates and physical EOF/overrun/shortfall/invalid-count/reference errors across G3 method families.
- [ ] Only collect original compiled diagnostic/cursor evidence for a *named uncovered source branch*, not one negative test per file/object.
- [ ] Confirm malformed-input semantics against actual original and consolidate one G3-14 decision.

### G3-15 — Native loaded-value and genuine-file parity

**Gate status:** OPEN. **Evidence known:** Existing original C98/T99/C227 loaded-value samples substantial but not whole method/profile corpus.

- [x] Reuse all SHA-pinned genuine original Core/Engine loaded-value evidence already recorded by method owners, including direct G3-04 object and Save/Load proofs.
- [ ] Identify every still-unobserved required serializer/profile and either compare deterministically loaded values to original compiled runtime or justify explicit source-defined unreachability/exclusion.
- [ ] Complete genuine file/object/provider, linked field/offset/reference parity across C98/T99/C227 without substituting PHP self-simulation or re-counting object samples as separate tests.
- [ ] Consolidated G3-15 decision with zero unowned mandatory oracles.

### G3-16 — Independent one-time whole-G3 acceptance and release

**Gate status:** OPEN. **Evidence known:** Cannot execute before G3-01 through G3-15 are accepted.

- [ ] Require accepted, SHA-pinned final decisions for all G3-01 through G3-15; no mandatory BLOCKED/FAILED/UNKNOWN item and no unowned source branch.
- [ ] One independent aggregate acceptance run, not a replay of all sealed original native processes.
- [ ] Only after PASS: final code/doc commit, push main, selective DEV-to-PRD SHA verification; protect PRD config/storage.
- [ ] Record complete O06 final evidence and mark G3 accepted; otherwise release stays BLOCKED.

## Historical appendix (do not add tests here — S001+ goes to the bottom of the T001–T080 backlog)

**None approved or scheduled as of the baseline.** Do not convert old exploratory or rejected attempts into passed subtests. Every future addition is entered as the **next S-number at the bottom**, never interleaved into the baseline.

| ID | Parent gate | Why not covered by baseline | Epic source/compiled discriminator | Single acceptance outcome + evidence required | Priority / approval | Outcome |
|---|---|---|---|---|---|---|
| *(none)* | — | — | — | — | — | — |

## Per-gate decision record — update only when a decision is actually made

For every G3 gate, record `ACCEPTED / FAILED / BLOCKED`, original build/profile/version scope, source and compiled-native SHA paths, genuine input SHA, one decisive result, production change commit (if any), precise blocker (if not accepted), and the next gate. The existing `UE1-O06-TEST-EVENTS.jsonl` and `UE1-O06-ACTIVE.json` remain operational journals; their accumulated PASS counts **do not replace** the 16 gate decisions.

### Current authoritative stop point

- No running tests and no product changes authorized by this plan.
- **G3-04:** remaining original UT99 version 60–63 legacy `UFunction::Serialize` valid native loader demonstration; previous bad-script and invalid synthetic loader attempts are excluded. This is ONE outstanding decision criterion, not an invitation to add more arbitrary native samples.
- **G3-16:** BLOCKED by the outstanding gates; no full-release run.
- Final approval required before executing the fixed baseline or appended subtests.
