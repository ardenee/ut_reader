# UE1 G3 — Individual executable test backlog (NOT the 16 acceptance gates)

**28 October 2026 · USER APPROVED THE 80 FIXED TESTS · EXECUTING 10 RESUMABLE BATCHES**

**Live results:** [80-test machine-readable status](UE1-O06-G3-EXECUTION-TEST-BACKLOG.json) and [10-batch progress](UE1-O06-G3-EXECUTION-PROGRESS.md). Original native parity is never assumed: BLOCKED means missing source-specific proof, not a pass.

These are the **individual pending tests**, each with a defined original engine/version, actual input or fixture, single operation, expected original-to-UnrealDB result and evidence requirement. The previous 16 `G3-XX` entries are *acceptance group headings only* and are NOT tests. This backlog replaces the earlier 52 broad acceptance statements as the work queue. The 5 sealed accepted gates G3-01, G3-02, G3-06, G3-07, G3-09 have **no tests to repeat**. Other previously passing original native cases are explicitly identified as REUSE, not to be rerun.

## Process rules

1. **Exactly 80 initial tests, `T001` through `T080`.** Work in row order; each has a permanent ID, parent G3 acceptance group, source build, concrete source package/fixture and single pass criterion. Do not generate new tests while executing one.
2. **Freeze after user review.** The baseline may be edited to correct factual errors *before* approval. After approval, edit ONLY status, evidence SHA/result and precise blocker. Any changed behavior needs an explicit change record; do not silently alter the test/pass criteria.
3. **Reuse first:** read the existing SHA-pinned original native evidence, G5 accepted original proof and original source. `PASS` may be recorded without a new engine execution if the exact asserted source/build/branch is already proven. Never rerun a previously accepted original CDB probe just to accumulate passing counts.
4. **A test is terminal only when `PASS`, `FAIL`, or source-proven `SOURCE_NA`.** A `BLOCKED` test records exactly what is missing, then work proceeds to the next T-number. Product defects are fixed only against that original failing test and verified once with affected existing regressions.
5. **Discovery queue: append-only `S001`, `S002`, ... at the VERY END**, with parent gate, source branch, reason not covered by any T-row, input, expected, evidence, and approval. These are processed **after the entire initial T-list has been attempted**, not interleaved. Mandatory surprises still block final G3 release until resolved; they do not reorder the baseline.
6. Do not run G3-16 release while any mandatory T or approved S case is unresolved. Original native source is master. No speculative test passing and no unproved equivalence across C98, stock UT99 v432, 469f, 2012 227i and 2026 227k.

## Fixed pending test counts

| Acceptance owner (not a test) | Actual individual tests |
|---|---:|
| G3-03 | 10 |
| G3-04 | 1 |
| G3-05 | 9 |
| G3-08 | 13 |
| G3-10 | 7 |
| G3-11 | 9 |
| G3-12 | 8 |
| G3-13 | 7 |
| G3-14 | 7 |
| G3-15 | 6 |
| G3-16 | 3 |
| **TOTAL planned tests** | **80** |

## Individual tests to finish

All rows begin **PENDING** because this is the user-requested planning baseline, not test execution. An existing sealed proof may satisfy a row at evidence review; that does NOT authorize repeating a native test.

