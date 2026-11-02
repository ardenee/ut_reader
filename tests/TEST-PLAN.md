# Required UnrealDB file-parity behaviours — UE1 through UE5

> Auto-generated from [contracts.json](contracts.json). Edit the single JSON register, not this table.

Each row describes **what** must agree with the selected Epic source, **why** users care, and **what result ends verification**. It does not authorise tests in advance. First map existing native/source evidence and trace actual code paths. The applicable engine cells are current *planning states*, not summaries of old G3/G5 evidence.

| ID | UnrealDB behaviour | Failure affecting users | Epic-equivalent stopping condition | Applicable engines |
|---|---|---|---|---|
| F01 | File intake and type identification | wrong reader, false acceptance | Package magic, known signatures, platform and envelope; no filename-only format inference | UE1, UE2, UE3, UE4, UE5 |
| F02 | Engine/game/build provenance | wrong game format for same version | Epic source-selected lineage; ambiguity must be preserved | UE1, UE2, UE3, UE4, UE5 |
| F03 | Archive byte primitives | offset drift and corrupt identities | Exact boundary bytes and cursor at field after value | UE1, UE2, UE3, UE4, UE5 |
| F04 | File header and package summary | wrong sizes and offsets | Source-gated headers and exact section offsets | UE1, UE2, UE3, UE4, UE5 |
| F05 | Name map and FName identity | missing objects through name mismatch | Native FName / NameMap identity equivalence | UE1, UE2, UE3, UE4, UE5 |
| F06 | Import/export table and object indices | wrong provider or outer identity | Every reference resolves to same raw table index and object path | UE1, UE2, UE3, UE4, UE5 |
| F07 | Archive compression, crypto and container payload | silent payload corruption | Byte-identical uncompressed package payload, offset and failure behaviour | UE1, UE2, UE3, UE4, UE5 |
| F08 | Serialized class, struct and property fields | missing fields or false dependencies | Relevant persisted members and next archive cursor equal Epic | UE1, UE2, UE3, UE4, UE5 |
| F09 | Script bytecode and serialized references | missing genuine references or invented edges | Exact token spans, referenced FNames/objects and errors | UE1, UE2, UE3, UE4, UE5 |
| F10 | Export payload and bulk resources | incorrect metadata and unreadable download | Offsets, lengths and exposed typed values match Epic | UE1, UE2, UE3, UE4, UE5 |
| F11 | Mounted package/container ordering | wrong physical duplicate picked | Same chosen provider under fully known context; otherwise explicit unknown | UE1, UE2, UE3, UE4, UE5 |
| F12 | Package name and required dependency extraction | missing or fabricated package dependencies | Exact required package keys and qualifying import rows | UE1, UE2, UE3, UE4, UE5 |
| F13 | Object identity, outer chain and VerifyImport | false matches or missed references | Same resolved object ID/path or justified source unresolved result | UE1, UE2, UE3, UE4, UE5 |
| F14 | Redirects, remaps, common/native packages | incorrect missing/common statuses | Exact source-aware identity rewrite and result | UE1, UE2, UE3, UE4, UE5 |
| F15 | Duplicate providers and conflicting packages | wrong downloaded or linked package | Same loader precedence and no DB ordering fallback | UE1, UE2, UE3, UE4, UE5 |
| F16 | Dependency edge construction | missing edges and false matches | Exact edge set, statuses and reason codes from source-known context | UE1, UE2, UE3, UE4, UE5 |
| F17 | Dependency transitive closure and retry | incorrect missing-count or infinite retry | Deterministic graph transitions on provider appearance/removal | UE1, UE2, UE3, UE4, UE5 |
| F18 | UEDB5 staging and catalog metadata | wrong persisted catalog records | Decoded snapshot equals persisted candidate identity and counts | UE1, UE2, UE3, UE4, UE5 |
| F19 | MySQL publication and reindex | stale/duplicated indexes | Atomic result consistency and idempotence on changed providers | UE1, UE2, UE3, UE4, UE5 |
| F20 | Invalid, truncated and unsupported input | invented accepted data or offset-overread | Source-backed error state, no partial success/DB pollution | UE1, UE2, UE3, UE4, UE5 |
| F21 | Download, generated files and output integrity | corrupted files provided to users | SHA-identical original or source-valid regenerated package when supported | UE1, UE2, UE3, UE4, UE5 |
| F22 | User-facing examine/search/coverage result | misleading user claiming package works | UI matches authoritative database rows and distinction between evidence and runtime | UE1, UE2, UE3, UE4, UE5 |
| F23 | UE4/UE5 cooked package store and IoStore | wrong cooked package or missing dependencies | Source-selected IoStore/Zen package store mapping and byte-exact payload | UE4, UE5 |
| F24 | Pak/archive ordering and file identity | duplicate or wrong entry selected | Engine-selected entry and original decompressed bytes | UE3, UE4, UE5 |

## Mandatory evidence fields for one accepted engine/contract result

1. Engine and game revision including container and loader provenance.
2. Exact Epic function and source SHA or authentic compiled-oracle SHA, with version/branch used.
3. The real UnrealDB reader/provider/persistence/website call chain and current code SHA.
4. Genuine file(s), required preexisting mount context, expected source output and actual UEDB5/visible output.
5. Exact field/reference/edge comparison plus error/unknown semantics.
6. PASS / FAIL / BLOCKED / SOURCE_NA with reason and one SHA-pinned final accepted record.

## Order of investigation (not a new test queue)

**First:** UE1 package identity and end-to-end dependencies (F02, F05–F06, F11–F19, F22), since wrong providers and falsely missing dependencies affect users immediately. Reuse the frozen original 80-test and G5 acceptance before authorising any new original-engine run.

**Then:** remaining UE1 visible archive/extraction, errors and file-download paths (F01, F03–F10, F20–F21). Only actual unverified file effects remain open.

**Next:** UE2/UE2.5 and UE3, based on their own Epic/game source and different linker, import and archive branches. Do not borrow UE1 results by default.

**Finally:** UE4 and UE5 end-to-end database/provider/UX coverage, reusing the already accepted UE4.27.2 and UE5.8.3 original package/Zen/IoStore differentials rather than rerunning them.

Never expand the catalogue or add a test because the method happens to exist. A new required contract requires an observable UnrealDB user-facing failure not already represented above and explicit approval.
