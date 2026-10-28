# UE1 O06 G3 — controlled master test register

**Baseline:** 2026-10-28 · **State:** PROPOSED / USER REVIEW — EXECUTION STOPPED · **Primary authority:** original Epic sources and actual compiled behavior, not UnrealDB-only tests.

This document is the **single controlled execution list** for G3. It supersedes the *workflow* in older expandable plan/status narratives, without deleting historical tests or changing accepted technical evidence. The 16 existing contracts are fixed, with 52 baseline acceptance conditions (14 supported by already accepted/subscope evidence; this subcondition count is **not** a test pass count). Exactly 5/16 gates are fully ACCEPTED; 11 remain OPEN. No test will be run merely because it is listed here until the user approves the baseline.

## Mandatory rules (G3 only)

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
| **G3-01** | Epic source lineage and version provenance | **ACCEPTED** | 87/87 accepted; original source chain, game overrides and negative wrong-provenance cases |
| **G3-02** | Complete Core serializer method and inheritance inventory | **ACCEPTED** | 1,318/1,318; 57 C227 symbols, 205 class-method declarations |
| **G3-03** | UField / UStruct serialization | **OPEN** | C98 100 real structs/96 script loads; C227i 237 structs/223 scripts sealed; T99 partial native accepted |
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

**Gate status:** ACCEPTED. **Evidence known:** 87/87 accepted; original source chain, game overrides and negative wrong-provenance cases.

- [x] Freeze source-selection/version/build provenance evidence across UE1, Unreal revisions and UT99 v432 (v400 inherited), explicit 469f/227i/k overlays.
- [x] Demonstrate incorrect game, revision and shared package-version selection fails rather than silently borrowing another game's serializer.

### G3-02 — Complete Core serializer method and inheritance inventory

**Gate status:** ACCEPTED. **Evidence known:** 1,318/1,318; 57 C227 symbols, 205 class-method declarations.

- [x] Reconcile all relevant Core class/property methods, inherited archives, source revisions and legitimate exclusions.
- [x] Pin original source/compiled symbols and assign each distinct archive behavior to exactly one downstream G3 owner.

### G3-03 — UField / UStruct serialization

**Gate status:** OPEN. **Evidence known:** C98 100 real structs/96 script loads; C227i 237 structs/223 scripts sealed; T99 partial native accepted.

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

## Additional subtests — append-only, BELOW the initial 16 gates

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