### G3-03 — UField and UStruct direct serializer

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T001** | UT99 v432 | Original Core.u: direct Vector/Rotator/Color UStruct exports | Load direct UStruct records in original compiled v432 Core.dll; capture FriendlyName, Line, TextPos, ScriptText, ScriptSize and Child reference. | Each captured field and physical archive boundary matches source-selected UnrealDB; source memory/byte count is not mistaken for on-disk count. | Original v432 x86 CDB post-Serialize + PHP archive comparison |
| **T002** | UT99 v432 | Original Core.u: UField-derived objects with non-null Next/SuperField | Capture native resolved Next and SuperField export targets under the same original linker. | Both targets match stored compact references after original native resolution, or a source-defined null/unreachable exception is documented. | Native post-LOAD pointer-to-export-index + source mapping |
| **T003** | UT99 v432 | Original Core.u and Engine.u: one zero-script and one nonzero-script UStruct | Observe original native Script.Num, decoded byte buffer length, saved ScriptSize and archive Tell for both cases. | Decoder matches native length and deterministic bytes; relocated UObject/FName words masked by type, not declared equal. | One grouped original compiled observation; crosscheck pinned v432 G5 scripts |
| **T004** | UT99 469f | Core.u direct RegistryPropertyInfo/RegistryObjectInfo/PreferencesInfo exports 727/728/734 | Establish actual original 469f loader reachability; if loadable, capture their previously unobserved direct UStruct values. | Either source/compiled proven unreachability with explicit exclusion, or all observable direct loaded fields match UnrealDB. | Original compiled reachability + loaded-value comparison |
| **T005** | Unreal 227k | Original 227k Core.u direct UStruct exports | Capture real 2026 Core.dll direct UStruct post-LOAD names, line/textpos, script sizes and source references. | Each direct native field, zero/nonzero script and declared length matches 227k-selected decoder. | 2026 original Core.dll + original Core.u; one grouped native observation |
| **T006** | Unreal 227k | Original Core.u native UField Next/SuperField and UStruct Children/ScriptText | Dereference original loaded pointers against same-linker export table; compare original archived indices. | Every mandatory pointer identity matches reader; true runtime nulls reported separately from nonzero archived refs. | Original x86 pointer-to-export mapping; no PHP-synthesized pointer address |
| **T007** | Unreal C98 May17 | Original genuine Core.u Class Object and one non-function direct UStruct | Map original compiled Children, ScriptText and SuperField live pointers to real linker export indices. | Match source-defined null-after-load vs archived non-null and actual Children/Super target; no false claim of pointer materialization. | Previously recorded May17 Class Object CDB pointer observation + only missing pointer lookup |
| **T008** | C98 / v432 / 469f / 227k | Previously saved original G3-04 1058/322/310-byte function-state fixtures, plus original v432 SAVE | Inspect original compiled UStruct/UField inherited SAVE prefix and compare saved bytes/field order with source; do not regenerate or reload accepted files. | Native-writer bytes prove inherited SAVE for each distinct method implementation, or one exact missing-version proof is identified. | SHA-sealed original writer files + method disassembly; new native run only if no applicable writer proof |
| **T009** | C98 / v432 / 227k | Genuine UStruct payload mutated in one Children/SuperField reference, one invalid ScriptSize | Exercise the source-defined method-level rejection or null conversion, with declared physical cursor. | Exact error/loaded rejection and failing cursor agree with corresponding original compiled method; no guessed stricter rules. | Original version-specific error call and FArchive Tell for ONE named branch per actual distinct implementation |
| **T010** | C98 / v432 / 227k | Genuine script-bearing UStruct with archived compact UObject/FName tokens | Measure original UStruct saved ScriptSize versus reconstructed in-memory Script.Num at a zero and nonzero boundary. | Reader consumes exact archive bytes and matches loaded script length; never compares relocated addresses as literal archived bytes. | Reuse C98 96 and C227i 223 scripts; obtain only missing distinct v432/227k evidence |

**Existing proof to reuse before executing any native code:**
- T001: 469f nine-direct proof is reusable context, NOT v432 proof.
- T002: G3-07 base metadata does not cover live UField pointer identity.
- T003: Do not repeat G3-12 opcode coverage.
- T004: Nine other direct 469f UStructs are already sealed.
- T005: 2012 227i 237-object evidence sealed, not interchangeable with 227k.
- T006: C227i 232 Children pointers already sealed.
- T007: C98 first 100 derived function/script native results sealed.
- T008: Crosscheck original SAVE gates in G3-04 without re-executing.
- T009: G3-14 owns generic EOF/error matrix, G3-12 owns opcode token bodies.
- T010: Avoid repeating native script-byte proofs already accepted.

### G3-04 — UState and UFunction legacy version branch

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T011** | UT99 v432 (or compiled 469f equivalent) | A **genuinely loadable** version 60-63 UFunction with legacy archived ParmsSize/NumParms/ReturnValueOffset and valid outer/child graph | Load through authentic original UT99 compiled UFunction::Serialize <=63 branch; record archived triplet and after-link runtime triplet. | Original loader reaches target serializer without Bad expr token or constructor failure; legacy saved fields are consumed, resulting parameter triplet equals UnrealDB computed Children layout. | One original native loaded-value trace against valid file and source branch; no 69 repeat |

**Existing proof to reuse before executing any native code:**
- T011: Excluded C98 bytecode and top-level synthetic attempts cannot count.

