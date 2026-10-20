#!/usr/bin/env python3
"""Source-indexed UnrealDB comparison table. This is NOT an Epic-only reference."""
import csv
import hashlib
import json
from pathlib import Path
import re

repo=Path(__file__).resolve().parents[2]
base=repo/"specs"/"epic-source"/"ue4-4.27.2"/"package-loading"
cfg=json.loads((base/"source-manifest.json").read_text(encoding="utf-8"))
out=repo/"catalog"/"compliance"/"UE427-LOADER-EPIC-STEP-MAPPING.csv"
field_re=re.compile(r'SA_VALUE\(TEXT\("([^"]+)"\)')
cond_re=re.compile(r'^\s*(?:#\s*(?:if|ifdef|ifndef|elif)\b|(?:else\s+)?if\s*\()')
sources={}
for src in cfg["files"]:
    p=Path(cfg["source_root"])/src["path"]
    raw=p.read_bytes()
    if hashlib.sha256(raw).hexdigest().upper()!=src["sha256"].upper():
        raise SystemExit("Pinned original Epic source changed: "+str(p))
    sources[src["id"]]=raw.decode("utf-8-sig").splitlines()
entries={}
for group in cfg["groups"]:
    lines=sources[group["file"]]
    for n,(line,needle) in enumerate(group["steps"],start=1):
        sid=f"STEP_{group['id']}_{n:02d}"
        if needle not in lines[line-1]:raise SystemExit("Broken source anchor "+sid)
        entries[sid]=(f"{group['file']}:{line}","loader_step",lines[line-1].strip())
    for lo,hi in group["condition_ranges"]:
        for line in range(lo,hi+1):
            statement=lines[line-1].strip()
            if cond_re.search(statement):
                sid=f"GATE_{group['id']}_{line}"
                entries[sid]=(f"{group['file']}:{line}","branch_gate",statement)
    for lo,hi in group["sections"]:
        for line in range(lo,hi+1):
            for field in field_re.findall(lines[line-1]):
                sid=f"FIELD_{group['id']}_{line}_{field}"
                entries[sid]=(f"{group['file']}:{line}","wire_field",field)
previous={}
columns=["source_step","epic_source","category","epic_expression","unrealdb_location",
         "production_caller","status","notes"]
if out.exists():
    with out.open(encoding="utf-8-sig",newline="") as f:
        previous={row["source_step"]:row for row in csv.DictReader(f)}
with out.open("w",encoding="utf-8",newline="") as f:
    writer=csv.DictWriter(f,fieldnames=columns)
    writer.writeheader()
    for sid,(epic,category,expression) in entries.items():
        prior=previous.get(sid,{})
        writer.writerow({"source_step":sid,"epic_source":epic,
             "category":category,"epic_expression":expression,
             "unrealdb_location":prior.get("unrealdb_location",""),
             "production_caller":prior.get("production_caller",""),
             "status":prior.get("status","NOT_CHECKED"),
             "notes":prior.get("notes","")})
print(f"PASS: {len(entries)} Epic source-indexed comparison rows; "
      f"{sum(1 for v in entries if previous.get(v,{}).get('status','NOT_CHECKED')=='NOT_CHECKED')} NOT_CHECKED")
