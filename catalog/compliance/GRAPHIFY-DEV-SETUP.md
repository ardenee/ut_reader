# Graphify on DEV — local code intelligence pilot

Updated: 2026-10-22. Owner machine: `game-amd` (`192.168.0.51`).
Status: **INSTALLED AND VERIFIED**. This is an OPTIONAL development/search tool. Epic source remains the sole Unreal format behavioral authority. Graphify may omit macro-expanded paths, conditional code, and custom archive semantics; never treat a graph link as proof of file compatibility.

## Local installation

- Free/local Graphify `graphifyy==0.9.93`, Apache-2.0. No Graphify Cloud or API keys configured.
- Windows `uv==0.13.5` via WinGet; Python 3.13 uv-managed user tool environment.
- Installed extras: `mcp`, `sql` (`tree_sitter_sql`).
- Executables: `C:\Users\arden\.local\bin\graphify.exe` and `graphify-mcp.exe`.
- User PATH includes `C:\Users\arden\.local\bin` (new terminal windows inherit it).
- No running service, open listening port, or Desktop Commander replacement. MCP tested on-demand using local **stdio**, not exposed to the network.
- Refresh helper: `C:\Users\arden\Graphify\Index-Code.ps1`.
- End-to-end MCP test: `C:\Users\arden\Graphify\Test-MCP.py`.
- All generated indexes, including metadata and rendered HTML, live **outside the Git checkout** under `C:\Users\arden\Graphify\`, not PRD.

## Verified local graphs and interactive dashboard (2026-10-22)

| Graph | Source path | Graph JSON | AST extraction | Result |
|---|---|---|---|---|
| `unrealdb` | `C:\Users\arden\source\repos\ut_reader` | `C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json` | 1,727 initial code files; incremental refresh after SQL dependency added | 10,809 nodes / 22,747 edges / 857 communities, 12,440,439 JSON bytes (refreshed after commit `a96e668a`) |
| `ue1-v0867` | `L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src` | `C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json` | 237 code files | 9,659 nodes / 15,888 edges / 459 communities, 6,360,278 JSON bytes |

All outputs contain `graph.html` as an aggregated browser-viewable community graph (generated with `graphify export html --node-limit 2400`). The complete individual nodes remain available in `graph.json` and via CLI/MCP.

**Interactive dashboard with Add & scan:** open **http://127.0.0.1:8765/** on DEV (or the `Graphify Dashboard` desktop shortcut). Enter a DEV folder (for example `L:\Source\Engine\UE1`) and optionally a project name; click **Add & scan**. A local Python service runs Graphify's existing code-only index script (four workers), writes STARTED/PASS/FAIL to `C:\Users\arden\Graphify\Dashboard-Index-Journal.jsonl` and updates the dashboard automatically. Only one graph scans at a time; per-project output is saved as `scan.log`. The service listens only on 127.0.0.1:8765, checks local Origin and a per-process CSRF token, and does not accept raw commands. A Windows user Startup shortcut starts the service on login; run `C:\Users\arden\Graphify\Start-Dashboard.ps1` to restart it if necessary. The old static `C:\Users\arden\Graphify\index.html` still works for browsing, but only the local HTTP dashboard can launch scans. `Build-Dashboard.py` automatically scans saved `graph.json` files and `project.json`; `Index-Code.ps1` updates the HTML view and dashboard after successful extraction.

**13 currently indexed projects:** UnrealDB, UE1 v0.82, v0.83, v0.84a, v0.86x, v0.867, v1.200, v1.224 (INCOMPLETE), v1.227, UT v400, UT v432, the separate `L:\Source\Engine\UE1\Source` tree (version unknown), and **`ue1-full-root`** (`L:\Source\Engine\UE1`, created by a real dashboard Add & scan acceptance test). The full root overlaps the unversioned source tree. **13 graphs currently total 136,091 nodes and ~236 MiB** of local graph/cache/HTML storage. Counts include duplicate nodes in overlapping index roots and must never be interpreted as unique source symbols. The local dashboard is the authority for current index metrics. All nine additional Games version trees plus the unversioned Engine/Source tree completed code-only indexing and HTML export successfully. Some source files could not be fully parsed; see limitations.

**Reusable local scripts:** `C:\Users\arden\Graphify\Build-Dashboard.py`, `Local-Dashboard-Server.py`, `Start-Dashboard.ps1`, `Index-Code.ps1`, `Index-UE1-Sources.ps1`, and `Test-MCP.py`; versioned backups live under repository `tools/graphify/`. `Index-UE1-Sources.ps1` preserves an append-only `UE1-Index-Journal.jsonl` with STARTED and terminal PASS/FAIL for each graph, and skips graphs already saved. For any new project, use `Index-Code.ps1 -Name <new-id> -Source <directory> -Workers 4`; its dashboard entry is then added without further manual page editing. No Graphify Cloud account or *network-facing* HTTP service is involved; the optional interactive dashboard uses loopback HTTP on DEV only.

## Master projects and scoped code search (2026-10-22)

Graphify's **local dashboard** at `http://127.0.0.1:8765/` now has master projects. This is our local grouping layer over Graphify, not a Graphify Cloud feature. Existing UnrealDB and UE1 graphs belong to **UnrealDB & Unreal Engine** (`unrealdb`); a separate **Other Projects** (`other`) master is created for unrelated work. Use **Create master** for another project grouping, select that master, then **Add & scan** a folder. All new child graphs store an explicit `master` ID in their `project.json`. New non-UnrealDB child storage IDs are prefixed with the master ID, avoiding simple collisions across unrelated projects.

