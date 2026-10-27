# UnrealDB source precedence: engine first, game differences second

**Binding rule for all Unreal Engine generations (UE1–UE5).** The engine's actual selected C++ serialization code is the base contract. A game's build-selected source may add, override or remove behavior **only at the corresponding source operation**. Neither the game's name nor the engine version may substitute for the other's identity.

1. **Engine baseline.** Select the actual engine generation, branch and revision (UE1; UE2/2.5; UE3; UE4; UE5). Derive package headers, archive primitives, class inheritance, version gates, linker references, and serializers from the relevant build-selected Epic source. Later generations do not inherit UE1 disk codecs unless their own source explicitly says so.
2. **Game/load context.** Identify a game/fork and the loader's revision independently, from a proven game-source header/signature, configured known game context, or explicit caller selection. A package may have been written by one engine revision and opened by a later compatible game loader. The archive's file version alone never identifies a game.
3. **Compose source behavior.** Run the inherited engine serializer first. Overlay exactly the game's changed methods, fields, predicates and post-load actions. When the game source calls `Super::Serialize(Ar)`, its parent archive must still run. Only `Ar.IsLoading()` / `Ar.IsSaving()` branches produce bytes; `Ar.SerializeRefs()`, garbage-collector walking, memory reallocations, and runtime effects must be kept distinct.
4. **Unresolved provenance.** Do not use a different game's reader because its layout happens to parse or its version number matches. Do not silently fall back to UT99 or any other game. If no source-proven loader context determines a later revision's serialization, report that source profile as unresolved and require caller-provided provenance. Do not present speculative fields as decoded truth.
5. **Acceptance separation.** `MAPPED` means the source-backed file read implementation is present. Independently verified loaded values, native runtime behavior, genuine-corpus comparison, writes and deployment have separate acceptance requirements. Never promote an unimplemented serializer to MAPPED because its source location is documented.

## UE1 implementation

Epic source baseline: `L:\Source\Engine\UE1\Source` and pinned Epic engine revisions for historical package versions. Unreal/Unreal Gold, UT99 and licensee forks are separate **game/engine-fork overlays**, not aliases.

- **Unreal v1.227:** game source `L:\Source\Games\Unreal\Unreal [v1.227]`; confirmed version **69** and licensee version **227**. This combination identifies the Unreal v1.227 loader; it never selects UT99. Its emitter C++ overrides use UnrealScript archive class identities `XBeamEmitter`, `XParticleEmitter`, `XRopeDeco` (C++ `AXBeamEmitter`, etc.). On load, the inherited UE1 tagged-property stream is decoded before those game methods. The available v1.227 tree does **not** include complete replacement `Core/Src`; unoverridden UE1/Unreal core methods remain inherited across all chronological Unreal game-source revisions, never borrowed from UT99.
- **UT99 v432 (canonical):** select `ut99-v432` using established UT99 loader provenance. The v400 Core/Engine C++ Serialize implementations are the **inherited source baseline**; the public v432 headers and compiled libraries supersede v400 where different. Do not interpret a shared package version (including 69) as game identity. The CLI accepts the former `ut99-v400` spelling only as a deprecated alias mapping to v432.
- **Earlier Unreal:** UE1 engine source defines the common legacy archive. May and July 1997 original sources add source-specific serializers; they can share package version 23 and therefore require their own source selection. Historic runtime/load effects are tracked separately.
- **Source selection:** `CatalogUE1ClassicSourceFamily::selectFromHeader` respects the documented Unreal v1.227 version/licensee signature. `select` does not infer UT99 from later package versions. Top-level tagged, class, native and CLI readers select once and propagate the source profile into nested field, inherited class, array, struct and bytecode readers. `CatalogUE1ClassicGameSourceOverlay` applies the three Unreal v1.227 actor overrides after their inherited tagged archive.

## UE1 source-profile version eligibility (implementation 2026-10-23)

The pinned game sources declare inclusive minimum and maximum **complete-backward-compatibility** versions from Core/Inc/UnObjVer.h:

| Game loader source profile | PACKAGE_MIN_VERSION | PACKAGE_FILE_VERSION | UnrealDB source ID |
|---|---:|---:|---|
| Unreal v1.200 | 34 | 61 | epic-ue1 |
| Unreal Tournament v432 public source (ENGINE_VERSION 430) | 60 | 69 | ut99-v432 |
| Unreal v1.227 | 60 | 69 | unreal-v227 |

