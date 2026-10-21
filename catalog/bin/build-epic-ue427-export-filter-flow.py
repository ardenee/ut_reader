#!/usr/bin/env python3
"""Epic-only UE4.27.2 FilterExport branch graph, validated at original C++."""
import hashlib
import json
from pathlib import Path
import xml.etree.ElementTree as ET

repo=Path(__file__).resolve().parents[2]
base=repo/"specs/epic-source/ue4-4.27.2/package-loading"
spec=json.loads((base/"source-manifest.json").read_text(encoding="utf-8"))
entry=next(item for item in spec["files"] if item["id"]=="Linker.h")
file=Path(spec["source_root"])/entry["path"]
raw=file.read_bytes()
if hashlib.sha256(raw).hexdigest().upper()!=entry["sha256"].upper():
    raise SystemExit("Epic Linker.h changed: refusing to build outdated branch graph")
source=raw.decode("utf-8-sig").splitlines()
nodes=[
 ("START",528,"bool FilterExport(", "Enter FLinker::FilterExport",False),
 ("FAILED",530,"Export.bExportLoadFailed || Export.bWasFiltered","Previous failure or filter?",True),
 ("EDITOR",534,"#if WITH_EDITOR","Is this compiled with WITH_EDITOR?",True),
 ("ALWAYS",535,"!Export.bNotAlwaysLoadedForEditorGame","Always loaded for editor game?",True),
 ("SERVER",540,"FilterClientButNotServer && Export.bNotForServer","Dedicated-server filtering?",True),
 ("CLIENT",544,"FilterServerButNotClient && Export.bNotForClient","Client-only filtering?",True),
 ("INVALID",548,"Export.ThisIndex.IsNull()","Null export package index?",True),
 ("FILTER",550,"return true;","Return true (filtered)",False),
 ("LOAD",552,"return false;","Return false (not filtered)",False),
]
edges=[
 ("START","FAILED",""),
 ("FAILED","FILTER","true"),("FAILED","EDITOR","false"),
 ("EDITOR","ALWAYS","WITH_EDITOR"),("EDITOR","SERVER","not compiled"),
 ("ALWAYS","LOAD","true"),("ALWAYS","SERVER","false"),
 ("SERVER","FILTER","true"),("SERVER","CLIENT","false"),
 ("CLIENT","FILTER","true"),("CLIENT","INVALID","false"),
 ("INVALID","FILTER","true"),("INVALID","LOAD","false")
]
ids={n[0] for n in nodes}
for sid,line,needle,_,_ in nodes:
    if needle not in source[line-1]:
        raise SystemExit(f"Source statement drift: {sid} Linker.h:{line}")
for a,b,_ in edges:
    assert a in ids and b in ids

mermaid=["flowchart TD"]
for sid,line,_,description,decision in nodes:
    text=f"{sid} — {description} (Linker.h:{line})"
    if decision:
        mermaid.append(f'    {sid}{{"{text}"}}')
    else:
        mermaid.append(f'    {sid}["{text}"]')
for a,b,label in edges:
    mermaid.append(f"    {a} -->"+(f"|{label}|" if label else "")+f" {b}")
(base/"flow-export_filter-decisions.mmd").write_text("\n".join(mermaid)+"\n",encoding="utf-8")

root=ET.Element("mxfile",{"host":"app.diagrams.net","type":"device","version":"24.7.17"})
tab=ET.SubElement(root,"diagram",{"id":"filter-decisions","name":"FLinker FilterExport"})
model=ET.SubElement(tab,"mxGraphModel",{"dx":"1800","dy":"1250","grid":"1",
                                      "gridSize":"10","guides":"1","tooltips":"1",
                                      "connect":"1","arrows":"1","page":"1",
                                      "pageScale":"1","pageWidth":"1700","pageHeight":"1750"})
graph=ET.SubElement(model,"root")
ET.SubElement(graph,"mxCell",{"id":"0"})
ET.SubElement(graph,"mxCell",{"id":"1","parent":"0"})
locations={
 "START":(500,45),"FAILED":(500,215),"EDITOR":(500,405),
 "ALWAYS":(120,600),"SERVER":(500,805),"CLIENT":(500,1030),
 "INVALID":(500,1245),"FILTER":(1100,805),"LOAD":(1100,1350),
}
for sid,line,_,description,decision in nodes:
    x,y=locations[sid]
    shape="rhombus" if decision else "rounded=1"
    text=f"{sid} — {description}<br>Linker.h:{line}"
    cell=ET.SubElement(graph,"mxCell",{"id":sid,"value":text,"parent":"1","vertex":"1",
         "style":f"shape={shape};whiteSpace=wrap;html=1;align=center;verticalAlign=middle;"
                 "fillColor=#f3f6f9;strokeColor=#5d758e;fontSize=13;"})
    ET.SubElement(cell,"mxGeometry",{"x":str(x),"y":str(y),"width":"340",
                                     "height":"102" if decision else "80","as":"geometry"})
for index,(a,b,label) in enumerate(edges):
    cell=ET.SubElement(graph,"mxCell",{"id":"edge_"+str(index),"parent":"1",
         "edge":"1","source":a,"target":b,"value":label,
         "style":"edgeStyle=orthogonalEdgeStyle;rounded=1;html=1;endArrow=block;strokeColor=#6b8196;fontSize=11;"})
    ET.SubElement(cell,"mxGeometry",{"relative":"1","as":"geometry"})
ET.indent(root,space="  ")
ET.ElementTree(root).write(base/"flow-export_filter-decisions.drawio",encoding="utf-8",xml_declaration=True)
print(f"PASS original Epic FilterExport pinned hash, {len(nodes)} source anchors, {len(edges)} true/false edges")