The browser search and displayed graph totals operate on **only the selected master's children**. The selected master is stored in `?master=<id>` in the URL. Master definitions are local in `C:\Users\arden\Graphify\masters.json`; no private graph JSONs are added to the public repository.

**For assistant/CLI graph searches**, do not query every graph globally. Use the scoped `Query-Master.py` launcher, with an explicit master and optionally one child; Graphify's underlying MCP still takes a *specific graph path*, not a composite master ID:

```powershell
python "C:\Users\arden\Graphify\Query-Master.py" --master unrealdb --child ue1-v0867 --query "Inc/UnClass.h::UClass" --budget 500
python "C:\Users\arden\Graphify\Query-Master.py" --master other --query "MySymbol" --budget 500
```

Omitting `--child` searches only graphs assigned to that master. Individual Graphify MCP sessions can be launched with a single child `--graph` file path, avoiding unrelated groups. Master creation, membership validation and cross-master query blocking were exercised in `tools/graphify/Test-Master-Projects.py` (PASS). Existing graphs are preserved without reindexing.

## Usage from any PowerShell terminal on DEV

```powershell
$g = "$env:USERPROFILE\.local\bin\graphify.exe"
& $g query 'CatalogUE1OriginalUnrealfileDatabaseDataReader' --graph 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json' --budget 1000
& $g query 'UClass SerializeHeader FProperty' --graph 'C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json' --budget 1000
& $g god-nodes --graph 'C:\Users\arden\Graphify\ue1-v0867\graphify-out\graph.json' --top 10
Start-Process 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.html'
```

Refresh either graph (local code-only AST, no external model/API):

```powershell
& 'C:\Users\arden\Graphify\Index-Code.ps1' -Name unrealdb -Source 'C:\Users\arden\source\repos\ut_reader' -Workers 4
& 'C:\Users\arden\Graphify\Index-Code.ps1' -Name ue1-v0867 -Source 'L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src' -Workers 4
```

For another project, choose a new `-Name` and source directory. Indexes are manual/incremental, not continuously updated. Regenerate HTML after significant graph changes:

```powershell
& $g export html --graph 'C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json' --node-limit 2400
```

## MCP (separate from Desktop Commander)

The local executable `C:\Users\arden\.local\bin\graphify-mcp.exe` supports stdio. An MCP client can launch it with these arguments:

```text
--graph C:\Users\arden\Graphify\unrealdb\graphify-out\graph.json
```

Verification was performed by `Test-MCP.py` using an MCP Python client via stdio: initialized `graphify 0.9.93`, discovered **10 tools** (`query_graph`, `get_node`, `get_neighbors`, `get_community`, `god_nodes`, `graph_stats`, `shortest_path`, `list_prs`, `get_pr_impact`, `triage_prs`), and successfully invoked `graph_stats`. Run again:

```powershell
& "$env:APPDATA\uv\tools\graphifyy\Scripts\python.exe" 'C:\Users\arden\Graphify\Test-MCP.py'
```

**This does not automatically connect Graphify as a first-class ChatGPT plugin.** Existing ChatGPT sessions can run `graphify` on DEV through Desktop Commander. The direct local MCP endpoint can be configured separately in an MCP-capable client. Do not expose HTTP MCP on the LAN or Internet without explicit authentication and TLS.

## Limitations and distinct source findings