### G3-05 — UClass and class default values

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T012** | C98 May17 | Original Core.u UClass exports including Object/Field/Struct | Capture original compiled UClass archived metadata, superclass/within and native postload flags. | Byte order, source-version branch and loaded class flags/refs match C98 reader for real exports. | Original compiled retail Core.dll, Class Object evidence reuse first |
| **T013** | UT99 v432 | Original Core.u and Engine.u UClass including Actor/HUD | Capture compiled UClass Serialize fields, ClassWithin, ClassFlags and inherited superclass identities. | Every source-applicable archived field and loaded scalar/reference matches v432 reader and valid class hierarchy. | Stock v432 Core.dll + existing G5 native UClass evidence |
| **T014** | UT99 469f | Original 469f Engine.u UClass and any 469f-specific class metadata delta | Compare native loaded UClass direct fields against v432 source-inherited baseline and compiled difference. | Any actual 469f-only UClass field/branch is represented; unchanged inherited fields proved equivalent without duplicate full loads. | Reuse 469f 88 UClass-inherited UState events; original UClass method disassembly for direct delta |
| **T015** | Unreal 227i and 227k | Authentic Core.u Class exports from each original shipped build | Compare original 2012 and 2026 UClass loaded fields and class/version gates. | Reader selects 227i vs 227k metadata correctly; any compiled difference is source-gated, no unproved inheritance. | Original shipped Core.dll + own Core.u, focused class field oracle |
| **T016** | C98 | One original class with inherited and overridden nontrivial default-property value | Load original native class defaults then compare inherited field value and saved source tag. | Loaded default value and super/override outcome match source-selected original, not merely static bytes. | May17 genuine Unreal Core/Engine original compiled class-default observation |
| **T017** | UT99 v432/469f | Actor-derived class with inherited and overridden nontrivial default property | Check original class-default construction and override in each distinct implementation. | UnrealDB resolved inherited/overridden value matches stock original; 469f delta either proven or excluded by identical compiled path. | G5 v432 default values reused; 469f original native delta only if needed |
| **T018** | C227i/227k | Original class default values for changed source build/field | Verify original loaded nonzero default and class layout for each genuine revision. | Reader applies correct 227i/227k class default/within/config and reports true unsupported branches. | Genuine 2012/2026 Core/Engine class defaults under compiled loader |
| **T019** | C98 / v432 / C227 | One Config, Localized and Transient class/default property per source-applicable revision | Compare saved tag and original loaded default or external INI/localization override under controlled original config. | Correct native loaded value, exclusion/precedence and source version for each behavior; absent source feature marked supported N/A. | Actual engine with isolated INI/profile fixture + source-defined rules |
| **T020** | C98 / v432 / C227 | One malformed UClass superclass/within/default reference per distinct source-defined error path | Load controlled corrupted class export and capture real native rejection/offset; ensure invalid class not shown as loaded. | Correct original rejection or source-permitted null, accurate method cursor and no invented defaults. | One genuine native error path per actual different compiled branch |

**Existing proof to reuse before executing any native code:**
- T012: G3-02 declaration inventory already accepted.
- T013: Only uncovered fields; do not rerun v432 G5.
- T014: Do not infer version69 means v432 equals 469f.
- T015: Do not reuse 227k as proof for different 2012 ABI.
- T016: Tagged serialization mechanics delegated G3-13.
- T017: No repeat of accepted v432 full UClass.
- T018: Do not conflate with G3-15 full-corpus coverage.
- T019: Generic tagged parsing G3-13, this test owns class-side effects.
- T020: General FArchive mismatch details only in G3-14.