CatalogUE1ClassicSourceFamily::GAME_VERSION_GATES and eligibleProfiles($fileVersion) provide source-defined **priority and declared compatibility** for candidate selection. A known loader chosen explicitly may attempt an out-of-range package: the actual Epic loader below-minimum check prompts for confirmation and does not unconditionally reject. The reader preserves that source selection and exposes loaderVersionDiagnostic rather than claiming the file will load.

- v34–59: Unreal v1.200 source eligible.
- v60–61: all three sources eligible, not auto-identified.
- v62-69: UT99 v432 and Unreal v1.227 eligible, not auto-identified.
- v69: UT99 v432 and Unreal v1.227 are BOTH candidate loaders. The 69/227 header pair is positive evidence of the Unreal v1.227 source, whereas 69/licensee 0 alone is not universal proof of UT99.
- Outside these ranges: none of these source profiles claims complete compatibility. Earlier original UE1 v22–23 sources are handled separately; other game branches need their own source-backed rules.

**Crucial source distinction:** The actual ULinkerLoad::LoadSummary check in both Unreal v1.200 and UT99 v400 *prompts* on files older than PACKAGE_MIN_VERSION and may continue. The eligibility gates deliberately reflect complete-compatibility support, **not an assertion that the engine refuses every out-of-range file**. A newer file is not rejected by that particular minimum-version check, either, but may fail in subsequent parsing or linking.

The CLI reports all preferred in-range profiles, plus a source-defined warning diagnostic for an explicitly selected out-of-range profile, before selecting. If multiple sources are eligible and no loader provenance is provided, it reports ambiguity rather than silently interpreting a file using the wrong game. A future candidate probe must compare complete structural outcomes and surface ambiguity when multiple sources succeed; successful tagged-property parsing alone does not identify a game. Game source is layered over the Epic UE1 engine serializer, never substituted for it.

### Exact native loader semantics and fixed-string UClass defaults (2026-10-23)

The inspected pinned Unreal v1.200 and UT99 v400 Core/Src/UnLinker.h checks Summary.FileVersion below PACKAGE_MIN_VERSION, then uses GWarn->YesNof(OldVersion) and aborts only when the user answers no. It does not check FileVersion above PACKAGE_FILE_VERSION at that point. UnrealDB no longer treats either source constant as a hard exclusion when an explicit loader profile is supplied. A selected loader above its own saver version is a speculative attempted load (not proof of successful compatibility). The version 69/227 Unreal header identity check remains independent. The partial v1.227 source tree lacks the relevant linker implementation, so no v1.227 confirmation prompt is invented. sourceLayers and CLI expose the distinction.

Epic Unreal v1.200 Core/Inc/UnType.h UStringProperty::SerializeItem calls FArchive::String(Value,StringSize) from Core/Src/UnFile.cpp. This reads one byte at a time, stopping at an on-disk NUL or at StringSize-1 bytes, then writes a NUL in memory without consuming an additional file byte. CatalogUE1ClassicStructArrayValueReader now implements this exact bounded original-engine string operation for nested UClass default structs and struct-array fields; it does not mistake fixed CHAR arrays for UT99 FString archives. These are code changes, not native-runtime G5 acceptance.

### UT99 v432 supersedes v400; inherited implementation and delta audit

The single target UT99 loader is `ut99-v432`, not two separate profiles. Public source folder `L:\Source\Games\UT99\Unreal Tournament v432` defines `ENGINE_VERSION=430`, `PACKAGE_FILE_VERSION=69`, `PACKAGE_MIN_VERSION=60` and licensee default 0 in `Core/Inc/UnObjVer.h`. The folder label v432 and compiled source macro 430 differ; do not silently call the latter 432. UT99 v400 source has package version 68 and provides the older complete Core/Engine native serializer method bodies. The public v432 tree omits Core/Src and Engine/Src; it has precompiled Core.lib/Engine.lib and declaration headers. **Absent v432 replacement implementation source INHERITS the v400 method by chronological game-source precedence. Actual changed headers/new classes remain differences to investigate. Compiled byte-for-byte identity is not required merely to inherit a missing later method file.**

