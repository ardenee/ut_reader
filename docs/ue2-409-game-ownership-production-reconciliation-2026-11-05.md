# UE2 production file/game ownership correction — 5 November 2026

## Why

The previously completed Unreal II game #2 Full Sync accurately republished **1,217** files for the *game IDs assigned in MySQL*, not necessarily their original owning games. A source path/provenance audit and exact filesystem SHA-256 comparison showed a substantial set of UT2003 and UT2004 packages had been attached to Unreal II instead. This also explained 219 `GamePlay` imports: those consumers came from UT2003/UT2004 files, while the matching `GamePlay.u` providers were correctly indexed in UT2003/UT2004.

The original UE2 package header does **not** encode an authoritative UnrealDB game ID. A database `source_relative_path` belongs to **catalogue import provenance**, not an intrinsic serialized UE field. Trustworthy original install/archive roots, exact byte hashes, engine package/licencee versions and compatible import/export data can provide convergent evidence. Neither package version, name nor cross-game identical hash alone proves unique ownership. For example, original Unreal II `343T.utx` v110/lic2481 also has byte-identical material indexed in UT2004; the original Unreal II file was correctly **retained**. Similarly, `ut2003barrensarc.utx` v110/lic2609 is an Unreal II map-pack package despite its filename.

## Completed production changes

**First source-directory batch:** All 391 verified wrong-game files identified by source directory names were physically checked against their *single* verified file in the destination game. All 391 source/target pairs matched full SHA-256 (2,077,899,282 source bytes). 88 had original UT2003 directory evidence and were already in game #4; 303 had original UT2004 directory evidence and were already in game #5. Each incorrect game #2 source was removed through `CatalogFileMaintenanceRemovalService::remove(fileId, null, true)`, preserving destination bytes. MySQL deletes, V5 metadata/projections, actual stored files and source UEDB5 containers were checked absent. Queued mirror job #453 was retargeted to surviving UT2004 file #137892 instead of losing its history through cascade deletion.

**Second version/licencee batch:** After the first pass, 18 more game #2 files had UE2 package version >=119 with licencee version 29 (UT2003/UT2004 family). Their original source paths were filename-only, IP download directories, custom archive locations or generic directories rather than definite original installation roots. All were further checked individually against actual UT2004 package provenance and SHA-256 before action:
- 15 had byte-identical verified UT2004 destination files and were removed from game #2 through the same canonical deletion service, with no cleanup warnings.
- Three were not identical to an existing UT2004 file and were **imported and verified into game #5 first**, using `CatalogVerifiedFileReassignmentService::move`, with preservation of their distinct physical bytes: `U2Weapons.u` #149675 → #1390450, `U2WeaponsA.uax` #149677 → #1390451, and `DM-ArizA][.ut2` #1247934 → #1390452. Each source retirement succeeded with zero cleanup warnings. The original Unreal II v110 `U2Weapons` and `U2WeaponsA` packages remained in game #2, where they belong; confusing name overlap was not used to select the target. Queued mirror job #88 was retargeted to #1390452.
- An initially failed **test callback** stopped the first attempted #149675 move *before source deletion*, after the UT2004 destination was already verified. The runner was corrected and rerun through the canonical duplicate-destination importer path; final source and target checks passed. No original bytes or metadata were lost on that unsuccessful attempt.

**Total:** 409 incorrect files retired from Unreal II (406 exact duplicate deletions; 3 verified moves). All 409 old file IDs, per-file UEDB5 registrations, migration statuses, provider keys, dependency edges, dependency package summaries and classic provider projections were verified absent; all destination records were verified present. The 409 old physical package files and source metadata containers were verified absent from live storage. The original metadata containers remain in maintenance audit backups. Three original *nonduplicate* package byte copies were also saved separately before source retirement.

Verified file totals after repair:
| Game | Before | After |
| --- | ---: | ---: |
| Unreal II (ID 2) | 1,217 | **808** |
| UT2003 (ID 4) | 5,082 | **5,082** |
| UT2004 (ID 5) | 72,880 | **72,883** |

Remaining Unreal II `GamePlay` references: **0**. Game #2 also has zero remaining verified v119+ / licencee29 packages in the audited family.

## Backup and exact repair evidence