### G3-08 — Scalar, string and special property classes

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T021** | C98 / v432 / C227 | Real byte and signed integer scalar property (0, positive, negative, max width) | Archive-load each source-applicable property through original native SerializeItem. | Exact signed/unsigned decoded values and 1/4-byte consumption match original; unchanged implementations may share one proven oracle. | Original compiled SerializeItem + genuine values or source-equivalent fixture |
| **T022** | C98 / v432 / C227 | Real float property with 0, negative and noninteger IEEE754 bytes | Compare native saved/loaded raw bit pattern and UnrealDB value, including -0 where representable. | Exactly four bytes consumed, sign/bit pattern preserved with no lossy source-unrequested normalization. | Native float load/save or original compiled operator + source-independent bit oracle |
| **T023** | C98 / v432 / C227 | Real bool property sharing a property flag word plus array index where supported | Verify source-defined bool mask, packed field, array index and original loaded booleans. | Both true/false and indexed bit selection match native declared field storage, not inferred byte bool. | Source selected Bool/Property SerializeItem + loaded field |
| **T024** | C98 / v432 / C227 | Real NAME scalar, None and one nonzero FName | Compare original compiled loaded global/name-table identity with serialized index. | Nonzero native name text/index resolves exactly; None remains genuine null sentinel. | Original compiled global FName lookup + UnrealDB name table |
| **T025** | C98 / v432 | UStringProperty fixed-length value at source-defined version conversion boundary | Verify actual old string class storage and source-legal conversion to dynamic StrProperty where applicable. | Exact array length, character termination and resulting runtime string match original compiled source-selected behavior. | Original original compiled package at both sides of source-defined conversion gate |
| **T026** | v432 / 469f / C227i/k | UStrProperty / FString with positive ANSI length including empty, 1-char, nonempty and NUL | Compare source archived count and native loaded string, physical consumed bytes. | Correct count, terminal NUL behavior, exact archive end and no unbounded read. | One native method oracle for each genuinely distinct source family |
| **T027** | C227i/k where source permits | UStrProperty / FString negative UTF16LE count and non-BMP/embedded nul source-defined cases | Compare stock original loader count and normalized loaded units for a legal UTF16 sample. | Matches original code unit count and archive cursor, without gratuitous UTF conversion. | Original x86 string operator source/compiled and native load |
| **T028** | C98 / v432 / C227 | A single malformed FString terminated incorrectly and one count exceeding physical archive | Observe source-specific original error, consumed cursor and rejection of loaded state. | Reader errors only where original does and reports same failing archive position. | Existing sealed invalid terminal/count probes reused; new native only if distinct branch missing |
| **T029** | Unreal 227k | UAnyProperty type5 NAME, one nonzero globally registered FName | Original native SerializeItem LOAD+SAVE a source-legal nonzero name. | Type5 tag, name identity and exact byte consumption match; no placeholder NAME_None. | 2026 Core.dll direct native archive + original FName table |
| **T030** | Unreal 227k | UAnyProperty type6 STRUCT with actual source-legal live UStruct value | Native LOAD+SAVE valid nonnull struct payload with fully constructed object and inner member. | Reader matches original 227k declared struct shape, count and real loaded member; mock allocator failure is NOT success. | Original fully initialized SDK object/Core.dll, no unsupported mock |
| **T031** | Unreal 227k | UAnyProperty type7 OBJECT with a nonnull valid linker-mapped UObject | Original native SerializeItem loads and saves a nonnull genuine object reference. | Correct object identity, tag7, saved object reference and cursor; null type7 proof reused. | Genuine Core.dll linker and object-backed archive |
| **T032** | UT99 469f vs C227k | One supported UAny/Pointer/Button NetSerializeItem and SerializeItem nonzero value in each differing original ABI | Verify branch-specific bytes/return code using original compiled methods, where class actually exists. | No cross-build guessed data: correct 469f pointer 4-byte vs 227k no-data behavior; new value cases only for unverified branches. | Reuse sealed 469f vs 227k method disassembly, new oracle only if value behavior differs |
| **T033** | Unreal 227k | Original ScriptedAI.u MoveCamButton/MovePointButton UButtonProperty Caption | Capture actual native loaded Caption text and source field after original class Serialize; verify archived ANSI length and result. | Original native strings exactly 'Move point to viewport' and 'Go to point', matching archive content; full class load must reach Caption. | 2026 genuine Core.dll runtime loaded field; reuse 2 package objects and compiled disassembly |

**Existing proof to reuse before executing any native code:**
- T021: Existing primitive/null tests reused.
- T022: Only distinct original methods, not one extra CDB per number.
- T023: G3-07 base property metadata accepted.
- T024: G3-06 enum FNames already sealed but scalar value different.
- T025: Reuse G5 v432 fixed-string fixture; no G5 rerun.
- T026: Old source string tests reuse first.
- T027: Do not assume every original game/build supports UTF16; source eligibility required.
- T028: Shared generic EOF error G3-14.
- T029: Previous type5 zero already sealed.
- T030: Previous null STRUCT6 failed due incomplete mock, not accepted.
- T031: Previous null OBJECT7 sealed.
- T032: Source absence explicitly supported N/A.
- T033: Do not repeat known source-only caption verifier.