- Original Unreal v0.867 uses non-UTF8 code and extensive C++ macros; Graphify reported **41 files with syntax/extraction issues**, and some may be only partially parsed. Use `rg`, compiler probes and actual Epic source for verification.
- Graphify located a stray Desktop Commander output line in historical `verify-uedb5-compact-v2-publisher.php`. Syntax was corrected and both DEV/PRD lint passed; the seven-game test was then **retired on 2026-10-22** after confirming no automated callers and that its pinned live fixtures are unavailable in the configured test database. Replacement isolated V5 checks passed (see `specs/uedb5-migration-validation.md`). **The `ue_uedb5_files` table and production compact publisher remain active, not obsolete.** Git history retains the removed script.
- The initial code-only UnrealDB scan skipped 7 SQL files without the optional grammar; `graphifyy[mcp,sql]` is now installed and the incremental refresh completed successfully.
- Graphify node/edge counts indicate searchable coverage, **not** UnrealDB serializer correctness. No O06 acceptance status was changed due to Graphify.
- Developer graph outputs may contain snippets or metadata from proprietary Epic source. Keep these files on DEV; do not push generated `graph.json` or `graph.html` to public GitHub or external Graphify Cloud.

## Windows Network Shortcut / WSL case-sensitive index fix (2026-10-22)

MeMeMe submitted using Windows Network Shortcuts `target.lnk` or the direct WSL UNC path. Two independent failures were reproduced and fixed locally on DEV.

1. Graphify 0.9.93 `graphify/cache.py::_normalize_path` used `os.path.normcase` on case-sensitive WSL UNC paths, lowercasing e.g. `Console/Commands/ArchiveLocationShards.php` and preventing physical `file_hash`. Saved the pre-patch installed package as `cache.py.original-0.9.93`; normalize normal Windows paths as before and preserve physical casing for `\\wsl.localhost\...` and `\\wsl$\...` paths. This is a LOCAL uv-installed package adjustment which may be replaced by a future Graphify upgrade.
2. `C:\Users\arden\Graphify\Local-Dashboard-Server.py` now resolves Windows Network Shortcuts' `target.lnk` through `Resolve-Network-Shortcut.ps1` only when the source lies inside Windows' official Network Shortcuts directory; the scanner metadata stores the real WSL directory, not the .lnk folder. `Index-Code.ps1` also supports shortcut paths for CLI use and judges external commands by exit code, not benign stderr warnings. A warning about a large non-UTF-8 `fulldump.sql` previously aborted the wrapper despite being informational.

End-to-end proof: the existing MeMeMe project was indexed through POST `/api/add` using the same shortcut source, API 202, index event STARTED then PASS(exit 0), graph JSON 3,033,358 bytes with **3,127 nodes and 5,756 links**, HTML browser endpoint HTTP 200. 543 source code files discovered. `C:\Users\arden\Graphify\Test-WSL-Shortcut-Index.py` asserts physical WSL file SHA-256, standard Windows normalization, shortcut target resolution, canonical project source, graph stats and HTTP/index journal status; all PASS. Index output is local-only; no Epic engine source or UnrealDB package parser changed by this tooling fix.

If graphifyy is upgraded, recheck the installed `cache.py` WSL path behavior and rerun the local test before depending on WSL scanning. These Graphify results are advisory to code navigation, not proof of Unreal byte-format parity.

## Descriptive offline community/node labels for local Graphify (2026-10-22)

Graphify 0.9.93 FREE local exporter supports `graphify export html --graph <graph.json> --labels <labels.json> --node-limit 2400`. Previously `Index-Code.ps1` omitted `--labels`, so the aggregated community views showed only `Community 0`, `Community 1`, etc. This is not a subscription or paid-SaaS limitation.

Implemented DEV-local, LLM-free, deterministic presentation naming:
- `C:\Users\arden\Graphify\Build-Community-Labels.py` reads each original `graph.json`, calculates actual graph degree and chooses the highest-degree recognizable **source-backed class/function/file** in each community, avoiding ambiguous one-letter minifier functions and source-less external references where appropriate. Uses original node names; repeated community names receive `#2`, `#3`, etc. Writes `graphify-out\.graphify_display_labels.json` for Graphify's supported native `--labels` option.
- `C:\Users\arden\Graphify\Disambiguate-Graph-HTML.py` handles graphs under the visualization limit, which display *individual* original nodes rather than communities: only visually repeated names get numeric suffixes. Native HTML rendering preserves source node IDs; it does NOT rewrite the source graph.
- `C:\Users\arden\Graphify\Index-Code.ps1` now runs both naming steps automatically after extraction/export, before dashboard refresh.
- `C:\Users\arden\Graphify\Refresh-Community-Labels.ps1` regenerates all local graph HTML views WITHOUT rescanning Epic or project source files and checks SHA-256 of original graph JSON before/after each project; writes PASS/FAIL after each project to `Community-Labels-Journal.jsonl`.
- `C:\Users\arden\Graphify\Test-Community-Labels.py` checks every project's community labels, stable identities, duplicate disambiguation, valid embedded JS node JSON, and latest per-project journal PASS; no API key or cloud request is used.

