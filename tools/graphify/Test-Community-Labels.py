#!/usr/bin/env python3
"""Test HTML-only local Graphify viewer cap and source-based community labels.

Checks source graph identities, native Graphify HTML aggregation and label
uniqueness. Never opens, changes, or reindexes source files.
"""
from collections import Counter
import hashlib
import json
from pathlib import Path

root = Path.home() / "Graphify"
config=root / "viewer-settings.json"
limit = json.loads(config.read_text(encoding="utf8"))["node_limit"] if config.exists() else 5000
assert isinstance(limit,int) and not isinstance(limit,bool) and 2000<=limit<=5000 and limit%500==0

def visual_nodes(file):
    text=file.read_text(encoding="utf8")
    label="const RAW_NODES = "
    at=text.index(label)+len(label)
    data, end=json.JSONDecoder().raw_decode(text[at:])
    assert text[at+end:].lstrip().startswith(";"),file
    assert len(data)==len({str(x["id"]) for x in data}),file
    assert len(data)==len({str(x["label"]).casefold() for x in data}),file
    return data

count,full,aggregated,duplicate_suffixes=0,0,0,0
baseline=root/"viewer-source-hashes-before.json"
source_hashes=json.loads(baseline.read_text(encoding="utf8")) if baseline.exists() else {}
for file in sorted(root.glob("*/graphify-out/graph.json")):
    name=file.parent.parent.name
    graph=json.loads(file.read_text(encoding="utf8"))
    source=graph["nodes"]
    if name in source_hashes:
        assert hashlib.sha256(file.read_bytes()).hexdigest()==source_hashes[name],name
    display_labels=json.loads((file.parent/".graphify_display_labels.json").read_text(encoding="utf8"))
    communities={str(n["community"]) for n in source if n.get("community") is not None}
    assert communities==set(display_labels),file
    assert len({x.casefold() for x in display_labels.values()})==len(display_labels)
    visible=visual_nodes(file.parent/"graph.html")
    fallback=visual_nodes(file.parent/"graph-communities.html")
    assert len(fallback)==len(visible) # Both respect same configured cap.
    if len(source)<=limit:
        full+=1
        originals={str(n["id"]):str(n.get("label") or "") for n in source}
        assert len(visible)==len(source)
        assert {str(n["id"]) for n in visible}==set(originals),name
        names=Counter(v[:256].casefold() for v in originals.values())
        for n in visible:
            label=originals[str(n["id"])][:256]
            current=str(n["label"])
            if current!=label:
                assert names[label.casefold()]>1,(name,label,current)
                assert current.startswith(label+" #"),(name,label,current)
                duplicate_suffixes+=1
    else:
        aggregated+=1
        assert len(visible)==len(communities),(name,len(visible),len(communities))
        assert len(visible)<=limit,(name,len(visible),limit)
        assert {str(n["id"]) for n in visible}==communities,name
        for n in visible:
            assert n["label"]==display_labels[str(n["id"])],(name,n)
    count+=1
assert count>=27
print(f"VIEWER_LABELS_PASS {count}/{count} LIMIT={limit}")
print(f"FULL_SOURCE_VIEWS={full}, CONDENSED_VIEWS={aggregated}, DISPLAY_DUPLICATES={duplicate_suffixes}")
print("GRAPH_IDENTITY_AND_SOURCE_HASH_INVARIANCE_PASS")
