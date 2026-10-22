#!/usr/bin/env python3
"""Make deterministic, offline, source-name labels for Graphify communities.

Does not change graph.json, source files, node identities or MCP data.
The HTML exporter consumes these names through --labels.
"""
import argparse
from collections import Counter, defaultdict
import json
from pathlib import Path
import re
import tempfile
import os

def generate(graph_file: Path, output_file: Path):
    graph = json.loads(graph_file.read_text(encoding="utf-8"))
    nodes = graph.get("nodes", [])
    edges = graph.get("links", graph.get("edges", []))
    degrees = Counter()
    for e in edges:
        u = e.get("source")
        v = e.get("target")
        # In NetworkX node-link JSON these are node identifiers, not list offsets.
        if u is not None:
            degrees[str(u)] += 1
        if v is not None:
            degrees[str(v)] += 1
    groups = defaultdict(list)
    for n in nodes:
        community = n.get("community")
        if community is None:
            continue
        groups[int(community)].append(n)
    if not groups:
        raise ValueError(f"No graph communities found in {graph_file}")

    reserved = set()
    labelled = {}
    repeated = 0
    examples = []
    for cid in sorted(groups):
        # Prefer identifiable source-backed objects over external dependency
        # stubs (e.g. bare PDO) and minifier identifiers (e.g. x()).
        # Within that pool, use Graphify's highest-degree hub rule.
        members = groups[cid]
        def readable_label(n):
            label = str(n.get("label") or "").strip().removesuffix("()")
            return len(label) >= 3 and any(ch.isalnum() for ch in label)
        concrete = [n for n in members if
            str(n.get("source_file") or "").strip() and readable_label(n)]
        meaningful = [n for n in members if readable_label(n)]
        choices = concrete or meaningful or members
        hub = min(choices, key=lambda n: (
            -degrees[str(n["id"])], str(n["id"])))
        original = str(hub.get("label") or "").strip()
        if original.endswith("()"):
            original = original[:-2]
        if not original:
            path = str(hub.get("source_file") or "")
            original = Path(path.replace("\\", "/")).stem or str(hub["id"])
        if not original:
            original = f"Community {cid}"
        name = original
        sequence = 2
        while name.casefold() in reserved:
            name = f"{original} #{sequence}"
            sequence += 1
        if name != original:
            repeated += 1
        reserved.add(name.casefold())
        labelled[str(cid)] = name
        if len(examples) < 10:
            examples.append(f"{cid}: {name} ({len(members)} nodes)")

    output_file.parent.mkdir(parents=True, exist_ok=True)
    output = json.dumps(labelled, ensure_ascii=False, indent=2) + "\n"
    fd, temp = tempfile.mkstemp(prefix=".community-names-", dir=str(output_file.parent))
    try:
        with os.fdopen(fd, "w", encoding="utf-8", newline="\n") as stream:
            stream.write(output)
        os.replace(temp, output_file)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)
    print(f"NAMED_COMMUNITIES={len(labelled)} DUPLICATES_DISAMBIGUATED={repeated} NODE_COUNT={len(nodes)}")
    for example in examples:
        print("  " + example)
    return labelled

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("graph", type=Path)
    parser.add_argument("--output", type=Path)
    args = parser.parse_args()
    target = args.output or args.graph.parent / ".graphify_display_labels.json"
    generate(args.graph, target)

if __name__ == "__main__":
    main()