Machine-readable source delta: `catalog/compliance/UE1-UT99-V432-SOURCE-DELTA.json`. The v432 release has 28 changed and 6 added Core headers, and 37 changed and 11 added Engine headers compared with v400. The v432 `Engine/Inc/UnModel.h` inline Serialize methods for UBspNodes, UBspSurfs, UVectors and UVerts provide **direct v432 source evidence** for their database count and record archive operations. The v432 `Engine/Inc/UnAnim.h` retains FMeshAnimSeq/FMeshAnimNotify archive operators moved from v400 UnMesh.h; the wire order remains the same. The v432 `Engine/Inc/UnMesh.h` additionally declares a 12-byte FMeshExtWedge (two WORD plus two FLOAT) archive record; the typed record decoder exists in CatalogUE1ClassicV432NativeDeltaReader.

New v432 `USkeletalMesh::Serialize` and `UAnimation::Serialize` are **registered PARTIAL source methods**, not falsely MAPPED: no v432 public Core/Engine native method bodies specify their native archive order. Read-side inspection preserves bounded raw native bytes and SHA-256 and explicitly reports source-order uncertainty. v432 `UFont` adds `CharRemap/IsRemapped` and `UStruct` adds `PropertiesAlign`; neither header alone proves extra persisted fields. These require direct compiled oracle or recovered licensed exact source rather than guesswork. Existing source-mapped UT99 methods retain their v400 inherited baseline while v432 native-method equivalence is marked pending; **G5 is not accepted**.

## Future UE2 / UE2.5 / UE3 / UE4 / UE5 work

For every code review or new reader, keep a `base_engine_source`, `selected_engine_revision`, `game_loader_source` and `override_source` conceptually separate. Determine version and licensee gates from the specific code branch. Never apply the behavior of Unreal II to UT2003, UT2004 to UE2.5, UT3 to generic UE3, or a custom UE4/UE5 game fork to a generic engine file without a verified inheritance/override relation. The same applies to cooked package loaders, object index resolution, script data, compression, redirects and dependency/linker fixups.

This document is an **architecture requirement** for all versions, not a claim that every UE2–UE5 call site has already been refactored or independently verified.


## Cumulative Unreal revision source rule (2026-10-27)

Source order: v0.82 -> v0.83 -> v0.84a -> v0.86x -> v0.867 -> v1.200 -> v1.224 -> v1.227. Begin from Epic UE1 engine source; apply game changes chronologically. Changes introduced at v0.84a, for example, persist in subsequent Unreal revisions until an actual later source change overrides or removes them. Missing code is NOT a changed implementation. Resolution is per class and per method/field/archive behavior, not wholesale replacement of the baseline when a source snapshot is incomplete. A changed declaration, inline function, introduced class, or version gate is present source evidence of a change and must be handled.

Keep the three original serialized archive generations separate: 1996 resource, 1997 Unrealfile and classic post-1998 package. A later rewritten serializer represents a format boundary; do not carry incompatible legacy wire layouts forward just because the class name matches.

The newest present classic Core/Src/UnClass.cpp and Core/Src/UnProp.cpp among the eight Unreal game sources are from v1.200. v1.224 and v1.227 lack replacement implementation files but supply changed Core/Inc headers. The source model therefore inherits v1.200 classic method implementations into v227 and applies all newer *actual* game source changes. It does not borrow UT99 Core; UT99 v432 similarly inherits its v400 game Core implementation where later source is absent. Source body inheritance is distinct from proving byte-for-byte binary equivalence, retained as a separate method/native acceptance responsibility.

Original source chain SHA manifest: catalog/compliance/UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json. Single source-selection acceptance: catalog/bin/verify-ue1-o06-g3-01-chronological-source-inheritance.php. A mismatched 2012 Core.dll and later Core.lib is not a reason to block source inheritance or all G3 contracts.


### G3-02 v227 newly introduced property-source delta (2026-10-27)

Unreal v1.227 Core/Inc/UnType.h introduces UPointerProperty and UAnyProperty with no replacement Serialize(FArchive&), so their original UField/UProperty base declaration archive is inherited. Their GetID aliases are NAME_IntProperty and NAME_VectorProperty; actual runtime SerializeItem semantics still differ and are not certified. CatalogUE1ClassicPropertyNativeReader supports their source-specific on-disk declarations for unreal-v227 only.

