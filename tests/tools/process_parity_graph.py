#!/usr/bin/env python3
"""Process-level PHP trace / Epic source comparison, without parity inflation.

Only Xdebug calls are considered executed. Epic source symbols are verified
textually; they are NOT evidence that a native engine session ran.
Generated graphs are DEV-only and must not be put in the public repository.
"""
from __future__ import annotations
import argparse
import collections
import hashlib
import html
import json
import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path

REPO = Path(__file__).resolve().parents[2]
EPIC = Path(r"L:\Source\Engine\UE5\UE 5.8.3\Engine\Source\Runtime\CoreUObject\Private\UObject")
SOURCES = {
    "UE5.8.3 UObjectGlobals": EPIC / "UObjectGlobals.cpp",
    "UE5.8.3 LinkerLoad": EPIC / "LinkerLoad.cpp",
}
ANCHORS = [
    ("package-name search", "UE5.8.3 UObjectGlobals", "FPackageName::SearchForPackageOnDisk(", 1810),
    ("mounted path conversion", "UE5.8.3 UObjectGlobals", "FPackagePath::TryFromMountedName(", 1820),
    ("original VerifyImport", "UE5.8.3 LinkerLoad", "FLinkerLoad::VerifyImport(", 3670),
    ("VerifyImportInner", "UE5.8.3 LinkerLoad", "FLinkerLoad::VerifyImportInner(", 3975),
    ("load verified package", "UE5.8.3 LinkerLoad", "Package = LoadPackageInternal(", 4070),
    ("load source linker", "UE5.8.3 LinkerLoad", "Import.SourceLinker = GetPackageLinker(", 4140),
]
STAGES = [
    ("parse", ("UnrealPackageReader5->",)),
    ("snapshot", ("Uedb5Ue5ClassicSnapshotBuilder::",)),
    ("container", ("Uedb5MetadataSnapshotWriter->", "Uedb5MetadataReader->")),
    ("provider", ("PdoUedb5PhysicalProviderSelector->",)),
    ("verify", ("Uedb5Ue5ClassicVerifyImportResolver::",)),
    ("rebuild", ("Uedb5DependencyRebuilder->",)),
]
EXTRA = ["Uedb5SqlProjectionContract::"]
ROW = re.compile(r"^(?P<indent>\s*)\d+\.\d+\s+\d+\s+->\s+(?P<fn>[^()\s]+)\(")
SRC = re.compile(r"\)\s+(?P<file>[A-Z]:\\.+?):(?P<line>\d+)\s*$", re.I)

