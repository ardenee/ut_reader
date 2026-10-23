# UnrealDB source precedence: engine first, game differences second

**Binding rule for all Unreal Engine generations (UE1–UE5).** The engine's actual selected C++ serialization code is the base contract. A game's build-selected source may add, override or remove behavior **only at the corresponding source operation**. Neither the game's name nor the engine version may substitute for the other's identity.

1. **Engine baseline.** Select the actual engine generation, branch and revision (UE1; UE2/2.5; UE3; UE4; UE5). Derive package headers, archive primitives, class inheritance, version gates, linker references, and serializers from the relevant build-selected Epic source. Later generations do not inherit UE1 disk codecs unless their own source explicitly says so.
2. **Game/load context.** Identify a game/fork and the loader's revision independently, from a proven game-source header/signature, configured known game context, or explicit caller selection. A package may have been written by one engine revision and opened by a later compatible game loader. The archive's file version alone never identifies a game.
3. **Compose source behavior.** Run the inherited engine serializer first. Overlay exactly the game's changed methods, fields, predicates and post-load actions. When the game source calls `Super::Serialize(Ar)`, its parent archive must still run. Only `Ar.IsLoading()` / `Ar.IsSaving()` branches produce bytes; `Ar.SerializeRefs()`, garbage-collector walking, memory reallocations, and runtime effects must be kept distinct.
4. **Unresolved provenance.** Do not use a different game's reader because its layout happens to parse or its version number matches. Do not silently fall back to UT99 or any other game. If no source-proven loader context determines a later revision's serialization, report that source profile as unresolved and require caller-provided provenance. Do not present speculative fields as decoded truth.
5. **Acceptance separation.** `MAPPED` means the source-backed file read implementation is present. Independently verified loaded values, native runtime behavior, genuine-corpus comparison, writes and deployment have separate acceptance requirements. Never promote an unimplemented serializer to MAPPED because its source location is documented.

## UE1 implementation

Epic source baseline: `L:\Source\Engine\UE1\Source` and pinned Epic engine revisions for historical package versions. Unreal/Unreal Gold, UT99 and licensee forks are separate **game/engine-fork overlays**, not aliases.

- **Unreal v1.227:** game source `L:\Source\Games\Unreal\Unreal [v1.227]`; confirmed version **69** and licensee version **227**. This combination identifies the Unreal v1.227 loader; it never selects UT99. Its emitter C++ overrides use UnrealScript archive class identities `XBeamEmitter`, `XParticleEmitter`, `XRopeDeco` (C++ `AXBeamEmitter`, etc.). On load, the inherited UE1 tagged-property stream is decoded before those game methods. The available v1.227 tree does **not** include complete replacement `Core/Src`; unmatched core changes remain unverified rather than borrowed from UT99.
- **UT99 v400:** select `ut99-v400` only from known UT99 loader provenance, not from package version 62–69. UT99 can load older packages: the game loader's selected serializers still apply to the old on-disk version's compatibility branches.
- **Earlier Unreal:** UE1 engine source defines the common legacy archive. May and July 1997 original sources add source-specific serializers; they can share package version 23 and therefore require their own source selection. Historic runtime/load effects are tracked separately.
- **Source selection:** `CatalogUE1ClassicSourceFamily::selectFromHeader` respects the documented Unreal v1.227 version/licensee signature. `select` does not infer UT99 from later package versions. Top-level tagged, class, native and CLI readers select once and propagate the source profile into nested field, inherited class, array, struct and bytecode readers. `CatalogUE1ClassicGameSourceOverlay` applies the three Unreal v1.227 actor overrides after their inherited tagged archive.

## UE1 source-profile version eligibility (implementation 2026-10-23)

The pinned game sources declare inclusive minimum and maximum **complete-backward-compatibility** versions from Core/Inc/UnObjVer.h:

| Game loader source profile | PACKAGE_MIN_VERSION | PACKAGE_FILE_VERSION | UnrealDB source ID |
|---|---:|---:|---|
| Unreal v1.200 | 34 | 61 | epic-ue1 |
| Unreal Tournament v400 | 60 | 68 | ut99-v400 |
| Unreal v1.227 | 60 | 69 | unreal-v227 |

CatalogUE1ClassicSourceFamily::GAME_VERSION_GATES and eligibleProfiles($fileVersion) enforce these ranges for source-profile **eligibility**, including explicit --ue1-source choices.

- v34–59: Unreal v1.200 source eligible.
- v60–61: all three sources eligible, not auto-identified.
- v62–68: UT99 v400 and Unreal v1.227 eligible, not auto-identified.
- v69: Unreal v1.227 is the only source in this pinned selection set; version alone still does not prove the producing game. The 69/227 header pair is positive evidence for that Unreal loader.
- Outside these ranges: none of these source profiles claims complete compatibility. Earlier original UE1 v22–23 sources are handled separately; other game branches need their own source-backed rules.

**Crucial source distinction:** The actual ULinkerLoad::LoadSummary check in both Unreal v1.200 and UT99 v400 *prompts* on files older than PACKAGE_MIN_VERSION and may continue. The eligibility gates deliberately reflect complete-compatibility support, **not an assertion that the engine refuses every out-of-range file**. A newer file is not rejected by that particular minimum-version check, either, but may fail in subsequent parsing or linking.

The CLI reports all eligible profiles before selecting. If multiple sources are eligible and no loader provenance is provided, it reports ambiguity rather than silently interpreting a file using the wrong game. A future candidate probe must compare complete structural outcomes and surface ambiguity when multiple sources succeed; successful tagged-property parsing alone does not identify a game. Game source is layered over the Epic UE1 engine serializer, never substituted for it.

## Future UE2 / UE2.5 / UE3 / UE4 / UE5 work

For every code review or new reader, keep a `base_engine_source`, `selected_engine_revision`, `game_loader_source` and `override_source` conceptually separate. Determine version and licensee gates from the specific code branch. Never apply the behavior of Unreal II to UT2003, UT2004 to UE2.5, UT3 to generic UE3, or a custom UE4/UE5 game fork to a generic engine file without a verified inheritance/override relation. The same applies to cooked package loaders, object index resolution, script data, compression, redirects and dependency/linker fixups.

This document is an **architecture requirement** for all versions, not a claim that every UE2–UE5 call site has already been refactored or independently verified.
