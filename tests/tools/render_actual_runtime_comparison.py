#!/usr/bin/env python3
"""Visualize retained UE1-UE5 Xdebug + Epic source evidence without re-running tests.

Generated HTML/SVG stays on DEV (Graphify), not in GitHub or production.
Solid arrows mean proven PHP processing sequence; dashed arrows mean a static
Epic source reference or a NOT EXECUTED downstream step, never an engine call.
"""
from __future__ import annotations

import argparse
import hashlib
import html
import json
import subprocess
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
DEFAULT_EVIDENCE = REPO / "tests/evidence/ue1-ue5-original-runtime-process-trace-20261103.json"
DEFAULT_OUTPUT = Path(r"C:\Users\arden\Graphify\unrealdb\graphify-out\process-ue1-ue5.html")
DEFAULT_DOT = Path(r"C:\Program Files\Graphviz\bin\dot.exe")


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def dotq(value: object) -> str:
    return json.dumps(str(value), ensure_ascii=True)


def display(value: object) -> str:
    return html.escape(str(value), quote=True)


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument("--evidence", type=Path, default=DEFAULT_EVIDENCE)
    ap.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    ap.add_argument("--dot", type=Path, default=DEFAULT_DOT)
    args = ap.parse_args()
    evidence = json.loads(args.evidence.read_text("utf-8"))
    if evidence.get("schema") != "source-sha-pinned-actual-php-xdebug-original-game-package-traces-v1":
        raise RuntimeError("Wrong evidence schema; do not render stale/incompatible results")
    for filename, expected in evidence["production_code_sha256"].items():
        path = REPO / filename
        if not path.is_file() or sha256(path) != expected:
            raise RuntimeError(f"Production code changed since Xdebug evidence: {filename}")

    dep_path = REPO / "tests/evidence/ue1-ue5-dependency-selector-runtime-trace-20261103.json"
    dependency = json.loads(dep_path.read_text("utf-8"))
    if dependency.get("schema") != "uedb5-original-xdebug-provider-selection-and-engine-owned-baseline-v1":
        raise RuntimeError("Wrong UE1-UE5 dependency runtime-evidence schema")
    if dependency["original_pass1_evidence_sha256"] != sha256(args.evidence):
        raise RuntimeError("Earlier original package evidence changed since dependency tracing")
    if sha256(REPO/"catalog/src/Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php") != (
        dependency["UT2004_UE2_engine_ownership_source_analysis"]["post_fix_source_sha256"]
    ):
        raise RuntimeError("Changed dependency resolver cannot reuse earlier observed Xdebug evidence")
    dep_cases = dependency["actual_prd_pass2_read_only_provider_selector_cases"]
    if len(dep_cases) != 5 or any(c["sql_writes"] != 0 for c in dep_cases):
        raise RuntimeError("Incomplete or mutated dependency audit scope")
    website_path = REPO / "tests/evidence/ue1-ue5-website-dependency-readpath-runtime-trace-20261103.json"
    website = json.loads(website_path.read_text("utf-8"))
    if website.get("schema") != "uedb5-five-engine-original-website-readpath-xdebug-v1":
        raise RuntimeError("Wrong original-file website Xdebug read-path evidence schema")
    if len(website["trace_cases"]) != 5 or website["total_historical_v13_edges_excluded"] != 51:
        raise RuntimeError("Unexpected website original-file trace evidence coverage")
    endpoint_proof_path = REPO / "tests/evidence/ue1-ue5-json-endpoint-source-guard-20261103.json"
    endpoint_proof = json.loads(endpoint_proof_path.read_text("utf-8"))
    if endpoint_proof.get("schema") != "uedb5-json-uses-required-by-shared-source-scope-v1":
        raise RuntimeError("Wrong JSON endpoint source-scope correction proof schema")
    if endpoint_proof["previous_website_evidence_SHA256"] != sha256(website_path):
        raise RuntimeError("Earlier website Xdebug source proof changed after the correction")
    endpoint = "catalog/file-examine-dependencies.php"
    if (endpoint_proof["endpoint_before_SHA256_from_previous_evidence"]
            != website["runtime_source_sha256"][endpoint]):
        raise RuntimeError("Original HTTP endpoint source cannot be authenticated")
    revised_in_follow_on = {
        endpoint,
        "tests/tools/verify_five_engine_website_dependency_freshness.php",
    }
    for filename, expected in website["runtime_source_sha256"].items():
        actual = sha256(REPO / filename)
        if filename in revised_in_follow_on:
            # Preserve original trace-time hashes unchanged; the new source
            # refactor and additional regression are separately SHA-pinned.
            if actual != endpoint_proof["source_and_test_SHA256"][filename]:
                raise RuntimeError(f"Follow-on endpoint source changed: {filename}")
        elif actual != expected:
            raise RuntimeError(f"Website production read-path source has changed: {filename}")
    for filename, expected in endpoint_proof["source_and_test_SHA256"].items():
        if sha256(REPO / filename) != expected:
            raise RuntimeError(f"Corrected JSON endpoint/runtime source has changed: {filename}")
    zero_path = REPO / "tests/evidence/ue1-ue5-cross-game-zero-verified-source-guard-20261103.json"
    zero = json.loads(zero_path.read_text("utf-8"))
    if zero.get("schema") != "ue1-ue5-cross-game-zero-verified-source-guard-original-prd-xdebug-v1":
        raise RuntimeError("Incorrect source-corpus cross-game runtime evidence")
    if (zero["source"]["after_source_sha256"] != sha256(
        REPO / zero["source"]["file"]
    ) or zero["isolated_regression"]["source_sha256"] != sha256(
        REPO / zero["isolated_regression"]["script"]
    )):
        raise RuntimeError("Cross-game zero-file runtime proof source code has changed")
    if (zero["actual_PRD_read_only_execution"]["changed_outcome_cases"] != 1
        or zero["actual_PRD_read_only_execution"]["unchanged_source_stale_cases"] != 5
        or zero["target_fixture"]["original_verified_file_count"] != 0):
        raise RuntimeError("Cross-game zero-file proof no longer matches the original PRD scope")
    current_ue5_path = REPO / "tests/evidence/ue5/ue5-current-satisfactory-561-source-audit-20261103.json"
    current_ue5 = json.loads(current_ue5_path.read_text("utf-8"))
    if current_ue5.get("schema") != "UE5-current-Satisfactory-5.6.1-source-selected-original-archive-audit-v1":
        raise RuntimeError("Current Satisfactory original UE5.6 source evidence was not found")
    if current_ue5["original_UE5_TOC_and_Zen"]["Zen_packages_structurally_decoded"] != 27240:
        raise RuntimeError("Current original Satisfactory package count changed")
    if current_ue5["original_UE5_TOC_and_Zen"]["casefold_identical_native_package_names"] != 27240:
        raise RuntimeError("Current original Satisfactory native name comparison is incomplete")
    if current_ue5["code_change"]["sha256"] != sha256(REPO / current_ue5["code_change"]["module"]):
        raise RuntimeError("Current Satisfactory source-specific PHP reader has changed")
    if current_ue5["code_change"]["test_source_sha256"] != sha256(REPO / current_ue5["code_change"]["original_regression_test"]):
        raise RuntimeError("Current Satisfactory source-specific regression has changed")
    service_cases = evidence["real_service_traces"]
    direct_cases = evidence["direct_real_reader_traces"]
    if len(service_cases) != 7 or len(direct_cases) != 6:
        raise RuntimeError("Incomplete original Xdebug trace evidence: expected 7 service + 6 direct")
    direct_ue5 = [v for v in direct_cases if v["engine"] == "ue5"]
    if len(direct_ue5) != 1:
        raise RuntimeError("Expected exactly one UE5 direct source file trace")
    current_contracts = json.loads((REPO / "tests/contracts.json").read_text("utf-8"))
    blocked = [v["key"] for v in current_contracts["engine_contracts"] if v["status"] == "BLOCKED"]

    cases = list(service_cases)
    cases.append({
        "observed_path": "direct-UE5-583-classic",
        "engine": "ue5",
        "game_id": 14,
        "original_file_id": "installed Epic 5.8.3 asset",
        "source_policy_from_successful_run": "ue5-5.8.3-release-396c9f059903aed5fec78ecd3d437a40c6415368",
        "summary_names_imports_exports": {
            "names": direct_ue5[0]["names"], "imports": direct_ue5[0]["imports"],
            "exports": direct_ue5[0]["exports"],
        },
        "applicable_Epic_source_anchor_roles": ["ue5_583_summary", "ue5_583_object"],
        "xdebug_trace": {"sha256": direct_ue5[0]["raw_trace_sha256"]},
        "source_mode": "reader-only",
    })

    dep_rows=[]
    for c in dep_cases:
        outcomes=", ".join(f"{k}={v}" for k,v in c["baseline_outcomes"].items())
        reasons="; ".join(f"{k}: {v}" for k,v in c["baseline_reason_counts"].items())
        dep_rows.append(
            "<tr><td><strong>"+display(c["engine"])+"</strong></td><td>"+display(c["case"])+
            "</td><td>"+display(c["file_id"])+"</td><td>"+display(outcomes)+
            "</td><td>"+display(reasons)+
            "</td><td>Pass2 real selector + isolated baseline; no runtime mounts or SQL writes</td></tr>"
        )
    ue5_dep=dependency["UE5_classic_original_standalone_baseline"]
    dep_rows.append(
        "<tr><td><strong>UE5 classic</strong></td><td>Original installed 5.8.3 asset</td><td>not staged in PRD</td>"
        "<td>"+display(", ".join(f"{k}={v}" for k,v in ue5_dep["observed_baseline_outcomes"].items()))+
        "</td><td><strong>Unbound baseline: 10 'missing' labels are NOT verified missing packages</strong></td>"
        "<td>Standalone actual PHP resolver call, no runtime mount/Pass2</td></tr>"
    )
    web_rows = []
    for case in website["trace_cases"]:
        if (case["source_correct_policy_current"]
            or case["v14_current_edges_displayable"] != 0
            or not case["original_detail_read_rejected_stale"]):
            raise RuntimeError("Website source-read trace unexpectedly exposed stale dependency status")
        web_rows.append(
            "<tr><td><strong>" + display(case["engine"]) + "</strong></td>"
            "<td>" + display(case["original_game"]) + "</td>"
            "<td>" + display(case["verified_original_file_id"]) + "</td>"
            "<td>" + display(case["pre_existing_v13_edge_count"]) + "</td>"
            "<td>" + display(case["v14_current_edges_displayable"]) + "</td>"
            "<td>Stale publication explicitly rejected by actual PHP read service</td></tr>"
        )
    source_anchors = evidence["epic_source_anchors_by_role"]
    result = [
        "digraph UEProcess {",
        " graph [rankdir=LR, bgcolor=\"#fbfcfd\", compound=true, fontname=\"Arial\", splines=polyline, nodesep=0.19, ranksep=0.30, pad=0.24];",
        " node [fontname=\"Arial\", shape=box, style=\"rounded,filled\", fontsize=10, color=\"#7691a1\", fillcolor=\"#eaf2f9\", margin=\"0.10,0.06\"];",
        " edge [color=\"#647a8d\", arrowsize=0.55, penwidth=1.15, fontsize=8];",
    ]
    rows = []
    for num, case in enumerate(cases):
        ident = f"case{num}"
        name = case["observed_path"]
        mode = case.get("source_mode", "original verified PRD source, apply=false")
        count = case["summary_names_imports_exports"]
        label = f"{case['engine'].upper()} — {name.replace('service-', '').replace('direct-', '')}"
        step_names = [
            f"Original package\\nfile {case['original_file_id']}",
            ("Production Pass1\\nrunFile(false)" if mode != "reader-only" else "Standalone original\\nasset reader"),
            ("Game/profile source\\nselection (observed)" if mode != "reader-only" else "UE5.8.3 source\\nprofile (standalone)"),
            "Engine-owned reader\\n(observed constructor)",
            f"UEDB5 table snapshot\\n{count['names']} names / {count['imports']} imports / {count['exports']} exports",
        ]
        result.append(f"subgraph cluster_{ident} {{")
        result.append(f" label={dotq(label)}; labelloc=t; fontname=\"Arial\"; fontsize=11; color=\"#d0dce5\"; style=\"rounded\";")
        for i, title in enumerate(step_names):
            node = f"{ident}_n{i}"
            result.append(f" {node} [label={dotq(title)}];")
            if i:
                result.append(f" {ident}_n{i-1} -> {node};")
        ref_roles = case["applicable_Epic_source_anchor_roles"]
        lines = []
        for role in ref_roles:
            if role not in source_anchors:
                raise RuntimeError(f"Missing pinned Epic source role: {role}")
            s = source_anchors[role]
            lines.append(role.replace("_", " ") + " " + s["sha256"][:12])
        if lines:
            source_label = "Epic original C++ source\\n" + "\\n".join(lines)
            source_edge = "reference only"
            source_color = "#b9943b"
            source_fill = "#fff5d9"
        else:
            source_label = "Exact Epic build NOT PINNED\\nUnreal II v110 / licensee 2481"
            source_edge = "authority unavailable"
            source_color = "#ab5252"
            source_fill = "#ffefeb"
        result.append(
            f" {ident}_source [label={dotq(source_label)},"
            f'fillcolor="{source_fill}",color="{source_color}"];'
        )
        result.append(
            f' {ident}_n4 -> {ident}_source [style=dashed, label="{source_edge}", color="{source_color}"];'
        )
        result.append(
            f" {ident}_after [label=\"Pass1 SQL publication not run\\nWebsite read tracing is separate\",style=\"rounded,dashed\",fillcolor=\"white\",color=\"#a2a6ac\"];"
        )
        result.append(
            f" {ident}_n4 -> {ident}_after [style=dotted, label=\"not observed\",color=\"#a2a6ac\"];"
        )
        result.append("}")
        rows.append(
            "<tr><td><strong>" + display(case["engine"].upper()) + "</strong></td>"
            "<td>" + display(name) + "</td><td>" + display(case["original_file_id"]) + "</td>"
            "<td>" + display(case["source_policy_from_successful_run"]) + "</td>"
            "<td>" + display(count["names"]) + " / " + display(count["imports"])
            + " / " + display(count["exports"]) + "</td>"
            "<td>" + display("; ".join(ref_roles) if ref_roles else "UNPINNED — exact original build source") + "</td>"
            "<td>" + display(mode) + "</td></tr>"
        )
    result.append("}")
    args.output.parent.mkdir(parents=True, exist_ok=True)
    dot_path = args.output.with_suffix(".dot")
    svg_path = args.output.with_suffix(".svg")
    dot_path.write_text("\n".join(result) + "\n", encoding="utf-8")
    cp = subprocess.run([str(args.dot), "-Tsvg", str(dot_path), "-o", str(svg_path)],
                        capture_output=True, text=True)
    if cp.returncode:
        raise RuntimeError(f"Graphviz failed: {cp.stderr[:2000]}")
    blockers = ", ".join(blocked)
    story = [
        "<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\">",
        "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">",
        "<title>UE1–UE5 Original Xdebug Process Comparisons</title>",
        "<style>body{font:14px/1.55 system-ui,-apple-system,Segoe UI,sans-serif;color:#1e293b;background:#f8fafc;"
        "max-width:1440px;margin:24px auto;padding:0 22px}h1{font-size:24px}h2{font-size:17px;margin-top:28px}"
        "p{max-width:1000px}a{color:#0369a1}figure{overflow:auto;margin:20px 0;border:1px solid #dbe3ec;"
        "background:white;border-radius:9px;padding:10px}figure img{min-width:1050px;width:100%;height:auto}"
        "table{border-collapse:collapse;background:white;width:100%;font-size:12px}"
        "th,td{border-bottom:1px solid #e2e8f0;padding:9px;text-align:left;vertical-align:top}"
        "th{background:#f1f5f9;position:sticky;top:0}code{font-size:12px}small{color:#475569}</style>",
        "</head><body><p><a href=\"process-coverage.html\">All 32 contract cases</a> · "
        "<a href=\"process-comparison.html\">Earlier UE5 classic process comparison</a></p>",
        "<h1>UE1–UE5: actually executed UnrealDB paths versus Epic C++ sources</h1>",
        "<p>Observed on 3 November 2026. <strong>Seven</strong> genuine original PRD-package paths "
        "through the real Pass1 source migration service with apply=false, plus "
        "<strong>one</strong> original installed UE5.8.3 classic asset through the standalone "
        "reader. All five engines are represented. Dashed Epic nodes are "
        "<em>source-code references, not native runtime calls</em>.</p>",
        "<p>Solid arrows show observed PHP stage progression. The actual Xdebug source includes "
        "individual method calls and is SHA-256 pinned in the retained evidence. "
        "The displayed process is a simplified stage graph, not a complete call graph.</p>",
        "<figure><img src=\"" + display(svg_path.name) + "\" alt=\"Actual UnrealDB PHP stages, "
        "static Epic references, and unexecuted publishing steps across UE1 through UE5\"></figure>",
        "<h2>Original package and source-profile comparison</h2>",
        "<div style=\"overflow:auto\"><table><thead><tr><th>Engine</th><th>Observed trace</th>"
        "<th>Original file ID</th><th>Source policy</th><th>Names / imports / exports</th>"
        "<th>Pinned Epic C++ source</th><th>Execution scope</th></tr></thead><tbody>",
        *rows,
        "</tbody></table></div>",
        "<h2>Actual source-scoped provider selection and dependency baselines</h2>",
        "<p><strong>Five</strong> original PRD package paths executed the production "
        "Uedb5GameDependencyPassService::runFile(false, skipPreflight=true) selector under "
        "an enforced SQL read-only transaction, plus isolated no-provider classic dependency "
        "baselines. UE5's original installed asset executed its standalone "
        "VerifyImport resolver, with no active game14 staging.</p>",
        "<div style=\"overflow:auto\"><table><thead><tr><th>Engine</th><th>Observed trace</th>"
        "<th>File ID</th><th>Observed baseline status counts</th><th>Interpretation / reason</th>"
        "<th>Execution scope</th></tr></thead><tbody>",
        *dep_rows,
        "</tbody></table></div>",
        "<p><strong>Confirmed UE2 UT2004 v99 ownership correction:</strong> its five import "
        "rows previously reported a UE1 source-implementation-unavailable reason. The "
        "source-selected UE2 runtime ownership now reports UE2 source implementation "
        "unavailable instead, still unresolved. Original package bytes and the staged "
        "container SHA-256 are unchanged. Epic UT2004 v3186 supports loading package "
        "v99 but does not prove the exact original author build, VerifyImport result "
        "or runtime mount. Four source-context negatives passed.</p>",
        "<p>The <strong>ten UE5 baseline 'missing'</strong> labels were obtained with "
        "<em>no providers supplied</em>; they are not a native runtime missing verdict. "
        "This batch did not call the writer, rebuild or SQL publisher and it "
        "cannot close the five website dependency contracts. "
        "SHA-pinned evidence: <code>tests/evidence/ue1-ue5-dependency-selector-runtime-trace-20261103.json</code>.</p>",
        "<h2>Production website dependency read paths — actual Xdebug calls</h2>",
        "<p>Five original PRD-staged files were traced through the actual SQL freshness "
        "predicate and current-edge join, plus the production file-info dependency "
        "read service. <strong>All 51 legacy v13 dependency-edge rows</strong> for these "
        "files were excluded from current v14 result views, and detail reads "
        "rejected stale policy explicitly. The session was enforced read-only "
        "and rolled back. These are actual PHP/SQL read-service calls, "
        "<strong>not HTTP-rendered browser requests</strong>.</p>",
        "<div style=\"overflow:auto\"><table><thead><tr>"
        "<th>Engine</th><th>Original game</th><th>File ID</th>"
        "<th>Historical v13 edges</th><th>Visible v14 edges</th><th>Detail behavior</th>"
        "</tr></thead><tbody>",*web_rows,"</tbody></table></div>",
        "<p><strong>Isolated positive and negative admission check:</strong> "
        "six correctly published v14 rows across UE1–UE5 (including UE2-owned "
        "UT2004) were admitted, while 24 v13, payload-mismatched, unpublished "
        "or unverified rows were rejected. The Uses tab now labels stale "
        "dependency counts as <em>unverified</em>, not zero. "
        "PRD game14 still has no verified original file for live website testing.</p>",
        "<p>Proof: <code>tests/evidence/ue1-ue5-website-dependency-readpath-runtime-trace-20261103.json</code>"
        " SHA-256 <code>" + sha256(website_path) + "</code>. "
        "All five website acceptance contracts remain BLOCKED until newly "
        "source-correct dependency publication and HTTP-rendered mixed outcomes.</p>",
        "<h2>JSON Uses / Required By: cross-game source guard correction</h2>",
        "<p>The JSON endpoint's previous source-policy CASE had an unsafe "
        "<em>ELSE v13</em> fallback for an unsupported referring engine profile. "
        "An isolated positive/negative regression reproduced the stale reverse "
        "link and confirmed that the corrected shared gate excludes it, "
        "while retaining <strong>two</strong> valid current cross-game links. "
        "The fix uses the same v14 policy/payload admission for the target file, "
        "Uses and Required By, without changing UE1–UE5 readers.</p>",
        "<p>The new SQL query shapes were exercised against <strong>six</strong> "
        "real PRD file records in a read-only transaction, excluding <strong>578</strong> "
        "historical v13 edges from current relationships. "
        "Automated HTTP requests received the site's configured crawler-guard "
        "<strong>403</strong> before database access; that guard was not changed "
        "or bypassed. Therefore these are executed MySQL statements and "
        "source-linked Xdebug evidence, <strong>not HTTP-rendering proof</strong>.</p>",
        "<p>New source-corrected endpoint evidence: "
        "<code>tests/evidence/ue1-ue5-json-endpoint-source-guard-20261103.json</code>"
        " SHA-256 <code>" + sha256(endpoint_proof_path) + "</code>.</p>",
        "<h2>Cross-game zero-source correction — original UE5 PRD game</h2>",
        "<p><strong>Actual verified defect:</strong> PRD game14 (UE5) has "
        "<strong>zero original verified files</strong>. Previously the cross-game "
        "read model treated the resulting zero stale-file count as complete and "
        "returned an empty result, wrongly implying no missing dependencies. "
        "The corrected model rejects that target as <strong>source unavailable</strong>. "
        "A live read-only PHP/MySQL replay and Xdebug call trace show exactly one "
        "changed outcome across six games; the five existing stale game rejections "
        "are unchanged.</p>",
        "<p><strong>Isolated positive/negative controls:</strong> no original UE5 "
        "files is unavailable; UE3 stale v13 remains unavailable; UE1 with one "
        "verified current v14 publication and zero missing rows legitimately "
        "returns an empty result. No PRD database writes, new native Epic execution "
        "or new acceptance contract ID. "
        "Proof <code>tests/evidence/ue1-ue5-cross-game-zero-verified-source-guard-20261103.json</code>"
        " · SHA-256 <code>" + sha256(zero_path) + "</code>.</p>",
        "<h2>Current Satisfactory 1.2: original UE5.6.1 Zen source audit</h2>",
        "<p>DEV's current <code>D:\\UE5\\Paks</code> source was decoded independently of earlier "
        "Satisfactory revisions. All <strong>27,240</strong> current original Zen package "
        "headers and names parsed; <strong>27,240/27,240</strong> names match Epic UnrealPak's "
        "native listing after FName case folding and verified plugin mount names. "
        "The companion pak has <strong>4,761</strong> native file entries. "
        "The global ScriptObjects chunk has <strong>54,968</strong> diagnostic entries, "
        "which are not a live runtime registry.</p>",
        "<p>The correctly selected base engine source is Epic <strong>5.6.1-release</strong> "
        "(separate from installed 5.8.3). Its <code>FZenPackageSummary::CookedHeaderSize</code> "
        "at byte offset 20 is named <code>_Unused</code> in 5.8.3. "
        "The PHP reader now preserves the original serialized value without assuming "
        "either release. <strong>66</strong> imported-package references to "
        "<strong>26</strong> nonlocal FPackageIds are not classified missing "
        "without an authenticated mounted package store.</p>",
        "<p><strong>Outstanding source-labelled publication:</strong> existing Zen "
        "SOURCE_POLICY still says 5.8.3 and needs Epic 5.6.1 selection for this build. "
        "Game-studio private code is NOT a prerequisite for package header/table "
        "parity unless genuine bytes demonstrate a serialization divergence. "
        "Authoritative mount context and v14 PRD website publication remain separate "
        "requirements; no PRD game14 files are published. "
        "The five existing website blockers remain open. Proof: "
        "<code>tests/evidence/ue5/ue5-current-satisfactory-561-source-audit-20261103.json</code>"
        " SHA-256 <code>" + sha256(current_ue5_path) + "</code>.</p>",
        "<h2>Independent comparisons and exclusions</h2>",
        "<p><strong>UE2 Unreal II v69:</strong> Original file #149508 and the selected 2000-12-09 "
        "linker match earlier accepted original-byte comparisons; this runtime run observed "
        "97 names, 14 imports, and 67 exports. <strong>UE4 UT4 v383:</strong> original #1335845 "
        "observed 65 / 10 / 4, matching independent decoding of that exact original file "
        "(including export size/offset). <strong>UE5 classic:</strong> the original "
        "BaseFlattenMaterial asset was already independently loaded by an actual 5.8.3 Epic "
        "commandlet; this batch did not rerun it.</p>",
        "<p><strong>Unresolved or excluded:</strong> UE1 UT99 source includes partial-public 430 "
        "policy and 432 header references, not a new complete compiled-runtime differential. "
        "UE2 v110 original licensee-build provenance is not pinned. UT2004 v99 used "
        "UE2-owned compatibility but did not test native VerifyImport. UE3's small traced "
        "fixture is outside the earlier independent-byte sample. UE5 is reader-only "
        "here; Zen/IoStore was not retraced. No new mount-order, database Pass2 publisher "
        "or authenticated website operations were executed.</p>",
        "<p><strong>Existing blocked contract cases are unchanged:</strong> "
        + display(blockers) + ". Source-level availability does not imply current "
        "v14 PRD dependency publication.</p>",
        "<p><small>Source: <code>tests/evidence/ue1-ue5-original-runtime-process-trace-20261103.json</code>"
        " · Evidence SHA-256 <code>" + sha256(args.evidence) + "</code>"
        " · UI generated offline; no network operations or product-code changes.</small></p>",
        "</body></html>",
    ]
    args.output.write_text("\n".join(story) + "\n", encoding="utf-8")
    print("ACTUAL_RUNTIME_EPIC_SOURCE_PROCESS_COMPARISON_CREATED")
    for p in (args.output, dot_path, svg_path):
        print(str(p), "bytes", p.stat().st_size, "sha256", sha256(p))
    print("cases", len(cases), "blocked_remain", blocked)


if __name__ == "__main__":
    main()
