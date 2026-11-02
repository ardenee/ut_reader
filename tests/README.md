# UnrealDB file-behaviour parity — current testing authority

The objective is to make UnrealDB's **observable file handling** match the correctly selected Epic engine and game-loader source: package identity, names, objects, serialized values, imports, dependencies, physical provider selection, database projection, and errors. We do not attempt to reproduce gameplay or rendering.

## Canonical files
- [TEST-PLAN.md](TEST-PLAN.md): readable 24-behaviour, per-engine risk and exact stopping-condition plan, generated from contracts.json.\n- [contracts.json](contracts.json): ONE live register with 24 frozen behaviour categories, 115 applicable UE1–UE5 contract pairs and a single current decision per pair. NOT_REVIEWED means end-to-end proof has not yet been mapped, NOT that historical accepted proof is invalid.
- [code-paths.json](code-paths.json): SHA-index of actual production PHP files and operational entrypoints. Filename-derived roles are discovery candidates, not proof of runtime reachability.
- [callgraph.json](callgraph.json):  static lexical class-reference navigation (not proof of actual runtime calls).\n- [ARCHITECTURE.md](ARCHITECTURE.md): production ingestion, reader, dependency and persistence boundaries.
- [WORKFLOW.md](WORKFLOW.md): mandatory reason-to-test, one-proof, stop and release rules.
- [LEGACY-ACCEPTANCE.md](LEGACY-ACCEPTANCE.md): closed historical programmes whose evidence is still available.
- [tools/index_codepaths.py](tools/index_codepaths.py): read-only codepath index builder.
- [tools/check.py](tools/check.py): no-engine-run schema, source-path and archived-SHA validator.

All historic test scripts, results and notes are under [archive/testing-legacy-2026-11-02](../archive/testing-legacy-2026-11-02/README.md); old dashboards and journals no longer determine current status.

A PASS needs applicable Epic source, a genuine/authoritative oracle for the relevant result, current code identity, and exact end-to-end persisted/visible outcome. SOURCE_NA needs evidence that this precise branch cannot apply. Missing load/mount context is UNKNOWN or AMBIGUOUS, never confidently MISSING.