UButtonProperty explicitly declares a new Serialize(FArchive&) override in the v227 header; missing the CPP is not proof of no change. CatalogUE1ClassicNativeObjectReader reports game_defined_buttonproperty_serialize_override_unverified rather than accepting inherited completion. New source-based v69/licensee227 package fixtures with wrong-game and trailing native byte negatives: 20/20 DEV PASS. G3-02 remains OPEN for other changed method/field behaviors.


### G3-02 original complete symbol inventory acceptance (2026-10-27)

Original source method register: 57/57 genuine later v227 Core.lib exported serializer symbols, all original v227 declaring class methods and owner G3 behavior contracts mapped, and 205/205 distinct original source class-method declarations across Unreal v1.200/v1.224/v1.227 and UT99 v400/v432. Independent original pinned SHA method register 1318/1318 checks PASS. The previous Oct26 C227 source inventory saying UButtonProperty has no Serialize override was incorrect; the true v227 header explicitly declares it. PointerProperty and AnyProperty do not override Serialize, so inherit base archive semantics. v432 static constructors and class registration changes must not invent new serialized fields. G3-02 closes source symbol/inheritance/exclusion coverage only. Actual method serialization error/version behavior remains G3-03 through G3-15.


### Matching OldUnreal 227k compiled export archive provenance (2026-10-27)

Real OldUnreal v227k_15 SDK x86 Core.lib and matched shipped x86 Core.dll have all 57/57 original C227 serializer method decorated exports. The older 2012 227i runtime had only 43/57 and must not be used for later method provenance.

REA/Ghidra compiled FArchive << FObjectExport at 0x1005DA20 proves licensee version>=227 with object flag RF_HasArchtype (0x100) reads a signed 32-bit ArchetypeIndex immediately after ObjectFlags, before compact SerialSize/SerialOffset. Old licensees do not read the field; UT99 keeps its separate source profile. Genuine original 227k UnrealShare.u export13627 BoxRigidBody holds signed reference+13069, all 13,873 original exports now decode and seven real packages validate. Bad reference mutation is rejected in an isolated source copy; source archives remain unchanged. Compiled UButtonProperty::Serialize at 0x10080010 archives inherited UProperty and FString Caption member+0x8C; actual loaded-value behavior is a separate G3-08 owner. SHA manifest at catalog/compliance/UE1-O06-G3-C227K-MATCHED-SDK-REA-AND-ARCHETYPE.json and focused verifier catalog/bin/verify-ue1-o06-g3-c227k-original-archetype-and-matched-sdk.php (180/180 DEV checks).


### UT99 469f source provenance over v432 (2026-10-27)

Real OldUnreal 469f Windows SDK and actual x86 Windows runtime have 53/53 matching Core serializer-related decorated symbols. Changed original 469f Core/Inc/UnType.h defines UPointerProperty, absent in historical v432 header; it does NOT override Serialize, so inherits UE1 UField/UProperty on-disk archive. Real 469f Core.u/Engine.u/Editor.u contain 2/12/7 = 21 authentic new UPointerProperty declarations, all read correctly under explicit ut99-v469f runtime source overlay. Prior v432 source still rejects new pointer classes, so no version-only change to legacy reader occurs.

Original v469f and v432 both have package version69/licensee0: the caller must establish actual 469f runtime provenance before selecting the explicit overlay. The codec inherits known UT99 v432 methods except proven later game deltas. Native REA/Ghidra decompiled real compiled 469f UStruct::Serialize at x86 0x1004a880, including script mismatch error path; no loaded UStruct behavior acceptance claimed. Original SDK, DLL, genuinely shipped packages, source and REA hashes are in catalog/compliance/UE1-O06-G3-T99-469F-MATCHED-SDK-POINTER-REA.json, focused one-shot 198/198 DEV PASS.


### Real original UT99 469f UStruct loaded values (2026-10-27)

Original 2026 469f Core.dll + UCC.exe, isolated CDB execution, real Core.u v69/l0: 25 genuinely loaded file-backed UFunction-derived UStruct prefixes compared after original compiled UStruct::Serialize returned, 21 native pointer-dereferenced Children targets, 25 each in-memory actual ScriptSize, FriendlyName equality, Line/TextPos, and null ScriptText, matching existing UnrealDB archive reader. 298/298 one new scoped DEV acceptance. This is original native loaded values, distinct from old C227 UField original proof. No claim of full UStruct script/error/v432/C227 parity. Evidence SHA manifest catalog/compliance/UE1-O06-G3-T99-469F-25-NATIVE-UStruct-LOADED-VALUES.json.


