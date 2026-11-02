# UnrealDB file-behaviour parity — current testing authority

The objective is to make UnrealDB's **observable file handling** match the correctly selected Epic engine and game-loader source: package identity, names, objects, serialized values, imports, dependencies, physical provider selection, database projection, and errors. We do not attempt to reproduce gameplay or rendering.

## Canonical files
- [TEST-PLAN.md](TEST-PLAN.md): 32 concrete operation/path cases with source, fixture, user risk and stopping condition, generated from contracts.json.
- [contracts.json](contracts.json): ONE live register with 24 risk categories (not tests), 32 actual implementation-derived operation cases and a single current decision per case. NOT_REVIEWED means end-to-end proof has not yet been mapped, NOT that historical accepted proof is invalid.
- [code-paths.json](code-paths.json): SHA-index of actual production PHP files and operational entrypoints. Filename-derived roles are discovery candidates, not proof of runtime reachability.
- [callgraph.json](callgraph.json):  static lexical class-reference navigation (not proof of actual runtime calls).
- [ARCHITECTURE.md](ARCHITECTURE.md): production ingestion, reader, dependency and persistence boundaries.
- [WORKFLOW.md](WORKFLOW.md): mandatory reason-to-test, one-proof, stop and release rules.
- [LEGACY-ACCEPTANCE.md](LEGACY-ACCEPTANCE.md): closed historical programmes whose evidence is still available.
- [tools/index_codepaths.py](tools/index_codepaths.py): read-only codepath index builder.
- [tools/impact.py](tools/impact.py): change-impact map from product files to affected UE1–UE5 operation cases; shared code flags multiple engines.
- [evidence/reader-isolation-20261102.json](evidence/reader-isolation-20261102.json): one accepted exact before/after four-genuine-package, source-shaped snapshot comparison for independent UE1/UE2 parser split. No original native parity or PRD acceptance asserted.\n- [tools/verify_reader_silo_real_paths.php](tools/verify_reader_silo_real_paths.php): read-only regression on actual game-specific Unreal, UT99, Unreal II snapshot builders, run only after a parser change.\n- [tools/check_reader_routes.php](tools/check_reader_routes.php): focused five-engine PHP dispatcher ownership regression, not native engine parity.
- [tools/check.py](tools/check.py): no-engine-run schema, source-path and archived-SHA validator.

All historic test scripts, results and notes are under [archive/testing-legacy-2026-11-02](../archive/testing-legacy-2026-11-02/README.md); old dashboards and journals no longer determine current status.

A PASS needs applicable Epic source, a genuine/authoritative oracle for the relevant result, current code identity, and exact end-to-end persisted/visible outcome. SOURCE_NA needs evidence that this precise branch cannot apply. Missing load/mount context is UNKNOWN or AMBIGUOUS, never confidently MISSING.
