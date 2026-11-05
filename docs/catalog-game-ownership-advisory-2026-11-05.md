# Game ownership evidence in Unverified Files and Dependency Cross-Examine

5 November 2026 — purpose: help an administrator select the correct destination game while preventing the category of wrong-game imports recently discovered in Unreal II.

## Two independent questions

**Game ownership** means the original game, installation or archive from which a package was acquired. UE1/UE2/UE3 package headers do not encode an authoritative UnrealDB game ID. Acquisition directory evidence (when trustworthy), source-specific version family, and hashes matching an existing verified package can assist; none of these should silently overwrite the imported game or invent original provenance.

**Dependency compatibility** means that an individual candidate package can satisfy one or more import paths required by another game's package, verified with that target game's Epic source policy. A UT2004 package can be compatible with another UE2 game without having originated from that game. Nor does matching the object paths cause the original Epic loader to search for and mount that physical archive.

Both dimensions are now separately visible on the two administration pages, and both use a common *read-only* `CatalogGameOwnershipEvidence` service.

## Unverified Files

- New **Original game clues** column alongside the existing **Dependency evidence** column. It distinguishes exact known package bytes, acquisition directory, possible game, reused bytes across multiple games, conflicting directory clues and unknown ownership.
- Exact-file evidence uses the complete stored **MD5 + SHA1 + file_size** fingerprint (labelled as such, not an unperformed SHA-256 comparison). Links reveal the exact already-verified file ID(s) and game(s).
- Only explicit directory segments (for example, `Unreal Tournament 2004/System`, `UT2003/System`, `Unreal II The Awakening/Textures` or `Unreal2XMP/System`) are mapped. These map to dynamically loaded catalogue **game slugs**, not numeric IDs inferred from package version. A filename like `UT2003Weapons.utx` or generic `Unreal/Maps` is *not* treated as game ownership.
- UE2 package-version/licencee evidence is shown separately as a **family-only clue**, never as proof of one game.
- When selecting files and a target game, a live note reports mixed source directories, target mismatches and identical files already present in the destination. The browser asks for confirmation before **Import selected** or **Move queue** if selected source-directory evidence conflicts, or if an `All exact compatible games` operation crosses original-directory hints. No target option is selected automatically.
- Public IoStore sets are explicitly identified as needing retained container or trusted upload provenance; their original-game assignment is not inferred from this classic-package fingerprint view.

## Dependency Cross-Examine

- The report continues to assess **actual missing target-game imports** with the target engine's source-specific VerifyImport/complete-consumer checks.
- New **Original game clues** column for each verified source candidate. The original `source_relative_path` is now included in the query.
- Displays source-game mismatch leads, identical already-indexed destination bytes and cross-game reuse. The Queue Selected action warns and requires browser confirmation for these noteworthy conditions.
- **Cross-game copies remain permitted** where required by target dependency compatibility. A copy does not relabel original game ownership. The action continues to use its existing server-side queue and source-specific compatibility revalidation.

## Performance and constraints

The service runs only **bounded, indexed, read-only lookups** over the rows actually rendered (100 default; max 1000 on Unverified Files, 500 on Cross-Examine). It uses `ue_files(game_id,md5)`, matches SHA1 and size in the indexed result, and never scans original package data, rebuilds UEDB5 or examines unrelated consumers in the page request. Capped sample IDs per game keep the evidence cell compact.

No database migration, game reassignment, UEDB5 regeneration or change to Epic UE1–UE5 readers or game-specific source policy was made for these UI clues. The existing importer remains the authoritative handler of actual input acceptance.

## Checks

Focused deterministic regression: `tests/tools/verify_game_ownership_advisory_ui.php`. Covers directory + identical confirmation, legitimate Unreal II resources reused in UT2004, generic archive/filename false positives, ambiguous identical bytes in both UT2003 and UT2004, contradictory source folder segments, slug-ID independence, separate UE2 version family hint, escaped user data, and both actual page wiring points.

Additional read-only PRD MySQL smoke: 100 real original Unreal II files evaluated with the service in approximately 26 ms from DEV; the case `Unreal II The Awakening/textures/343T.utx` has identical bytes in UT2004 but was correctly assessed as an Unreal II **directory clue**, not a misassignment. There were zero indexed unverified files at the time of that read-only check, so a full live Unverified Files row cannot yet be observed without creating a new staged input. The deterministic fixture verifies the row renderer and selection warning integration.

The 5 November UE2 Pass-2 post-publication check independently reports UE2 policy v15 complete for Unreal II **808/808**, UT2003 **5,082/5,082** and UT2004 **72,883/72,883**. All three game statistics freshness gates report current, with UE2's old `common` classification removed.