Retained outside the public website, on the PRD storage drive:
`L:\UnrealDB\maintenance-audit\ue2-owner-fix-20261105\`

- `manifest.json`: original relational source file rows, original/destination file identities, complete before-deletion physical SHA-256 and source UEDB5 SHA-256 for 391 files. Manifest SHA-256 `54239698BE60D07BB5A3B9B2C3A43C118B907CE57D6985FF9359ED5023EAAD57`.
- `metadata/2/...`: 391 original UEDB5 metadata containers, separately SHA-256 verified.
- `apply.jsonl`: durable per-file source removal / destination confirmation events.
- `version29/manifest.json`: 18 additional source records/bytes and suggested target identities, SHA-256 `21B0577935E1435D2EDFD13D554506B3B2F85598BA003A012B46A28760741D2D`.
- `version29/metadata/2`: all 18 source UEDB5 containers (full SHA-256 verified).
- `version29/originals`: original bytes of the three packages without a pre-existing identical UT2004 copy.
- `version29/three-moves-ledger.jsonl` and `version29/duplicate-deletion-ledger.jsonl`: resumable source/target actions.
- `old-coverage-cache-v14.json`: 197 old derived package-coverage headers and 145 old provider coverage rows backed up before invalidation; SHA-256 `9AA3CE909B36A1CD698473B93716D6D5646FA69CEE29E293E57621ED5C8F15EF`.

The one-off audit and postflight verifiers are retained on DEV under `D:\Temp\UnrealDB\UE2`. A guarded, repeatable CLI reproducing the first 391 exact-delete actions, with `--apply`, exact manifest checksum, physical source/target SHA and foreign-linkage safeguards, was committed as `catalog/bin/reconcile-ue2-duplicate-game-ownership.php`. It does not make assumptions about undetermined game ownership and was run successfully in two resumable passes (1 + 390). It cannot delete the correct-game copy.

## Derived data and remaining publication

The previous Unreal II Package Coverage cache was built on obsolete v14 consumer files. Its 197 game #2 package headers and 145 provider rows were backed up and invalidated. **The rebuilt Package Coverage completed successfully** from UE2 v15 source-current original 808-file data: **84 logical package groups inspected, 70 cached, 14 pruned**. This did not require any original package Full Sync.

The game #2 statistics cache was rebuilt separately after the original-file cleanup: the same production website freshness gate reports **CURRENT** with 808 verified files, one duplicate, and **74,556 total dependency references** instead of the previous 110,970. UE2 `common` count remains zero; no selected-runtime provider was invented. UT2003 remains 5,082 verified and v15-current. UT2004 has 72,883 verified and still needs completion of its independently running v15 dependency publication before its game stats can be marked fresh.

At final source-policy preflight: Unreal II game #2 808/808 v15 source dependency completed, UT2003 #4 5,082/5,082 completed, UT2004 #5 5,893/72,883 completed (as of that check). **The UT2004 publisher was apparently running independently**; do not launch a competing job. The three newly imported UT2004 files also queued standard affected-dependency/projection-reconciliation jobs (#9526948–#9526952); these remain queued for the PRD worker, not falsely reported complete.

Game statistic/display freshness is separate from file ownership. A partially republished engine game must remain marked stale until Pass 2 and its summary rebuild finish. There is no need to rescan original package bytes or to change Epic source.

## Forward-looking auto-assignment rule

Recommend implementing an *advisory* game suggestion with explicit evidence classes:

1. **High-confidence** — authoritative installation/archived folder identifies a game, its original profile accepts the package, and exact bytes are already verified under that game. Source-based cross-game duplications can be reconciled safely.
2. **Candidate** — a package version/licencee lineage, package type, GUID, import/export content and/or filenames suggest a family but the installation provenance is absent. Distinguish UT2003/UT2004 version lineage from the different Unreal II v110/licencee2481/2609 and XMP v126/licencee0 families.
3. **Ambiguous/reused** — original package bytes match multiple games, or ports/mods deliberately reuse resources. Never delete the only valid original provenance or move by filename/MD5 alone.

Epic's loader chooses a physical package in its installed context; UnrealDB can compare offline candidate compatibility without inventing that runtime selection. Stored `source_relative_path` values come from catalogue acquisition, not UE package header serialization.