### G3-10 — Nested UStructProperty member values

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T034** | C98 | One genuine local struct with two named scalar fields including inherited field | Use original SerializeBin; compare member order, sizes and real loaded values. | Exact member offsets, value bytes and inheritance match original compiled C98. | Original native loaded struct + declared source member graph |
| **T035** | v432 / 469f | One imported struct from Core.Struct (Vector/Rotator/Color) used by Engine class property | Load original native struct member values and verify imported identity plus member order. | Reader resolves real import and source-sized value exactly; 469f only differs where original compiled does. | Original compiled Engine.u+Core.u import chain with genuine class object |
| **T036** | C227i/k | One nested struct with linked outer/inner property and nonzero member value | Compare original SerializeBin load and loaded field offset against reader. | Exact 227-specific member offsets, array dimensions and loaded bytes, or source-proven absent class. | 2012/2026 native class/type plus genuine archive |
| **T037** | C98 / v432 / C227 | Source-legal nested FString inside struct, nonempty | Compare real native serialized count, loaded string and physical archive cursor. | UnrealDB matches original nested length and string value and fails closed on unsupported stock allocation. | G5 sealed original v432 nested source-legal success if applicable; new only missing variants |
| **T038** | C98 / v432 / C227 | Struct array containing two distinct nested members, zero and >0 count | Original native item load of array-of-struct with deterministic initialized memory. | Member order and both native loaded values match; any undefined uninitialized FString buffer excluded with source reason. | Original compiler fixture with initialized nested elements |
| **T039** | C98 / v432 / C227 | Struct inherited member offsets vs flat direct struct | Load original native inherited struct and compare declared offsets and physical member payload. | Reader does not collapse inherited member layout, actual offsets and values match original. | Original linked ancestry + native SerializeBin for one member |
| **T040** | C98 / v432 / C227 | Malformed nested member ref or truncated nested value | Invoke source-defined original error for one invalid member reference and one truncated legal struct value. | Same native failure/method cursor and no false loaded nested field; exclude stock undefined behavior. | Original compiled malformed input + current decoder |

**Existing proof to reuse before executing any native code:**
- T034: G3-03 struct declarations not value storage.
- T035: No inferred struct size from name alone.
- T036: 227i and 227k method deltas separately handled.
- T037: Known uninitialized allocator crash excluded, never counted pass.
- T038: G3-11 owns outer array count, here owns struct member codec.
- T039: G3-03 Children targets reused.
- T040: G3-14 crosscutting EOF template owns shared cursor implementation.

### G3-11 — Arrays, fixed arrays and maps

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T041** | C98 / v432 / C227 | UArrayProperty of integer inner: zero count and 2 distinct values | LOAD and SAVE under original native array operator. | Count, element order, exact bytes and loaded values match; no duplicate tests for same inherited code. | Original genuine file or source-shaped array fixture and compiled native operator |
| **T042** | C98 / v432 / C227 | UArrayProperty of UObject/Name inner: null and nonnull refs | Compare original item count and actual loaded link identity for both elements. | Every nonnull reference target matches native linker, null stays null; exact read count. | Original native linker fixture and source-selected inner type |
| **T043** | C98 / v432 / C227 | UArrayProperty of nested UStruct with nonzero members | Check outer count and member traversal on actual fully initialized original runtime. | Two native values and serialized footprint match, with true stock allocator failures excluded explicitly. | Original compiled native array/struct fixture |
| **T044** | C98 / v432 / C227 | UArrayProperty of string with source-legal empty/nonempty payload | Compare native repeated per-element FString sizes, final array cursor and loaded strings. | Matches source versions without rewriting absent or uninitialized strings. | Original compiled initialized string array fixture |
| **T045** | C227i/k | UFixedArrayProperty declared length 2 with nonzero primitive values | Original native SerializeItem/Serialize of fixed array under source-selected C227. | Exactly two values of source ElementSize decoded; no dynamic count or extra bytes invented. | Original C227 native Core method and original declaration |
| **T046** | C227i/k | UFixedArrayProperty nested/struct or Name array variant | Verify original fixed-count iteration, offset and source type size. | Serialized bytes and native loaded array values equal source-specific fixed semantics. | Original C227 compiler fixture with nested inner property |
| **T047** | C227i/k | UMapProperty with no-data/empty map and one source-legal nonempty map where supported | Observe original compiled map SerializeItem class behavior and record source-defined unsupported branch if no nonempty storage. | Reader consumes exactly original no-data bytes and never invents entries; nonempty case either proven or source-excluded. | Original 227 compiled method disassembly/real archive |
| **T048** | C98 / v432 / C227 | Malformed array count (negative/overflow) or truncated final element | Feed one genuine legal array then one controlled count mutation through actual original archive. | Same method-specific error and failing cursor; bounded decoder must not fabricate elements. | Original compiled actual FArchive failure at named branch |
| **T049** | C227i/k | UArrayProperty::ShouldSerializeItem and NetSerializeItem conditional branch | Use one source-valid value suppressed by ShouldSerializeItem and one serialized value, where implemented. | Original returned boolean/serialized bytes match reader source gate; unsupported net branch explicitly excluded. | Original C227 Core symbol method and value fixture |

