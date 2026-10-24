# UE1 O06 — G5 evidence reconciliation

Evidence inventory, not O06 acceptance. Generated 2026-10-24T11:16:41.750204+00:00; Git bd2bcc9c.

## Counts

- 256 MAPPED; 38 EXCLUDED; 0 methods with demonstrated full independent native behaviour across all relevant criteria.
- 59 entries have no named test; 52 of these are R82/R83 early-resource identity mappings.
- 3 independent compiled v432 native export comparison slices: T99:UMusic::Serialize, T99:USound::Serialize, T99:UTextBuffer::Serialize.
- Compiled v432 method archive-order evidence with no genuine loaded-value evidence: none.
- Current genuine archive compatibility: 1 method (T99 UFont; 34 SHA-pinned file exports / 66 checks PASS).
- Current **compiled v432 genuine native loaded-value subsets**: 6 methods (T99:UAnimation::Serialize, T99:UClass::Serialize, T99:UFont::Serialize, T99:UObject::Serialize, T99:USkeletalMesh::Serialize, T99:UTextBuffer::Serialize). Includes UClass 88/131 actual compiled ClassFlags/GUID/TArrayCounts/ConfigName matches, UFont real glyph and nonempty remaps, USkeletalMesh/Animation full nested-array subsets, all 88 genuine Engine.u TextBuffer post-load values, and common UObject real inherited flags + 14/19 compiled positive RF_HasStack map frames. These remain PARTIAL method proofs; full error/reference/inherited source-family closure is OPEN.
- NEW compiled v432 **genuine StateFrame referent name subset**: 1 verified UObject method with 14/19 original Entry.unr v68 StateFrames and all 28 real compiled Node/StateNode FName strings matching import references, plus exact ProbeMask64/LatentAction/Code values (195/195 PASS). Five Camera original exports and complete outer/class/error semantics OPEN.
- NEW **full native array-element subsets**: 2 compiled v432 methods (T99 USkeletalMesh, UAnimation): genuine 5 skeletal objects 42,032 bytes of complete bone index/weights and 225,756 bytes of complete Points/LocalPoints, plus 143 animation ref bones full native name-table-resolved FName/flags/parent semantic records (1,716 bytes). Total 269,504 bytes, focused 733/733 + 228/228 PASS, no repeated tests.
- NEW independent compiled-v432 **resumable 2-case batch**: 237 full native FMeshBone records (15,168 bytes) source field-by-field including runtime-resolved names and XSize's second serialized write, 1787/1787 PASS; 70 full native FMeshAnimSeq metadata records (2,240 bytes), all 22 nested native notification time/function names, 575/575 PASS. Both were rerun in resume mode with 2/2 UNCHANGED_PASS cache skips, zero repeated tests. Combined new 2362/2362 checks. SHA-pinned native manifests and append-only events preserved; YSize/Depth nonserialized fields and MotionChunk Moves still OPEN.
- NEW third resumable native contract: 70 genuine MotionChunk in-memory scalar/root header and array-length comparisons, 95 full first-Move AnalogTrack header checks plus 732 full first-track key/BoneIndices bytes identical, 598/598 PASS. Remaining KeyQuat/KeyPos/KeyTime buffers from all other tracks still OPEN; did not replay previous PASS evidence.
- NEW compiled real v432 **full nested MotionChunk memory**: 70 authentic Moves, 3,292 AnalogTracks, 10,156 distinct root/track/bone nested array buffers, 540,836 entire loaded bytes equal every source byte, 21,020 KeyQuat, 9,268 KeyPos and 21,020 KeyTime keys. Microsoft CDB 46MB full-memory MiniDump MEMORY64 oracle for each original package, independent source bounds/float-preview comparisons; focused 29,145/29,145 PASS, one initial test aggregation FAIL preserved. This validates complete nested element values for TWO original v69 package UAnimation exports, not malformed runtime or other source families.
- Installed vanilla UT99's 310 original packages had no skeletal/animation exports; the additional original-filename-indexed PRD corpus does (5 SkeletalMesh+3 Animation compiled loaded).
- Latest DEV journal state: {'PASS': 118}.
- Named tests with current matching source fingerprint: 2 of 197; historical PASS is not an automatically current proof.
- Global source fingerprint in latest status 834dac6fe9b5fc71c3d8cfb04667bd01046dc258e5c93b57655dfb01831c4978; independently recomputed 834dac6fe9b5fc71c3d8cfb04667bd01046dc258e5c93b57655dfb01831c4978.

