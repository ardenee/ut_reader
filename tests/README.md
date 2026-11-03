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

## Local process-level source/runtime comparisons (DEV, 2026-11-03)

Tools installed on game-amd: Graphviz 16.1.0 (`C:\Program Files\Graphviz\bin\dot.exe`), Doxygen 1.18.0 (`C:\Program Files\doxygen\bin\doxygen.exe`) and Xdebug 3.5.3 for PHP 8.5 TS VS17, loaded by an explicit CLI-only `-d zend_extension=...` argument. Neither Apache PHP configuration nor PRD is modified. Commercial SciTools Understand is **not** installed.

The first source-matched process is UE5 classic physical provider selection (case `ue5-mount-provider`). An isolated original-assets PHP script in `C:\Temp\UnrealDB\UE5\verify_ue5_classic_no_mount_original_pair_20261103.php` processed genuine `BaseFlattenMaterial.uasset` and `BaseFlattenDiffuseMap.uasset`. Its Xdebug trace is `C:\Temp\UnrealDB\process-tracing\ue5_original_classic.xt`; the test result is `ue5-run-result.json`. The bounded public audit evidence is `evidence/ue5/ue5-classic-mount-provider-process-audit-20261103.json`. The original source is under `L:\Source\Engine\UE5\UE 5.8.3`. Doxygen generated static C++ call-graphs in `C:\Temp\UnrealDB\process-tracing\doxygen-ue5-classic\html`.

To regenerate only the *visualization of retained evidence* (not repeat the test), from the repository root on DEV:

```powershell
python tests/tools/process_parity_graph.py --trace 'C:\Temp\UnrealDB\process-tracing\ue5_original_classic.xt' --result 'C:\Temp\UnrealDB\process-tracing\ue5-run-result.json' --output 'C:\Users\arden\Graphify\unrealdb\graphify-out\process-comparison.html' --evidence-output 'tests/evidence/ue5/ue5-classic-mount-provider-process-audit-20261103.json'
python tests/tools/render_process_coverage.py --output 'C:\Users\arden\Graphify\unrealdb\graphify-out\process-coverage.html'
```

On the DEV desktop run `C:\Users\arden\Graphify\Start-Dashboard.ps1` and open `http://127.0.0.1:8765/`. The UE5 comparison and process-coverage links are local-only dashboard pages. All full traces and proprietary Epic Doxygen HTML remain **outside GitHub and PRD**.

**Evidence classes are different:** Graphify/Doxygen represent static possible calls. Xdebug represents functions actually invoked in the isolated UnrealDB PHP scenario. An Epic C++ source anchor is not proof that a compiled Epic runtime traversed that branch. No native UE5 classic mounted-session observation was captured for this case; it remains BLOCKED pending authoritative runtime evidence and completion of SQL/UI consumer verification. Previous native Zen evidence remains valid only for its original scope. The existing contract register has been reopened accordingly; do not create parallel test IDs.