**Existing proof to reuse before executing any native code:**
- T041: Existing 2012 four array-inner oracle reused if suitable.
- T042: G3-09 declaration refs already closed; this is array values.
- T043: G3-10 owns per-member content; this checks iteration/count.
- T044: Reuse G5 stable nested string proof.
- T045: Source absence in C98/T99 explicitly N/A.
- T046: Only where class source defines supported inner.
- T047: Existing map no-data script reused.
- T048: G3-14 owns crosscutting low-level Count/EOF.
- T049: G3-02 confirms method exists, not behavior.

### G3-12 — Bytecode expression archive and fixups

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T050** | C98 | Original Core.u script with FName token and UObject token, nonzero | Run original compiled SerializeExpr and capture loaded script buffer typed relocation positions. | Known byte positions match; relocatable Name/Object words compare by resolved target not literal ASLR address. | Reuse 96 true original C98 script memory buffers; cover only untested token types |
| **T051** | UT99 v432 | Original Core.u and Engine.u script containing one control-flow branch and one nested expression | Use sealed G5 native bytecode parser proof to map source-defined opcodes and memory size. | Existing original native source proof covers branch+size or precise missing opcode identified without replay. | G5 original v432 script evidence and source opcode declaration |
| **T052** | UT99 469f | Original Core.u bytecode with one source-defined 469f-only opcode variation if any | Diff original compiled SerializeExpr behavior against v432 code and genuine script corpus. | Every real delta implemented/covered; byte-for-byte identity proof accepted if truly no delta. | 469f native source/REA + genuine loaded bytes |
| **T053** | C227i/k | Original source script with Object/Name relocations and one struct/default expression | Capture actual loaded 2012/2026 native script memory for uncovered opcode families. | Typed relocation targets and actual compiled in-memory bytes match current reader; size/offset match source. | C227i sealed 223 buffer proof reused, 227k native delta only |
| **T054** | C98 / v432 / C227 | One zero-script and one long (>one compact-index byte) script | Compare archived compact script footprint, ScriptSize and native decoded memory length. | Exactly source-defined physical byte cursor and in-memory Script.Num, including compact-reference widening. | Original script-bearing package and compiled loader |
| **T055** | C98 / v432 / C227 | One invalid expression token at exact source-defined switch/default branch | Compare original compiled token failure and UnrealDB diagnostic at same native Tell. | Reject only original-invalid token and report exact consumed cursor. | G3-03 previous 469f malformed NAME_None is different branch; v61 Bad expr 15 exploration excluded as a G3-04 proof. |
| **T056** | C98-to-469f legacy overlap | Original C98 v61 bytecode token15 that failed when loaded through 469f | Classify whether error is intentionally unsupported old script version, invalid token, or wrong source family. | Source-defined behavior documented and UnrealDB emits matching compatibility/error status, never claims valid 469f Function load. | Recorded original 469f Bad expr token15 trace, no rerun |
| **T057** | C98 / v432 / C227 | Nested EX_* expression containing multiple compact object/name references | Compare original recursive token dispatch, count and actual loaded binary memory bytes. | Reader matches typed recursion, consumes exact bytes, and handles unknown reference without invented values. | Source opcode table plus original compiled memory values |

**Existing proof to reuse before executing any native code:**
- T050: No blanket rerun of 100 scripts.
- T051: v432 accepted G5 not entire G3.
- T052: No invented 469f-only opcode.
- T053: Unrelocated known bytes not double-tested.
- T054: G3-03 checks prefix fields; G3-12 owns opcode expansion.
- T055: No repeat of earlier rejected synthetic cross-era load.
- T056: G3-04 old param-field branch remains distinct.
- T057: Only original source-legal cases.

