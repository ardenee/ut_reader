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
            f" {ident}_after [label=\"SQL publish / website\\nNOT EXECUTED in this trace\",style=\"rounded,dashed\",fillcolor=\"white\",color=\"#a2a6ac\"];"
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