## Branches

| Branch | MAPPED | No named test | Native export slices | Compiled method only | Native loaded values subset | Full array element subset |
|---|---:|---:|---:|---:|---:|
| C227 | 3 | 0 | 0 | 0 | 0 | 0 |
| C98 | 40 | 2 | 0 | 0 | 0 | 0 |
| D97 | 6 | 1 | 0 | 0 | 0 | 0 |
| R82 | 26 | 26 | 0 | 0 | 0 | 0 |
| R83 | 26 | 26 | 0 | 0 | 0 | 0 |
| R96 | 23 | 0 | 0 | 0 | 0 | 0 |
| T99 | 46 | 4 | 3 | 0 | 6 | 2 |
| U86 | 43 | 0 | 0 | 0 | 0 | 0 |
| U97 | 43 | 0 | 0 | 0 | 0 | 0 |

## G5 finite queue (do not replay existing valid tests)

1. Identify exact v432 PHP reader changes since last journal fingerprint and rerun only their invalidated focused tests.
2. NEW resume-safe FOUR-contract native batch: 237 FMeshBone records, 70 real AnimSeqs and 22 notifications, 70 MotionChunk headers, 95 first-Move AnalogTracks, plus ALL 10,156 nested animation arrays (540,836 native bytes) from original EcoTerror/d00d. Remaining priorities: inherited ULodMesh on genuine skeletal objects, native malformed/error semantics, and other source families; SFCharacter4 deps remain blocked.
3. Compare actual native property/default/struct loaded values, reference identities and consumed bytes for available genuine packages.
4. R82/R83: 52 registered methods carry only source identity proof, no named test. Attach actual per-method source/production/fixture evidence before G5 signoff.
5. Early R82/R83/R96/U86/U97/D97: locate matching original runtime; otherwise record a native oracle limitation, not invented PASS.
6. Verify malformed boundaries for remaining high-risk serializer families, then close O06 only with complete per-method accepted/limited list.

## Entries without named tests

R82:UActorList, R82:UArray, R82:UBounds, R82:UBspNodes, R82:UBspSurfs, R82:UBuffer, R82:UCamera, R82:UClass, R82:UEnum, R82:UFont, R82:ULevel, R82:ULightMesh, R82:UMesh, R82:UMeshMap, R82:UModel, R82:UPalette, R82:UPlayer, R82:UPolys, R82:UScript, R82:USound, R82:UTerrain, R82:UTextBuffer, R82:UTexture, R82:UTransBuffer, R82:UVectors, R82:UVertPool, R83:UActorList, R83:UArray, R83:UBounds, R83:UBspNodes, R83:UBspSurfs, R83:UBuffer, R83:UCamera, R83:UClass, R83:UEnum, R83:UFont, R83:ULevel, R83:ULightMesh, R83:UMesh, R83:UMeshMap, R83:UModel, R83:UPalette, R83:UPlayer, R83:UPolys, R83:UScript, R83:USound, R83:UTerrain, R83:UTextBuffer, R83:UTexture, R83:UTransBuffer, R83:UVectors, R83:UVertPool, D97:UObject::SerializeData, C98:AActor::Serialize, C98:UFireTexture::Serialize, T99:AActor::Serialize, T99:UAnimation::Serialize, T99:UFireTexture::Serialize, T99:USkeletalMesh::Serialize

Machine-readable rows: UE1-O06-G5-RECONCILIATION.json