### G3-13 — Tagged properties, defaults and configuration

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T058** | C98 | Original UObject with one nontrivial named property, one None terminator | Native LOAD of tagged values and compare type/name/index, count and cursor. | UnrealDB stops on actual NAME_None terminator, returns original loaded value and exact bytes. | G5 original UObject saved fields reused where profile same |
| **T059** | UT99 v432 / 469f | One original actor default with mixed Bool/Int/Name tagged fields | Compare tagged stream and loaded non-default values against original engine. | Original tag order, type alias, payload and last cursor match; 469f only separately where source delta. | G5 v432 original default corpus reused; 469f delta via Core/Engine |
| **T060** | C227i/k | A source-valid C227 tagged default with actual nonzero value | Compare original compiled tag parser and source version/category name variants. | Correct 227 selected tag codec; genuine loaded value matches; no tag-family shortcuts. | 2012/2026 original Core/Engine native defaults |
| **T061** | C98 / v432 / C227 | Property absent from saved stream with original class default present | Observe native default-then-tag overlay when tag is missing. | Reader preserves inherited/default field according to original, not inventing serialized zero. | Actual native loaded class and empty/partial tag stream |
| **T062** | C98 / v432 / C227 | Config and Localized property with controlled isolated INI/text localization input | Load same saved package twice with two source-legal configurations. | Only original source permits override; resulting runtime value/priority equal original compiled engine. | Original engine isolated config profile and native loaded value |
| **T063** | C98 / v432 / C227 | Transient property present in default object but excluded or reset on SAVE | Original native SAVE and independent LOAD of controlled object. | Correct transient omission/reset and source-defined archived field count. | Genuine original SavePackage fixture per distinct method and class |
| **T064** | C98 / v432 / C227 | Malformed tag type, impossible array index or missing terminator | Test one source-defined corrupt tag per distinct error branch. | Original native failure/recovery and exact failing archive offset match; no fabricated defaults. | Original compiled tagged-property error + UnrealDB |

**Existing proof to reuse before executing any native code:**
- T058: Do not repeat sealed 1998 positive already covering this exact case.
- T059: G3-05 owns construction and superclass overlay.
- T060: G3-08 handles leaf numeric/string codecs.
- T061: G3-05 owns class default initialization; here owns missing tag effect.
- T062: Do not change canonical source or production config.
- T063: G3-05 owns declared flag presence.
- T064: Shared FArchive physical EOF G3-14 separately.

### G3-14 — Crosscutting malformed and version boundaries

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T065** | C98 / v432 / C227 | Version boundary matrix for each serializer method actually changing bytes (e.g. <=63 UFunction legacy branch) | Enumerate original source-gated ranges and test lowest/highest applicable version with same source family. | Exact branch-selected archive fields and cursor; same numeric v69 from different families must not substitute source. | Source-version manifest + original compiled representative evidence |
| **T066** | C98 / v432 / C227 | End-of-file truncated immediately before field read vs mid-field | Original FArchive attempted read and physical Tell for one shared underlying operator. | Reader throws at original moment with identical cursor and source native error class. | Original compiled FArchive native diagnostic trace or sealed equivalent |
| **T067** | C98 / 469f / 227k | Export declared length shorter/longer than method bytes | Reuse sealed original C98 Function Got42/40 and Got40/41 and State Got62/63; 469f Tell235 and 227k Tell221. | A single cross-engine check confirms BEFORE/AFTER read guards, false loaded values suppressed. | Cross-compare sealed original x86 raw CDB, no new original native execution |
| **T068** | C98 / v432 / C227 | Negative/huge compact count for array/name/string where source defines bounds | Compare source conversion, native error/recovery and physical Tell for each distinct operator implementation. | Same source-valid negative handling and same source-invalid error; no arbitrary extra policy. | Existing original negative test references + uncovered native operator branch only |
| **T069** | C98 / v432 / C227 | Invalid FName index and object import/export index from same original archive | Compare original compiled name/ref resolution errors and reader provenance. | Same null/throw rule and failing archive cursor; no misleading resolved name. | Original native lookup branch + real source package indexed maps |
| **T070** | C98 / v432 / C227 | Unexpected extra bytes vs physical package EOF after nominal export boundary | Test one stock original file with physically readable extension and one true EOF case. | Reader honors original FArchive physical read and serialization size check in correct source order. | Source-defined original Preload/linker trace and reader archive cursor |
| **T071** | ALL | One consolidated cross-method malformed/version ownership matrix | Cross-reference every G3-03..13 source-defined error and version gate to one concrete test ID/evidence SHA. | No unowned mandatory error branch; explicit excluded native undefined behavior and no duplicate test case. | Read-only source/matrix reconciliation plus one native trace per still-unproven distinct branch |

**Existing proof to reuse before executing any native code:**
- T065: Reuse G3-04 single pending <=63 native test, not clone it.
- T066: No one test per object or per package file.
- T067: Do not create another malformed size fixture.
- T068: G3-08 and G3-11 own leaf decoders.
- T069: G3-03 NAME_None assertion sealed for 469f.
- T070: Use only actual distinct implementation, not duplicate native per profile.
- T071: The register itself is the fixed input list; newly discovered beyond scope go appendix.