### Real UT99 469f nine direct UStruct plus original native malformed FriendlyName (2026-10-27)

Nine actual genuinely direct original Core.u UStruct exports were observed after original compiled Core.dll UStruct::Serialize under x86 CDB and agree with UnrealDB saved field values. Six native Children links dereferenced to actual original source linker indices; distinct from earlier 25 UFunction-derived loaded values. Real original Core.u has three additional registry UStruct classes not loaded by this commandlet. NEW one-off independent 135/135 source verification PASS. Separately, one-byte isolated source mutation of genuine BoundingBox original UStruct FriendlyName at file offset7541 changes FName79 to NAME_None and actual compiled unchanged 469f Core.dll asserts on the original UStruct::Serialize path and compiled UnClass.cpp line840; PHP rejects the same malformed field. Independent new 27/27 PASS, no genuine original L source mutated; physical native Tell cursor not verified. SHA full original binary/archive, real CDB transcripts/commands and source-specific reproducible verifiers in catalog/compliance/UE1-O06-G3-T99-469F-NINE-DIRECT-UStruct-ORIGINAL-NATIVE.json and catalog/compliance/UE1-O06-G3-T99-469F-ORIGINAL-MALFORMED-UStruct-FRIENDLYNAME.json. Whole G3 remains 2/16.


### 227k original compiled UButtonProperty and genuine captions now implemented (2026-10-27)

Earlier 2012 and original v227 public-source-only claims of unverified UButtonProperty Serialize are superseded by actual authentic 2026 227k x86 Core.dll: original method RVA0x80010 calls inherited UProperty Serialize then archives FString Caption at property member+0x8C. Original compiled FString operator RVA0x66e40 uses a signed compact character count; positive ANSI or negative UTF16LE. Both genuine ScriptedAI.u native ButtonProperty exports 2285 and2286 are now parsed exactly by the real production UE1 reader, including Captions Move point to viewport and Go to point and matching source archive end. Distinct one-shot source-focused 38/38 checks, genuine original source SHA plus REA compiled input and full output pinned in catalog/compliance/UE1-O06-G3-C227K-TWO-GENUINE-UButton-Caption-Serialize.json. The older source-only G3-02 synthetic test now expects serialized FString Caption, rather than the obsolete unverified override response (old accepted events immutable). Genuine 227k UCC.exe lacks make/batchexport commands, so native actual in-memory Caption values remain separately OPEN. UButton SerializeItem/NetSerializeItem is explicitly UNVERIFIED, no false alias to integer properties.

### G3-06 complete source class method acceptance (2026-10-27)

Source-defined UConst::Serialize and UEnum::Serialize verified across inherited C98 May1998, UT99 v400 -> v432 -> matching 469f, and OldUnreal C227. Original compiled C98 and C227i loaded native string/name values reused from accepted source evidence with hashes (no tests replayed); new actual original T99 469f compiled all 8 Core.u UConst plus ESheerAxis global FName strings compiled loaded. Original 227k Core DLL method instructions verify UField + class-specific FString or TArray<FName>, LOAD/SAVE using the same unconditional archive member operator. Source-specific v61-v63 UT99 UTF16 is not guessed from header and C98 ANSI remains separate. Existing source-typed negative/malformed/EOF evidence reused without rerunning tests, one new full contract gate PASS 56/56. G3-06 ACCEPTED, full G3 3/16. Crosscutting compiled FArchive Tell error G3-14 and all-other-method compiled original acceptance G3-15 remain open. See catalog/compliance/UE1-O06-G3-06-FULL-CLOSURE-MATRIX.json.


### G3-07 original 227k legacy property flags source correction (2026-10-27)

