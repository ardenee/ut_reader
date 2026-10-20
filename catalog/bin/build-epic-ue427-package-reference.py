#!/usr/bin/env python3
"""Generate a source-only UE4.27.2 package reference from pinned Epic C++."""
import hashlib
import html
import json
from pathlib import Path
import re
import xml.etree.ElementTree as ET

REPO = Path(__file__).resolve().parents[2]
OUTPUT = REPO / "specs" / "epic-source" / "ue4-4.27.2" / "package-loading"
MANIFEST = OUTPUT / "source-manifest.json"
FIELD = re.compile(r'SA_VALUE\(TEXT\("([^"]+)"\)')
CONDITION = re.compile(r'^\s*(?:#\s*(?:if|ifdef|ifndef|elif)\b|(?:else\s+)?if\s*\(|else\s+if\s*\()')

def cell(table, **attrs):
    return ET.SubElement(table, "mxCell", attrs)

def main():
    info = json.loads(MANIFEST.read_text(encoding="utf-8"))
    sources = {}
    checks = []
    for f in info["files"]:
        path = Path(info["source_root"]) / f["path"]
        if not path.is_file():
            raise RuntimeError("Epic source missing: " + str(path))
        data = path.read_bytes()
        digest = hashlib.sha256(data).hexdigest().upper()
        if digest != f["sha256"].upper():
            raise RuntimeError(f"Pinned source changed: {path} expected {f['sha256']} got {digest}")
        sources[f["id"]] = data.decode("utf-8-sig").splitlines()
        checks.append((f["id"], digest))

    steps = 0
    for group in info["groups"]:
        lines = sources[group["file"]]
        for lineno, expected in group["steps"]:
            if lineno < 1 or lineno > len(lines) or expected not in lines[lineno-1]:
                got = lines[lineno-1] if 1 <= lineno <= len(lines) else "(out of bounds)"
                raise RuntimeError(f"Source mismatch {group['file']}:{lineno}, expected {expected!r}, got {got!r}")
            steps += 1
        for lo,hi in group["sections"] + group["condition_ranges"]:
            if not (0 < lo <= hi <= len(lines)):
                raise RuntimeError(f"Invalid source range {group['file']}:{lo}-{hi}")

    md = [
        "# UE4.27.2: Epic source-only package loader and format reference",
        "",
        "Source: pinned original Epic C++ only. No UnrealDB result or PHP code is",
        "used to generate this reference. The original UE4.27.2 source remains",
        "authoritative when reading any abbreviated table or diagram.",
        "",
        f"**Revision:** `{info['pinned_source_revision']}`",
        f"**Source checkout:** `{info['source_root']}`",
        f"**Completion:** **INCOMPLETE** — {info['scope']}",
        "",
        "## Source integrity",
        "",
        "| Original Epic file | Source SHA-256 |",
        "|---|---|",
    ]
    for name,digest in checks:
        md.append(f"| `{name}` | `{digest}` |")
    md.extend(["","## How to read these views", "",
        "The diagrams show **the original source statement order** within a named",
        "function, not an assertion that every stage always executes. To determine",
        "a branch outcome, follow the separately listed **exact C++ conditions**",
        "and the real source at the cited line; do not interpolate skipped branches.",
        "No inferred numeric enum values or PHP assumptions appear here.",
        "",
        "The field tables are **serialization sites**, not fixed offsets or fixed-size",
        "records. Format, byte width and presence depend on Epic's archive and",
        "version/compiler conditions. In particular, the header and Import/Export",
        "fields must not be read as unconditional sequential fixed-width columns.",
        "",
    ])
    body = ['<!doctype html><html lang="en"><head><meta charset="utf-8">',
            '<title>UE4.27.2 Epic source-only package loader and format</title>',
            '<style>@page{size:A4 landscape;margin:14mm 13mm}body{font:11px/1.4 Arial,sans-serif;color:#243244}',
            'h1{font-size:23px}h2{font-size:17px;page-break-before:always}',
            'h3{font-size:13px}code{font-family:Consolas,monospace;font-size:9px}',
            'table{border-collapse:collapse;width:100%;margin:12px 0 20px}',
            'th,td{border:1px solid #cbd5df;padding:5px 7px;vertical-align:top;text-align:left}',
            'th{background:#e9eff4}tr{page-break-inside:avoid}thead{display:table-header-group}',
            '.note{border-left:4px solid #506982;padding:10px;background:#eef3f7}',
            '.warning{font-weight:bold}td.snippet{overflow-wrap:anywhere;font-family:Consolas,monospace;font-size:9px}',
            '</style></head><body><h1>UE4.27.2 — Epic source-only package loader and format</h1>',
            f'<div class="note">Authority: original Epic C++ only.<p>Revision: {html.escape(info["pinned_source_revision"])}',
            f'<p>Source root: {html.escape(info["source_root"])}</p>',
            '<p class="warning">INCOMPLETE — this is a source navigation reference, not full-package format coverage or UnrealDB certification.</p></div>',
            '<h3>Pinned source integrity</h3><table><tr><th>Epic file</th><th>SHA-256</th></tr>']
    for name,digest in checks:
        body.append(f"<tr><td>{html.escape(name)}</td><td><code>{digest}</code></td></tr>")
    body.extend(["</table><p>Diagram arrows show source statement order only. Every relevant",
                 " C++ conditional must be followed at its cited source line.</p>"])
    # Use the ORIGINAL enum declarations, never estimate numeric gates by
    # counting enum members or copying PHP's implementation.
    version_re=re.compile(r"\bVER_UE4_[A-Za-z0-9_]+\b")
    used_versions=set()
    for group in info["groups"]:
        for start,end in group["sections"]:
            for line in sources[group["file"]][start-1:end]:
                used_versions.update(version_re.findall(line))
    defs=sources["ObjectVersion.h"]
    positions={}
    for number,line in enumerate(defs,start=1):
        for name in version_re.findall(line):
            if name in used_versions:
                positions[name]=(number,line.strip())
    md.extend(["## Version symbols — original Epic declarations", "",
       "No derived or manually typed numeric enum values. Look up these",
       "definitions in the pinned Epic header, including explicit assignments",
       "and applicable preprocessor conditions.", "",
       "| Used C++ symbol | Original Epic declaration location |",
       "|---|---|"])
    body.extend(["<h2>Version symbols in the original Epic source</h2>",
       "<p>Every source-defined version gate below is linked to its enum declaration.",
       " No inferred numeric values are present.</p>",
       "<table><tr><th>Epic version symbol</th><th>Original source declaration</th></tr>"])
    for name in sorted(used_versions):
        if name in positions:
            lineno,_=positions[name]
            location=f"ObjectVersion.h:{lineno}"
        else:
            location="No matching declaration in this pinned ObjectVersion.h; follow the original C++ reference."
        md.append(f"| `{name}` | `{location}` |")
        body.append(f"<tr><td><code>{html.escape(name)}</code></td>"
                    f"<td>{html.escape(location)}</td></tr>")
    body.append("</table>")
    md.append("")
    xml = ET.Element("mxfile",{"host":"app.diagrams.net","type":"device","version":"24.7.17"})
    for group in info["groups"]:
        gname = group["title"]
        lines = sources[group["file"]]
        md.extend([f"## {gname}", "",
                   f"**Epic function:** `{group['file']}:{group['function_line']}`",
                   "", "| Step | Original source location | Exact C++ statement |",
                   "|---|---|---|"])
        body.extend([f"<h2>{html.escape(gname)}</h2>",
                     f"<p><b>Epic function:</b> {html.escape(group['file'])}:{group['function_line']}</p>",
                     "<table><thead><tr><th>Step</th><th>Source</th><th>Exact Epic statement</th></tr></thead><tbody>"])
        mm = ["flowchart TD"]
        tab=ET.SubElement(xml,"diagram",{"name":group["id"],"id":group["id"]})
        model=ET.SubElement(tab,"mxGraphModel",{"dx":"1300","dy":"950","grid":"1","gridSize":"10",
            "guides":"1","tooltips":"1","connect":"1","arrows":"1","page":"1",
            "pageScale":"1","pageWidth":"1050","pageHeight":str(max(1800, len(group["steps"])*115+180))})
        root=ET.SubElement(model,"root")
        cell(root,id="0")
        cell(root,id="1",parent="0")
        for index,(lineno,needle) in enumerate(group["steps"],start=1):
            code=lines[lineno-1].strip()
            step_id=f"{group['id'].upper()}{index:02d}"
            location=f"{group['file']}:{lineno}"
            md.append(f"| `{step_id}` | `{location}` | `{code.replace('|','\\|')}` |")
            body.append(f"<tr><td>{step_id}</td><td>{html.escape(location)}</td><td class='snippet'>{html.escape(code)}</td></tr>")
            note=code.replace('"',"'").replace("<","&lt;").replace(">","&gt;")
            if len(note)>70:note=note[:67]+"..."
            title=f"{step_id} {note}<br/>{location}"
            mm.append(f'  {step_id}["{title}"]')
            vertex=cell(root,id=step_id,parent="1",vertex="1",value=title,
                        style="rounded=1;whiteSpace=wrap;html=1;fillColor=#f3f6f9;strokeColor=#667d92;fontSize=13;spacing=9;")
            ET.SubElement(vertex,"mxGeometry",{"x":"100","y":str(85+(index-1)*115),
                "width":"700","height":"77","as":"geometry"})
            if index>1:
                prev=f"{group['id'].upper()}{index-1:02d}"
                mm.append(f"  {prev} --> {step_id}")
                edge=cell(root,id=f"{group['id']}_e{index}",parent="1",edge="1",source=prev,target=step_id,
                          style="edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#667d92;")
                ET.SubElement(edge,"mxGeometry",{"relative":"1","as":"geometry"})
        body.append("</tbody></table>")
        (OUTPUT/f"flow-{group['id']}.mmd").write_text("\n".join(mm)+"\n",encoding="utf-8",newline="\n")
        md.extend(["","### Actual conditional statements in the original Epic source","",
                   "| Source line | Original C++ condition or compile-time directive |",
                   "|---|---|"])
        body.append("<h3>Conditional statements in this original Epic source path</h3><table><tr><th>Source</th><th>Original C++ condition</th></tr>")
        gates=[]
        for lo,hi in group["condition_ranges"]:
            for lineno in range(lo,hi+1):
                statement=lines[lineno-1].strip()
                if CONDITION.search(statement):
                    gates.append((lineno,statement))
        for lineno,statement in gates:
            md.append(f"| `{group['file']}:{lineno}` | `{statement.replace('|','\\|')}` |")
            body.append(f"<tr><td>{html.escape(group['file'])}:{lineno}</td><td class='snippet'>{html.escape(statement)}</td></tr>")
        body.append("</table>")
        md.extend(["","### Serialized field sites in the Epic implementation","",
                   "| Source line | Field written/read by Epic |",
                   "|---|---|"])
        body.append("<h3>Serialized fields in source order</h3><table><tr><th>Source</th><th>Serialized field</th></tr>")
        count=0
        for lo,hi in group["sections"]:
            for lineno in range(lo,hi+1):
                for field in FIELD.findall(lines[lineno-1]):
                    count+=1
                    md.append(f"| `{group['file']}:{lineno}` | `{field}` |")
                    body.append(f"<tr><td>{html.escape(group['file'])}:{lineno}</td><td><code>{html.escape(field)}</code></td></tr>")
        if not count:
            md.append("| — | No direct SA_VALUE sites inside this section; follow its called serializer |")
            body.append("<tr><td>—</td><td>No direct SA_VALUE sites in this function</td></tr>")
        body.append("</table>")
        md.extend(["",f"**{len(group['steps'])} source-anchored navigation nodes; {len(gates)} original conditional-directive lines; {count} serialized field sites.**", ""])
    md.extend(["## Unexpanded calls and formats", "",
               "Not traced to their end here; no end-to-end completeness claim is permitted.",
               ""])
    body.extend(["<h2>Unexpanded calls / format coverage</h2><p>These must be traced before any whole-file compliance claim.</p><ul>"])
    for item in info["not_yet_expanded"]:
        md.append("- "+item)
        body.append("<li>"+html.escape(item)+"</li>")
    body.append("</ul></body></html>")
    (OUTPUT/"source-reference.md").write_text("\n".join(md)+"\n",encoding="utf-8",newline="\n")
    (OUTPUT/"source-reference.html").write_text("\n".join(body)+"\n",encoding="utf-8",newline="\n")
    ET.indent(xml,space="  ")
    ET.ElementTree(xml).write(OUTPUT/"source-reference.drawio",encoding="utf-8",xml_declaration=True)
    print(f"PASS original Epic SHA256 {len(checks)}/{len(info['files'])} files; exact C++ anchors {steps}; diagram pages {len(info['groups'])}")
    print("Output:",OUTPUT/"source-reference.md",OUTPUT/"source-reference.html",OUTPUT/"source-reference.drawio",sep="\n  ")

if __name__=="__main__":
    main()