### G3-15 — Original compiled aggregate loaded-value parity

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T072** | C98 | Original genuine retail Core.u/Engine.u all source-required direct class/struct/property types | Enumerate methods actually reached by original compiled loader and match type-correct loaded values to reader. | 100% of required observable fields matched or each unobservable direct type has an explicit documented exclusion. | Reuse C98 direct loaded native registers and G3-03..13 closure proofs; compare only missing types |
| **T073** | UT99 v432 / 469f | Original Core.u and Engine.u native loaded objects by distinct serializer/release | Union existing 650 v432 function/state and 469f 1422 Engine plus other class proofs, identify genuine missing method classes. | Zero unexplained source-applicable method/type gaps and no false 469f-v432 equivalence. | Aggregate existing sealed original native snapshots and owner gate evidence |
| **T074** | C227i / C227k | Original 2012 Core.u 237 structs and 2026 Core/Engine method-loaded values | Reconcile all required C227 types, own actual native loaded references and script flags. | Zero missing mandatory C227 method oracle; 227i and 227k build deltas explicitly separated. | Reuse original 237 native and 227k saved fixture; only uncovered fields target native |
| **T075** | ALL | Linked element sizes and object/reference identity vs PHP stored archive refs | Compare actual compiled layout outputs for imported struct, property chains and runtime materialized refs. | No value presented as actual runtime pointer unless native observed; exact source-backed sizes and identities. | Original per-build native linker reference index mapping |
| **T076** | ALL | Genuine-file corpus selection including duplicates/import providers | Compare one distinct provider-resolution/import case per documented game/build and verify reconstructed package names. | Reader source-selected provider precedence and references equal original native loaded values; missing provider reported accurately. | Actual shipped Core/Engine and documented dependency cases; owner gates sealed |
| **T077** | ALL | Final deduplicated evidence matrix across every method and version profile | Assign one SHA-verified native acceptance record or explicit exclusion to every source-required behavior. | Every original method gate has verified owner/oracle and zero unexplained parity mismatches. | Read-only evidence join from G3-01..14, not a new test suite |

**Existing proof to reuse before executing any native code:**
- T072: Not a new full CDB replay.
- T073: Avoid proof-count inflation per export.
- T074: Do not equate distinct compiled versions.
- T075: G3-03 child refs and G3-04 param chain feed this.
- T076: Do not rescan 235k files without a named gap.
- T077: Any failure gives concrete owner gate and stops final G3-16.

### G3-16 — Final controlled release and one-time acceptance

| ID | Original build | Concrete input | Test operation | **PASS exactly when** | Required oracle |
|---|---|---|---|---|---|
| **T078** | ALL | Final frozen T001...T080 test register and all G3-01..15 decisions | Verify every mandatory test row terminal PASS or source-justified N/A, with approved append-only S-subtests resolved; check no BLOCKED/FAIL remains. | One exact final release decision; no historical PASS counts mistaken for full closure. | Deterministic static register/status SHA and proof linkage |
| **T079** | ALL | Independent whole-G3 Epic parity acceptance run | Run ONE final combined gate covering complete source/version, actual original loaded-value method family and malformed evidence. | All mandatory assert counts PASS, no source-branch gaps, no native test replay merely to farm passes. | Single independent G3 verifier composed of accepted immutable proof artifacts |
| **T080** | DEV → GitHub → PRD | Final release file manifest, product code and protected production environment | Commit/push final source/documentation and selectively deploy; SHA compare every changed file excluding config/storage. | GitHub main and DEV clean, PRD changed files identical SHA, no protected config/storage copied. | Git and SHA256 PRD deployment log + final project status |

**Existing proof to reuse before executing any native code:**
- T078: Blocked while any required T row is pending.
- T079: Execute only after T001... backlog terminal.
- T080: No PRD deployment until one-time release gate PASS.

## Execution/result fields (machine-readable register)

The companion [JSON backlog](UE1-O06-G3-EXECUTION-TEST-BACKLOG.json) holds: `id`, `gate`, `profiles`, `fixture`, `procedure`, `pass_criterion`, `evidence`, `reuse`, `status`, `evidence_sha`, `result`, `blocking_reason`. It is the writeable status ledger; Markdown is the readable frozen specification. Every result must cite an original source/engine SHA and a deterministic comparison, or a precise documented source-defined nonapplicable branch.

## Discovered extra tests — append to the END only

**NONE**. Any test discovered during baseline execution goes here as S001, S002, ... after the T-list, with an explicit parent and reason. Do not execute until the baseline has been attempted.

| Extra ID | Parent G3 gate | Why no T-row covers it | Source-discriminating original behavior | Input and PASS criteria | Approval | Status |
|---|---|---|---|---|---|---|
| *(none)* | — | — | — | — | — | — |
