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
