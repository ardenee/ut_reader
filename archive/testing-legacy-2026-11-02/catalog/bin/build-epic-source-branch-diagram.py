#!/usr/bin/env python3
"""Render source-linked branch graph for pinned original Epic UE4.27.2 C++."""
import hashlib
import html
import json
from pathlib import Path
import xml.etree.ElementTree as ET

REPO = Path(__file__).resolve().parents[2]
OUT = REPO / "specs" / "epic-source" / "ue4-4.27.2" / "package-loading"
SPEC = json.loads((OUT/"summary-decision-graph.json").read_text(encoding="utf-8"))
source = (Path("L:/Source/Engine/UE4/UE 4.27.2")
          / "Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp")
data=source.read_bytes()
digest=hashlib.sha256(data).hexdigest().upper()
if digest!=SPEC["pinned_sha256"].upper():
    raise SystemExit("Pinned Epic C++ has changed: "+digest)
lines=data.decode("utf-8-sig").splitlines()
nodes={x["id"]:x for x in SPEC["steps"]}
assert len(nodes)==len(SPEC["steps"]), "Duplicate node IDs"
for node in SPEC["steps"]:
    line=int(node["line"])
    if node["needle"] not in lines[line-1]:
        raise SystemExit("Epic source statement changed: "+node["id"])
    for target,label in node["edges"]:
        if target not in nodes: raise SystemExit(f"Invalid edge {node['id']} -> {target}")
        if node["decision"] and not label:
            raise SystemExit(f"Unlabelled decision edge {node['id']} -> {target}")
    if node["decision"] and len(node["edges"])!=2:
        raise SystemExit(f"Decision must have two branches: {node['id']}")
# Source-ordered main path; auxiliary action/return nodes displayed alongside,
# not mistaken for an executable total ordering.
aux={"B04","B08","B11","B13","B96","B90","B91","B92","B93","B94","B95","B98","B99"}
main=["B01","B02","B03","B00","B05","B06","B07","B09","B10","B12","B14","B15","B16","B97","B17","B18"]
levels={sid:i for i,sid in enumerate(main)}
parent={"B04":"B03","B08":"B07","B11":"B10","B13":"B12",
        "B96":"B13","B90":"B02","B91":"B05","B92":"B06",
        "B93":"B13","B94":"B15","B95":"B17","B98":"B97","B99":"B00"}
extra_rank={"B13":0,"B96":1}
root=ET.Element("mxfile",{"host":"app.diagrams.net","type":"device","version":"24.7.17"})
page=ET.SubElement(root,"diagram",{"id":"summary-branches","name":"Header decisions"})
model=ET.SubElement(page,"mxGraphModel",{"dx":"2000","dy":"1000","grid":"1",
        "gridSize":"10","guides":"1","tooltips":"1","connect":"1","arrows":"1",
        "page":"1","pageScale":"1","pageWidth":"1800","pageHeight":"2700"})
graph=ET.SubElement(model,"root")
ET.SubElement(graph,"mxCell",{"id":"0"})
ET.SubElement(graph,"mxCell",{"id":"1","parent":"0"})
mm=["flowchart TD"]
md=["# UE4.27.2: Epic summary decision-path view","",
    "Source-only selected branches from `PackageFileSummary.cpp`.",
    "This is a focused decision graph, **not** the complete FPackageFileSummary",
    "serialization function. Skipped serialization fields remain in",
    "`source-reference.md` and the original pinned Epic source.",
    "",
    f"Source SHA-256: `{digest}`","",
    "| Node | Source location | Condition/action | Outgoing branch |",
    "|---|---|---|---|"]
for item in SPEC["steps"]:
    sid=item["id"]
    if sid in levels:
        x,y=130,95+levels[sid]*148
    else:
        p=parent.get(sid,"B01")
        px=levels.get(p,levels.get(parent.get(p,"B01"),0))
        x=850 if sid in {"B90","B91","B92","B93","B94","B95","B98","B99"} else 530
        y=95+px*148+ (72 if sid in {"B96"} else 0)
    expr=lines[item["line"]-1].strip()
    value=f"{sid}  {item['description']}<br/><font color='#596d82'>PackageFileSummary.cpp:{item['line']}</font>"
    style=("shape=rhombus;fillColor=#fff7e6;strokeColor=#9d7b2d;" if item["decision"]
           else "rounded=1;fillColor=#f1f5f9;strokeColor=#516982;")
    if sid in {"B90","B91","B92","B93","B94","B95","B98","B99"}:
        style="shape=ellipse;fillColor=#f8f0f0;strokeColor=#966a6a;"
    vertex=ET.SubElement(graph,"mxCell",{"id":sid,"value":value,"parent":"1",
            "vertex":"1","style":style+"whiteSpace=wrap;html=1;align=center;verticalAlign=middle;fontSize=12;spacing=9;"})
    ET.SubElement(vertex,"mxGeometry",{"x":str(x),"y":str(y),
            "width":"420","height":"88","as":"geometry"})
    title=item["description"].replace('"',"'").replace("<","&lt;").replace(">","&gt;")
    mm.append(f'  {sid}{"{" if item["decision"] else "["}"{sid}: {title}<br/>line {item["line"]}"{"}" if item["decision"] else "]"}')
    paths=", ".join(f"{label}: `{dest}`" if label else f"`{dest}`" for dest,label in item["edges"]) or "Terminal"
    md.append(f"| `{sid}` | `PackageFileSummary.cpp:{item['line']}` | `{expr.replace('|','\\|')}` | {paths} |")
for item in SPEC["steps"]:
    for i,(dest,label) in enumerate(item["edges"],start=1):
        edge=ET.SubElement(graph,"mxCell",{"id":f"edge_{item['id']}_{i}",
            "value":label,"parent":"1","edge":"1","source":item["id"],"target":dest,
            "style":"edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;"
                    "endArrow=block;strokeColor=#6c7c8c;fontSize=12;"})
        ET.SubElement(edge,"mxGeometry",{"relative":"1","as":"geometry"})
        safe=label.replace('"',"'")
        mm.append(f"  {item['id']} -->{('|'+safe+'|' if safe else '')} {dest}")
ET.indent(root,space="  ")
ET.ElementTree(root).write(OUT/"summary-decision-graph.drawio",encoding="utf-8",xml_declaration=True)
(OUT/"summary-decision-graph.mmd").write_text("\n".join(mm)+"\n",encoding="utf-8")
(OUT/"summary-decision-graph.md").write_text("\n".join(md)+"\n",encoding="utf-8")
print(f"PASS: {len(nodes)} C++-anchored nodes, {sum(len(x['edges']) for x in nodes.values())} source-linked transitions")
