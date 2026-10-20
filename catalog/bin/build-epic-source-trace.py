#!/usr/bin/env python3
"""Render SOURCE-ONLY Epic execution traces; reject changed or missing source evidence."""
import csv
import hashlib
import json
from html import escape
from pathlib import Path
import sys
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parents[2]
CONTRACT = ROOT / "specs" / "epic-source" / "ue4-4.27.2" / "verifyimport" / "source-path.json"
COMPARISON = ROOT / "catalog" / "compliance" / "UE427-P07-SOURCE-STEP-CHECKLIST.csv"

def main():
    spec = json.loads(CONTRACT.read_text(encoding="utf-8"))
    source = Path(spec["source_root"]) / Path(spec["source_file"])
    if not source.is_file():
        raise SystemExit(f"PINNED EPIC SOURCE UNAVAILABLE: {source}")
    raw = source.read_bytes()
    actual = hashlib.sha256(raw).hexdigest().upper()
    if actual != spec["sha256"].upper():
        raise SystemExit(f"EPIC SOURCE SHA256 CHANGED: expected {spec['sha256']} actual {actual}")
    lines = raw.decode("utf-8-sig").splitlines()
    nodes = {s["id"]: s for s in spec["steps"]}
    assert len(nodes) == len(spec["steps"]), "duplicate node IDs"
    for s in spec["steps"]:
        n = int(s["line"])
        assert 1 <= n <= len(lines), (s["id"], n, "line out of bounds")
        if s["needle"] not in lines[n-1]:
            raise AssertionError(
                f"{s['id']} Epic anchor changed at {source.name}:{n}\n"
                f"Expected: {s['needle']}\nActual: {lines[n-1]}"
            )
        for target, label in s["to"]:
            assert target in nodes, f"unknown edge: {s['id']} -> {target}"
    # Guard against silently dropping a step from both diagrams and narrative.
    used = [sid for g in spec["groups"] for sid in g["ids"]]
    assert len(used) == len(nodes) and set(used) == set(nodes), "group/step coverage mismatch"

    # Markdown is a faithful, line-addressed rendering of the input from Epic.
    out = CONTRACT.parent
    md = [
        "# UE4.27.2 - Epic source-only VerifyImport execution paths",
        "",
        "**Authority:** original Epic C++ only. This document contains no UnrealDB implementation claims.",
        "",
        f"- Engine: `{spec['engine']}`",
        f"- Pinned revision: `{spec['revision']}`",
        f"- Local Epic path: `{source}`",
        f"- Whole source file SHA-256: `{actual}`",
        f"- Scope: {spec['scope']}",
        f"- Complete end-to-end package loading path: **{'yes' if spec['complete_package_loading_path'] else 'NO - dependencies not expanded'}**",
        "",
        "This is a source navigation aid, not a compliance certificate. Read the linked",
        "C++ statement and surrounding branch before implementing any corresponding operation.",
        "The editable `.drawio` diagram contains three separate pages to avoid tangled paths.",
        "",
    ]
    for group in spec["groups"]:
        mmd = []
        md.extend([f"## {group['title']}", "", group["description"], "",
                   "| Step | Epic source line | Exact Epic statement | Source-derived operation |",
                   "|---|---|---|---|"])
        mmd.extend([f"%% {group['title']}", "flowchart TD"])
        local = set(group["ids"])
        for sid in group["ids"]:
            s = nodes[sid]
            statement = lines[s["line"]-1].strip().replace("|", "\\|")
            desc = s["action"].replace("|", "\\|")
            md.append(f"| `{sid}` | `LinkerLoad.cpp:{s['line']}` | `{statement}` | {desc} |")
            title = s["action"].replace('"', "'").replace("<", "&lt;").replace(">", "&gt;")
            title = title[:75] + ("..." if len(title)>75 else "")
            caption = f"{sid}: {title}<br/>LinkerLoad.cpp:{s['line']}"
            if s.get("decision"):
                mmd.append(f'    {sid}{{"{caption}"}}')
            else:
                mmd.append(f'    {sid}["{caption}"]')
        for sid in group["ids"]:
            for target, label in nodes[sid]["to"]:
                if target not in local:
                    # Explicit cross-page call: source operation is retained in the table.
                    continue
                mmd.append(f"    {sid} -->{('|'+label+'|' if label else '')} {target}")
        md.extend(["", "### Exact source-path transitions", ""])
        for sid in group["ids"]:
            s=nodes[sid]
            targets = ", ".join(f"`{t}`{(' ('+label+')') if label else ''}" for t,label in s["to"])
            md.append(f"- `{sid}` -> {targets or 'return to caller'}")
        md.extend(["", ""])
        mmd.append("")
        (out/f"source-path-{group['key']}.mmd").write_text("\n".join(mmd), encoding="utf-8", newline="\n")
    md.extend(["## Functions/branches to expand before declaring end-to-end coverage", ""])
    for dep in spec["source_dependencies_to_expand"]:
        md.append(f"- `{dep}`")
    md.extend(["", "**No completeness claim:** each of the functions above needs its own",
              "Epic-only source trace (including its version/compile-condition branches).",
              "The production PHP comparison belongs outside this source-only folder.", ""])
    (out/"source-path.md").write_text("\n".join(md), encoding="utf-8", newline="\n")
    old_combined = out/"source-path.mmd"
    if old_combined.exists():
        old_combined.unlink()

    # Print-ready source-only HTML; the PDF is exported from this, never reauthored.
    html = ["""<!doctype html><html lang="en"><head><meta charset="utf-8">
    <title>UE4.27.2 Epic VerifyImport source trace</title>
    <style>
    @page { size: A4 landscape; margin: 15mm 13mm; }
    body { font: 11px/1.4 Arial, sans-serif; color:#1b2736; }
    h1 { font-size:23px; } h2 { font-size:17px; margin-top:24px; }
    .meta { border-left:4px solid #455f7a; padding:8px 12px; background:#f1f5f9; }
    table { width:100%; border-collapse:collapse; margin-top:10px; }
    thead { display:table-header-group; }
    th { background:#eaf0f6; }
    th,td { border:1px solid #cbd5df; text-align:left; padding:5px 7px; vertical-align:top; }
    tr { page-break-inside:avoid; }
    td.source { width:31%; font-family:Consolas,monospace; font-size:9px; overflow-wrap:anywhere; }
    td.step { width:8%; } td.loc { width:11%; }
    code { font-family:Consolas,monospace; }
    .group { page-break-before:always; }
    footer { color:#536474; font-size:9px; }
    </style></head><body>
    <h1>UE4.27.2 - Epic source-only VerifyImport trace</h1>"""]
    html += ["<div class='meta'><p><b>Authority:</b> Original Epic C++ only; no UnrealDB implementations or test outcomes.</p>",
             f"<p><b>Epic revision:</b> <code>{escape(spec['revision'])}</code><br>",
             f"<b>Source:</b> <code>{escape(str(source))}</code><br>",
             f"<b>Source SHA-256:</b> <code>{actual}</code></p>",
             "<p><b>Scope:</b> VerifyImport, VerifyImportInner and LoadImportPackage lambda only. "
             "<b>Not a complete package-loading path or full UE4.27.2 file-format reference.</b></p></div>"]
    for group in spec["groups"]:
        html.append(f"<section class='group'><h2>{escape(group['title'])}</h2>")
        html.append(f"<p>{escape(group['description'])}</p>")
        html.append("<table><thead><tr><th>Step</th><th>Epic source</th><th>Exact C++ statement</th><th>Source-derived operation / next step</th></tr></thead><tbody>")
        for sid in group["ids"]:
            st = nodes[sid]
            snippet = lines[st["line"]-1].strip()
            dest = ", ".join(f"{t} {label}".strip() for t,label in st["to"]) or "Return"
            html.append(f"<tr><td class='step'>{sid}</td><td class='loc'>LinkerLoad.cpp:{st['line']}</td>"
                        f"<td class='source'>{escape(snippet)}</td>"
                        f"<td>{escape(st['action'])}<br><b>Next:</b> {escape(dest)}</td></tr>")
        html.append("</tbody></table></section>")
    html.append("<footer>Source-only navigation. Use the editable multi-page source-path.drawio for flow arrows."
                " All unexpanded callees prevent any completeness claim.</footer></body></html>")
    (out/"source-path.html").write_text("\n".join(html), encoding="utf-8", newline="\n")

    # Editable multi-tab diagrams.net/draw.io XML (also useful for export to Visio).
    mxfile = ET.Element("mxfile", {"host":"app.diagrams.net", "type":"device",
                                  "version":"24.7.17"})
    for g in spec["groups"]:
        diagram = ET.SubElement(mxfile, "diagram", {"id":g["key"],"name":g["title"].split("::")[-1]})
        model = ET.SubElement(diagram, "mxGraphModel", {
            "dx":"1800","dy":"1100","grid":"1","gridSize":"10","guides":"1",
            "tooltips":"1","connect":"1","arrows":"1","fold":"1","page":"1",
            "pageScale":"1","pageWidth":"1400","pageHeight":str(max(2600, len(g["ids"])*124+180)),"math":"0"
        })
        root = ET.SubElement(model,"root")
        ET.SubElement(root,"mxCell",{"id":"0"})
        ET.SubElement(root,"mxCell",{"id":"1","parent":"0"})
        local=set(g["ids"])
        for i,sid in enumerate(g["ids"]):
            st=nodes[sid]
            action=st["action"]
            if len(action)>88:
                action=action[:85]+"..."
            value=f"{sid} | {action}<br><font color='#666666'>LinkerLoad.cpp:{st['line']}</font>"
            shape="rhombus" if st.get("decision") else "rounded=1"
            style=(f"shape={shape};whiteSpace=wrap;html=1;align=center;verticalAlign=middle;"
                   "fillColor=#f5f8fc;strokeColor=#516983;fontSize=13;spacing=9;")
            if st.get("decision"):
                style += "fillColor=#fff9e9;strokeColor=#a17b26;"
            cell=ET.SubElement(root,"mxCell",{"id":sid,"value":value,"style":style,
                                              "vertex":"1","parent":"1"})
            x=120+int(st.get("lane",0))*455
            y=100+i*115
            ET.SubElement(cell,"mxGeometry",{
                "x":str(x),"y":str(y),"width":"365","height":"84","as":"geometry"})
        k=0
        external_nodes = {}
        for sid in g["ids"]:
            for target,label in nodes[sid]["to"]:
                if target not in local and target not in external_nodes:
                    external_nodes[target] = True
                    v = ET.SubElement(root,"mxCell",{
                        "id":"offpage_"+target,
                        "value":f"Continue in other diagram tab: {target}",
                        "style":"shape=ellipse;whiteSpace=wrap;html=1;fillColor=#eef1f5;"
                                "strokeColor=#697d91;dashed=1;fontSize=13;",
                        "vertex":"1","parent":"1"
                    })
                    ET.SubElement(v,"mxGeometry",{
                        "x":"660","y":str(100+len(external_nodes)*135),
                        "width":"370","height":"74","as":"geometry"
                    })
        for sid in g["ids"]:
            for target,label in nodes[sid]["to"]:
                if target not in local:
                    target = "offpage_"+target
                k+=1
                ed=ET.SubElement(root,"mxCell",{
                    "id":f"{g['key']}_edge_{k}","value":label,
                    "style":"edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;"
                            "endArrow=block;endFill=1;strokeColor=#637184;fontSize=11;",
                    "edge":"1","parent":"1","source":sid,"target":target
                })
                ET.SubElement(ed,"mxGeometry",{"relative":"1","as":"geometry"})
    ET.indent(mxfile, space="  ")
    ET.ElementTree(mxfile).write(out/"source-path.drawio", encoding="utf-8", xml_declaration=True)

    # Production mapping deliberately lives OUTSIDE the Epic-only reference.
    # Preserve previously entered mappings on rerender.
    old = {}
    if COMPARISON.exists():
        with COMPARISON.open(encoding="utf-8-sig",newline="") as f:
            old={row["step"]:row for row in csv.DictReader(f)}
    fields=["step","epic_source","epic_statement","unrealdb_location",
            "production_caller","status","explanation"]
    with COMPARISON.open("w",encoding="utf-8",newline="") as f:
        writer=csv.DictWriter(f,fieldnames=fields)
        writer.writeheader()
        for st in spec["steps"]:
            sid=st["id"]
            before=old.get(sid,{})
            writer.writerow({
                "step":sid,
                "epic_source":f"LinkerLoad.cpp:{st['line']}",
                "epic_statement":lines[st["line"]-1].strip(),
                "unrealdb_location":before.get("unrealdb_location",""),
                "production_caller":before.get("production_caller",""),
                "status":before.get("status","NOT_CHECKED"),
                "explanation":before.get("explanation",""),
            })
    print(f"PASS: pinned Epic source SHA256 {actual}")
    print(f"PASS: {len(nodes)} anchors, {len(spec['groups'])} independent diagram pages")
    print("Output:",out/"source-path.md",out/"source-path-verify_import.mmd",out/"source-path.drawio",COMPARISON,sep="\n  ")

if __name__ == "__main__":
    main()