Matching actual 2026 compiled Unreal 227k Core.dll UProperty::Serialize compares FArchive::Ver() to 61 and ANDs loaded flags with 0xFFF7FFBF. At v60/61, C227 and UT99 original engines clear saved legacy bits 0x00080040; original C98 v1.200 preserves them. UnrealDB selected C227 effective_property_flags fixed accordingly without changing property_flags, which must retain actual saved archive bits. New native compiled UT99 469f 490 individually identified UProperty objects match saved ArrayDim/PropertyFlags, 20 actual non-NONE global Category FName names from memory, eight distinct categories overall. The 49 additional original Core.u source exports were not loaded by UCC help; no native claim for them. Original C98 1536/C227i 668 native checks reused by exact historical SHA without repeating prior tests. One new full G3-07 gate 2991/2991 accepted source-specific UProperty declaration method; linked runtime ElementSize and other source corpus G3-15 OPEN; original malformed native physical Tell G3-14 OPEN; G3 4/16.


### G3-09 original 469f compiled null MetaClass source exception (2026-10-27)

Original Unreal v1.200 and UT99 v400/v432 UClassProperty::Serialize use Ar<<MetaClass then check(MetaClass); genuine 2012 C227i and 2026 227k compiled methods retain native appFailAssert when null. But actual shipped UT99 469f x86 Core.dll stores Core!UObject::PrivateStaticClass when null, after consuming MetaClass from the archive. A real one-byte original 469f Core.u export12 change at physical offset6930 (compact ref3->0) executes the exact compiled fallback; identical native source mutation in original 2012 C227i Core.u at offset7752 enters real appFailAssert. Source files unchanged and all real debugger input/output SHAs pinned. Current PHP now has explicit UT99_V469F source overlay returning saved zero and effective original class fallback; old v432/C98/C227 remains fatal. Original compiled T99 property reference targets 12 Object +4 class Meta and C227i 25 Object +10 class Meta matched their own original saved import/export names, including Function, ArrayProperty and Property. Existing C98 600 and two malformed original native branches SHA reused. One independent G3-09 full class serializer 222/222 focused PASS, G3 5/16. T99 export771 unobserved belongs G3-15; other type/cursor/redirect cases G3-14.

### G3-08 matching compiled Pointer runtime methods (2026-10-27)

The original 2026 shipped UT99 469f x86 Core.dll PointerProperty SerializeItem and NetSerializeItem archive four bytes (RVA 0x853A0 and 0x83B70) via FArchive Serialize(Data,4). Later 227k PointerProperty and ButtonProperty share an identical SerializeItem no-op RVA 0x1EC40 (ret 8), and NetSerializeItem success-only RVA 0x7EA00 (return1, no bytes). Native 227k UAnyProperty has different complex dynamic FAnyProperty SerializeItem and NetSerialize, not a normal Vector value despite GetID alias. The property-declaration archive serializer and property-instance methods are distinct. UnrealDB exposes source-specific original compiled runtime metadata but does not falsely claim to implement the dynamic instance codecs. UT99 469f must be selected explicitly rather than inferred from version69/licensee0. One new independent original PE opcode verifier 46/46 PASS, C98 UString66 and two real 227k Button captions not rerun. G3-08 is still OPEN for native loaded Caption, Any dynamic codec, remaining scalar instance/negative/version behavior. See catalog/compliance/UE1-O06-G3-08-469F-VS-227K-POINTER-BUTTON-ANY-RUNTIME-SEMANTICS.json.


### G3-08 original 227k executable native Any values 0..3 (2026-10-27)

The genuine shipped 227k x86 Core.dll exported UAnyProperty::SerializeItem and FAnyProperty::PeekIsEmpty, SerializeEmpty, Serialize were executed as actual original machine code in disposable MSVC x86 FArchive-ABI test harness. On native LOAD and SAVE, type NULL0 consumes1 byte, INT1 and FLOAT2 each consume5 bytes, BOOL3 consumes2 bytes, real values preserved. Peek uses original Tell-Serialize(1)-Seek and returns true only for byte0 among0..7. FAny native instance allocation performed through original GMalloc on types1..3. This proves actual compiled archive-method behavior without falsely claiming the harness is the original game-package ULinkerLoad. New source-specific production value decoder accepts only these four proven dynamic types; C227 matched UAny declarations via UClass source schema enable reinterpretation of the TYPE11 Vector alias, unlike actual FVector. Original Any STRING4 NAME5 STRUCT6 OBJECT7, NET, noncanonical BOOL and full error cursor unverified/fail closed. One NEW 65/65 DEV gate, prior native tests not rerun. G3-08 OPEN, full G3 5/16. See catalog/compliance/UE1-O06-G3-08-C227K-ACTUAL-COMPILED-FANY-NULL-INT-FLOAT-BOOL-LOAD-SAVE.json.