def sha(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()

def selected_stage(method: str):
    for stage, patterns in STAGES:
        if any(method.endswith(p) or p in method for p in patterns):
            return stage
    if any(p in method for p in EXTRA):
        return "misc"
    return None

def parse_xdebug(path: Path):
    functions = collections.Counter()
    stage_calls = collections.Counter()
    edges = collections.Counter()
    sites = {}
    stack = []
    seen = 0
    with path.open("r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            m = ROW.match(line)
            if not m:
                continue
            seen += 1
            depth = len(m["indent"])
            method = m["fn"]
            stage = selected_stage(method)
            while stack and stack[-1][0] >= depth:
                stack.pop()
            parent = next((entry[1] for entry in reversed(stack) if entry[1]), None)
            if stage:
                functions[method] += 1
                stage_calls[stage] += 1
                if parent and parent != method:
                    edges[(parent, method)] += 1
                loc = SRC.search(line)
                if loc and method not in sites:
                    sites[method] = {"caller_file":loc["file"],"caller_line":int(loc["line"])}
            stack.append((depth, method if stage else None))
    return seen, functions, stage_calls, edges, sites

def original_source_anchors():
    out = []
    for name, label, needle, start in ANCHORS:
        path = SOURCES[label]
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
        match = next((i + 1 for i, s in enumerate(lines) if needle in s and i + 1 >= start), None)
        if match is None:
            raise RuntimeError(f"Epic source anchor absent: {name}: {path}")
        out.append({"operation":name,"source":str(path),"line":match,"source_sha256":sha(path),
                    "text_signature":needle})
    return out

def clean_id(name):
    return re.sub(r"[^A-Za-z0-9_]", "_", name)

def safe_label(value):
    return str(value).replace("\\","\\\\").replace('"', '\\"').replace("\n", " ")

def make_graph(methods, edges, anchors, run, svg, dot_exe):
    by_stage = collections.defaultdict(list)
    for method, count in methods.items():
        stage=selected_stage(method)
        if stage:
            by_stage[stage].append((method,count))
    colors={"parse":"#d6e9ff","snapshot":"#d6e9ff","container":"#d6e9ff",
            "provider":"#ffe0bb","verify":"#e0edff","rebuild":"#ffe0bb","misc":"#eee"}
    lines=[
        'digraph Audit {',
        ' graph [rankdir=LR, bgcolor="white", fontname="Arial", pad=0.3, nodesep=.33, ranksep=.9];',
        ' node [fontname="Arial", shape=box, style="rounded,filled", fontsize=10];',
        ' edge [color="#536177", arrowsize=.6, fontsize=9];',
        ' subgraph cluster_php { label="UNREALDB — observed Xdebug PHP calls"; color="#4776b7"; style="rounded";',
    ]
    allowed=set()
    for i,(stage,patterns) in enumerate(STAGES):
        sorted_methods=sorted(by_stage.get(stage,[]),key=lambda p:(-p[1],p[0]))
        for method,count in sorted_methods[:10]:
            k=clean_id(method);allowed.add(method)
            shorter=method.rsplit("\\",1)[-1]
            lines.append(f' P_{k} [label="{safe_label(shorter[:62])}\\n{count} observed call(s)",fillcolor="{colors[stage]}"];')
    lines.append(' }')
    for (src,dst),count in sorted(edges.items(),key=lambda p:-p[1]):
        if src in allowed and dst in allowed:
            lines.append(f' P_{clean_id(src)} -> P_{clean_id(dst)} [label="{count}"];')
    lines += [
        ' subgraph cluster_epic { label="EPIC UE5.8.3 — source anchors, native run NOT captured"; color="#a98962"; style="rounded,dashed";',
    ]
    for i,a in enumerate(anchors):
        lines.append(f' E{i} [label="{safe_label(a["operation"])}\\n{Path(a["source"]).name}:{a["line"]}",fillcolor="#f4eadb"];')
    lines += [
        ' E0 -> E1 [style=dashed,label="LoadPackage source branch"];',
        ' E2 -> E3 [style=dashed,label="source call"];',
        ' E3 -> E4 [style=dashed,label="conditional, source code"];',
        ' E3 -> E5 [style=dashed,label="conditional, source code"];',
        ' }',
        ' C0 [label="Unique catalog candidate\\nwithout native mount evidence",fillcolor="#fff3cf",shape=diamond];',
        ' C1 [label="PHP: source_unresolved\\n(native outcome unverified)",fillcolor="#eaf4ea"];',
        ' C0 -> C1 [label="observed"];',
        '}'
    ]
    dot = svg.with_suffix(".dot")
    dot.write_text("\n".join(lines)+"\n",encoding="utf-8")
    result=subprocess.run([str(dot_exe),"-Tsvg",str(dot),"-o",str(svg)],capture_output=True,text=True)
    if result.returncode!=0:
        raise RuntimeError("Graphviz failed: "+result.stderr)
    return {"dot":str(dot),"svg":str(svg),"node_count":len(allowed)+len(anchors)+2,
            "observed_graph_edges":sum(1 for edge in edges if edge[0] in allowed and edge[1] in allowed)}

def html_output(path:Path, graph_svg:Path, report):
    body=graph_svg.read_text("utf-8")
    body=re.sub(r'^<\?xml[^>]+>\s*','',body)
    body=re.sub(r'<!DOCTYPE svg[^>]*>\s*','',body)
    js='''<script>const data=document.getElementById("report-data");const o=JSON.parse(data.textContent);document.getElementById("source-details").textContent=JSON.stringify(o.epic_source_anchors,null,2);document.getElementById("trace-details").textContent=JSON.stringify(o.observed_stage_calls,null,2);</script>'''
    doc="""<!doctype html><html lang="en"><meta charset="utf-8"><title>UnrealDB / Epic process comparison</title>
<style>body{font:15px system-ui,Arial;background:#f8fafc;color:#152236;margin:2rem;max-width:1600px}
h1,h2{color:#113666}.panel{background:white;padding:1.2rem;border:1px solid #ced8e2;border-radius:10px;margin:1rem 0}
.warn{border-left:5px solid #a66b21;padding:.8rem;background:#fff9eb}svg{width:100%;height:auto}
pre{overflow:auto;background:#f0f5fa;padding:1rem}table{border-collapse:collapse;width:100%}
th,td{border-bottom:1px solid #dde3eb;padding:.6rem;text-align:left}a{color:#22599a}
</style><h1>UE5 classic: package-provider resolution</h1>
<p>This process view combines observed PHP calls with pinned Epic source locations. It does NOT equate static call possibilities with observed native engine execution.</p>
<div class="panel warn"><strong>Evidence boundary:</strong> the PHP path was actually executed with original UE5.8.3 assets. The Epic C++ branch is source-inspected only; native mount/session behavior was not executed in this run. Missing native evidence remains UNVERIFIED, not PASS.</div>
<div class="panel">"""+body+"""</div>
<div class="panel"><h2>Observed outcome</h2><table><tr><th>Input</th><th>Observation</th></tr>
<tr><td>Original consumer file</td><td>BaseFlattenMaterial.uasset</td></tr>
<tr><td>Original provider file</td><td>BaseFlattenDiffuseMap.uasset</td></tr>
<tr><td>Catalog candidates</td><td>1</td></tr>
<tr><td>Authenticated mount context</td><td>Unavailable</td></tr>
<tr><td>PHP result</td><td>source_unresolved (no invented provider)</td></tr>
<tr><td>Controlled empty mount case</td><td>No provider: missing (non-native fixture)</td></tr>
<tr><td>Epic native runtime comparison</td><td>NOT EXECUTED — no parity PASS</td></tr></table></div>
<div class="panel"><h2>Trace by stage</h2><pre id="trace-details"></pre>
<h2>Pinned Epic source anchors</h2><pre id="source-details"></pre></div>
<script type="application/json" id="report-data">"""+json.dumps(report).replace("</","<\\/")+"""</script>"""+js+"""</html>"""
    path.write_text(doc,encoding="utf-8")

def main():
    cli=argparse.ArgumentParser()
    cli.add_argument("--trace",type=Path,required=True)
    cli.add_argument("--result",type=Path,required=True)
    cli.add_argument("--output",type=Path,required=True)
    cli.add_argument("--dot",type=Path,default=Path(r"C:\Program Files\Graphviz\bin\dot.exe"))
    cli.add_argument("--evidence-output",type=Path,help="Optional bounded proof in the current UE5 contract, not a new test register")
    a=cli.parse_args()
    raw_result=a.result.read_bytes()
    observed=json.loads(raw_result.decode("utf-16") if raw_result[:2] in (bytes((255,254)),bytes((254,255))) else raw_result.decode("utf-8-sig"))
    if observed.get("result")!="PASS" or not observed.get("rebuilt_without_false_missing"):
        raise RuntimeError("Real original PHP regression not passing; refuse to publish graph as accepted")
    anchors=original_source_anchors()
    n,methods,stages,edges,sites=parse_xdebug(a.trace)
    for needed in ("parse","snapshot","container","provider","verify","rebuild"):
        if not stages[needed]:
            raise RuntimeError("Required executed PHP stage not observed: "+needed)
    graph_svg=a.output.with_suffix(".svg")
    graph=make_graph(methods,edges,anchors,observed,graph_svg,a.dot)
    report={
      "schema":"unrealdb-process-comparison-v1","contract":"ue5-mount-provider",
      "date_utc":datetime.now(timezone.utc).isoformat(),
      "source_status":"SOURCE_INSPECTED_NOT_NATIVE_EXECUTED",
      "php_status":"XDEBUG_EXECUTED_ORIGINAL_ASSETS",
      "overall_parity_certification":"NOT_ESTABLISHED",
      "xdebug_function_entries":n,"xdebug_trace_sha256":sha(a.trace),
      "observed_stage_calls":dict(stages),
      "observed_functions":dict(methods),
      "call_sites":sites,
      "epic_source_anchors":anchors,
      "original_input_shas":{k:v["SHA256"] for k,v in observed["real_original_files"].items()},
      "outcome":{"runtime_mount_evidence":False,"selector":observed["observed"],
                 "dependency_snapshot_total":observed["dependency_snapshot_total"],
                 "source_unresolved_dependency_rows":observed["source_unresolved_dependency_rows"],
                 "controlled_negative_mount_no_exception":observed["controlled_negative_mount_no_exception"]},
      "graph":graph,
    }
    a.output.with_suffix(".json").write_text(json.dumps(report,indent=2)+"\n",encoding="utf-8")
    html_output(a.output,graph_svg,report)
    if a.evidence_output:
        bounded = {
          "contract":"ue5-mount-provider", "related_contract":"ue5-dependency-publish",
          "scope":"UE5 classic original files; isolated PHP provider and rebuilder execution, no native mount session",
          "outcome":"PARTIAL_VERIFIED_RUNTIME_PHP; NATIVE_ORIGINAL_RUNTIME_UNVERIFIED",
          "epic_source_anchors":anchors,
          "trace_sha256":report["xdebug_trace_sha256"],
          "trace_events":n,"observed_php_stage_calls":dict(stages),
          "original_asset_sha256":report["original_input_shas"],
          "observed_selection":observed["observed"],
          "original_dependency_rows":observed["dependency_snapshot_total"],
          "unresolved_import_rows":observed["source_unresolved_dependency_rows"],
          "known_empty_mount_exercised_only_in_test":observed["controlled_negative_mount_no_exception"],
          "production_db_writes":0,"epic_original_file_writes":0,
          "remaining":"Authenticated native UE5 classic mount/loaded-linker selection and SQL/web outcomes not covered by this PHP trace",
          "source_product_sha256":{
            "catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php":sha(REPO/"catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php"),
            "catalog/src/Infrastructure/Metadata/Uedb5DependencyRebuilder.php":sha(REPO/"catalog/src/Infrastructure/Metadata/Uedb5DependencyRebuilder.php")
          }
        }
        a.evidence_output.parent.mkdir(parents=True,exist_ok=True)
        a.evidence_output.write_text(json.dumps(bounded,indent=2)+"\\n",encoding="utf-8")
    print(json.dumps({"html":str(a.output),"svg":str(graph_svg),"report":str(a.output.with_suffix(".json")),
        "trace_events":n,"stage_calls":dict(stages),"source_anchors":len(anchors),
        "proof":"OBSERVED_PHP_WITH_PINNED_EPIC_STATIC_SOURCE; NATIVE_OUTCOME_NOT_PROVEN"},indent=2))

if __name__=="__main__":
    main()