Accepted local proof: **27/27 indexed project HTML views PASS** (15 aggregated community diagrams, 12 individual-node views), **1,049 otherwise identical individual node labels disambiguated** without rewriting graph.json. Representative cases: MeMeMe `Community 0` becomes `TestCase` (source-backed); `AdminController` / `AdminController #2`; UnrealDB `Community 0` becomes `PdoCatalogSourcePathStore`; minifier-generated `x()` communities can be identified as `files/jquery.qtip-2012-04-26.min.js`. Three dashboard HTML endpoints return HTTP 200. A fresh incremental scan on `archive_backend_test` (18 source nodes) confirmed the future-indexing path runs the label builder, HTML exporter and individual name validator successfully.

To regenerate and verify after updating Graphify:
```powershell
& 'C:\Users\arden\Graphify\Refresh-Community-Labels.ps1'
& python 'C:\Users\arden\Graphify\Test-Community-Labels.py'
```
Keep the full `graph.json` and source content on DEV, not in the public repository or PRD website. This is **visual display identification only**; Epic-source serialization correctness is independent and O06 remains open.

## HTML-only viewer slider, 2,000–5,000 nodes (2026-10-22; supersedes previous unlimited-HTML export)

The dashboard on DEV at http://127.0.0.1:8765/ now has a visible **HTML viewer node limit** slider directly below **Master project**, ranging from 2,000 to 5,000 in increments of 500. **Apply to HTML viewers** saves the choice and starts an asynchronous, HTML-only rebuild of existing views. Moving the slider alone does not change anything. The default/applied setting is 5,000 nodes.

The slider changes Graphify's HTML exporter threshold ONLY. Full graph.json content, all original source symbols, relationships, node IDs, Graphify MCP output, Query-Master.py access and all source files remain unchanged. At/below the limit, individual graph nodes have original names (with #2/#3 suffixes added ONLY to duplicate HTML display labels). Above the limit, Graphify's own HTML exporter shows descriptive communities; the indexed full graph remains intact. Neither source names nor graph.json are overwritten or renamed.

**Configuration and automation:** local-only C:\Users\arden\Graphify\viewer-settings.json stores node_limit=5000. Index-Code.ps1 reads this setting on future scans and passes it ONLY to graphify export html --node-limit. Refresh-Community-Labels.ps1 -NodeLimit 5000 regenerates existing HTML without source rescanning, journaling PASS/FAIL and source-graph SHA-256 after EACH project. The old graph-communities.html filename now uses the SAME selected cap as graph.html and is a compatibility alias, not an unlimited bypass. GET /api/viewer reports current limit and job state; authenticated POST /api/viewer validates 2000..5000 in steps of 500, CSRF token and local Origin, and disallows overlapping scan/update jobs. Dashboard displays STARTED/PASS/FAIL; logs in viewer-refresh.log.

**Acceptance evidence, 2026-10-22:**
- Live local dashboard HTTP 200 with real slider; JavaScript syntax PASS. API minimum 2000, maximum 5000, step 500, default 5000.
- Invalid values 1999, 5001, 4250, string and boolean rejected HTTP 400; unauthorized/missing Origin rejected HTTP 403; valid POST 5000 accepted HTTP 202, job STARTED then PASS exit 0.
- 27/27 existing HTML-only exports at limit 5000 PASS: 13 full-detail views and 14 descriptive community views. Logs record each individual PASS, plus overall 27/27.
- At limit 2000, 3,127-node MeMeMe graph displays 361 communities. At limit 5000, all 3,127 source nodes display individually. The original graph.json SHA-256 is unchanged.
- Test-Viewer-Limit.py verify: full original graph JSON SHA-256, individual/aggregated node counts and original identities **27/27 PASS**. Test-Community-Labels.py: original IDs and visual name disambiguation **27/27 PASS**.
- No UE1 O06 serializer acceptance or modification; Epic source and native behavior remain authoritative.

**Repository scripts:** tools/graphify/Build-Dashboard.py, Local-Dashboard-Server.py, Index-Code.ps1, Refresh-Community-Labels.ps1, Build-Community-Labels.py, Disambiguate-Graph-HTML.py, Test-Viewer-Limit.py, Test-Community-Labels.py. Local settings, generated graphs and source contents stay on DEV; no Graphify SaaS required.
