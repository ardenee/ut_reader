#!/usr/bin/env python3
"""Disambiguate duplicate HTML visual labels without changing Graphify graph data.

Operates on Graphify's native RAW_NODES JSON literal, retains stable node ids,
and changes only repeated on-screen labels. Does not rewrite graph.json.
"""
import argparse
from collections import Counter
import json
import os
from pathlib import Path
import tempfile

def update(html_path: Path) -> int:
    contents = html_path.read_text(encoding="utf-8")
    marker = "const RAW_NODES = "
    at = contents.find(marker)
    if at < 0:
        raise RuntimeError("Graphify RAW_NODES data not found; exporter changed")
    start = at + len(marker)
    decoder = json.JSONDecoder()
    nodes, consumed = decoder.raw_decode(contents[start:])
    end = start + consumed
    if not isinstance(nodes, list) or not contents[end:].lstrip().startswith(";"):
        raise RuntimeError("Unrecognized Graphify RAW_NODES structure")
    ids = [str(n["id"]) for n in nodes]
    if len(ids) != len(set(ids)):
        raise RuntimeError("Graphify display ids are not unique")
    names = Counter(str(n.get("label") or "").casefold() for n in nodes
                    if str(n.get("label") or "").strip())
    original_names = set(names)
    seen = Counter()
    used = set()
    changed = 0
    for node in nodes:
        original = str(node.get("label") or "")
        if not original.strip():
            continue
        key = original.casefold()
        seen[key] += 1
        display = original
        if seen[key] > 1:
            number = 2
            display = f"{original} #{number}"
            while display.casefold() in original_names or display.casefold() in used:
                number += 1
                display = f"{original} #{number}"
            changed += 1
            node["label"] = display
            if node.get("title") == original:
                node["title"] = display
        used.add(display.casefold())
    if changed:
        payload = json.dumps(nodes, ensure_ascii=False)
        edited = contents[:start] + payload + contents[end:]
        fd, temp = tempfile.mkstemp(prefix=".named-html-",dir=str(html_path.parent))
        try:
            with os.fdopen(fd,"w",encoding="utf8",newline="\n") as handle:
                handle.write(edited)
            os.replace(temp,html_path)
        finally:
            if os.path.exists(temp):
                os.unlink(temp)
    print(f"VISUAL_NODES={len(nodes)} DUPLICATE_DISPLAY_SUFFIXES={changed}")
    return changed

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("html",type=Path)
    args = parser.parse_args()
    update(args.html)

if __name__=="__main__":
    main()