### G3-08 original compiled C227k FAny STRING4 native roundtrip (2026-10-27)

Actual original 227k x86 Core.dll FAnyProperty::Serialize plus UAnyProperty::SerializeItem executed via disposable MSVC x86 FArchive-ABI shim. Original native FString archive method invokes virtual CountBytes at slot0x14; old shim missing it crashed (NOT an Epic error). ArIsSaving=2 selects genuine AR_INDEX compact SAVE; original tested STRING Test loaded 5 UTF16 WCHAR incl terminator, saved one type4 + compact length5 + ANSI Test+NUL = 7 bytes 04055465737400, and its own compiled LOAD faithfully round-trips. UnrealDB source-selected Any runtime value decoder now safely supports canonical small ASCII STRING4, rejects unverified Unicode/large malformed formats; ordinary FVector property tag11 never misidentified. This is an additional one-off original native compiled 36/36 DEV acceptance, with old 65+46 suites not rerun. G3-08 OPEN for NAME5 STRUCT6 OBJECT7 NET, Button in-memory Caption and scalar negative/source version checks; whole G3 5/16. See catalog/compliance/UE1-O06-G3-08-C227K-REAL-NATIVE-ANY-STRING-LOAD-SAVE-ROUNDTRIP.json.


### G3-08 original 227k Any NAME5 zero (2026-10-27)

Matching original 227k x86 Core.dll UAnyProperty SerializeItem NAME5 compiled native method actually LOAD/SAVE zero FName as five bytes 0500000000. The disposable x86 FArchive shim must implement real FName archive virtual at offset0x1c; initial null virtual was mock omission. UnrealDB source-specific Any decoder only supports zero-valued FName until actual original name table/linker nonzero values are proven; no conjectured arbitrary name resolution. New 18/18 independent scoped DEV check without rerun of previous accepted tests; G3-08 still OPEN, total G3 5/16. Evidence UE1-O06-G3-08-C227K-ORIGINAL-NATIVE-ANY-NAME5-ZERO.json.


### G3-08 native 227k Any OBJECT7 null (2026-10-27)

Original C227k shipped x86 Core.dll UAnyProperty::SerializeItem + FAnyProperty::Serialize compiled method serializes a null UObject as source TYPE7 followed by four bytes zero: 07 00 00 00 00. Actual original native LOAD and SAVE executed with FArchive UObject* virtual vtable slot0x18. Source-v227 Any instance reader implements only proven null UObject; no invented non-null ULinker object mapping or class instance values. Original STRUCT6 null test cannot execute in incomplete mock without valid initialized UStruct; test-only AV is NOT an Epic engine error. New independent one-off 20/20 focused DEV acceptance, old tests not replayed, G3-08 OPEN, full G3 5/16. See catalog/compliance/UE1-O06-G3-08-C227K-REAL-OBJECT7-NULL-LOAD-SAVE.json.


### G3-03 actual original 2012 C227i UStruct script memory (2026-10-27)

Original shipped 2012 C227i x86 Core.dll directly loaded 237 genuine UField-derived UStruct objects with original UCC and unchanged source Core.u. Actual native original UStruct::Serialize after-archive breakpoint: 223 nonzero script UFunctions, 12 direct UStruct and 2 Class inherited prefixes, all exact source metadata, 232 real Children ref pointers. Second real compiled CDB native run captured 223 actual original Script.TArray memory buffers, source-only known opcode bytes 646/646 equal the current SerializeExpr memory reconstruction; native UObject pointer and global FName relocation bytes masked, never compared with local archive values. Original 0xFFFFFFFF source TextPos/Line is -1 as signed 32-bit INT. Distinguish memory bytecode Script.Num from compact on-disk archive bytes and exclude debug allocator padding outside original ScriptSize. One new focused 4,412/4,412 DEV native proof, old 915 original UField and other native acceptance tests not replayed. C98/T99/other C227 source variants, SAVE and native malformed Tell still required. G3-03 OPEN, G3 5/16. See catalog/compliance/UE1-O06-G3-03-C227I-237-ORIGINAL-NATIVE-223-SCRIPTS.json.
