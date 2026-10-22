#!/usr/bin/env python3
"""Query only the Graphify graphs within a selected master project."""
import argparse
import json
import shutil
import subprocess
import sys
from pathlib import Path

ROOT=Path.home()/"Graphify"
def main():
    ap=argparse.ArgumentParser(description="Query scoped Graphify master/child graphs on DEV")
    ap.add_argument("--master", required=True, help="Master ID shown in dashboard")
    ap.add_argument("--query", required=True, help="Code query")
    ap.add_argument("--child", help="Optional exact child project ID; omit to query just this master's children")
    ap.add_argument("--budget", type=int, default=500)
    args=ap.parse_args()
    masters=json.loads((ROOT/"masters.json").read_text(encoding="utf-8-sig"))["masters"]
    if args.master not in {m["id"] for m in masters}:
        ap.error("Master project not found")
    graphs=[]
    for p in sorted(ROOT.iterdir()):
        meta=p/"project.json"
        graph=p/"graphify-out"/"graph.json"
        if not graph.is_file() or not meta.is_file():continue
        info=json.loads(meta.read_text(encoding="utf-8-sig"))
        if info.get("master") != args.master:continue
        if args.child is not None and p.name!=args.child:continue
        graphs.append((p.name,graph))
    if not graphs:
        ap.error("No child graphs found for that master/child combination")
    cli=ROOT.parent/".local"/"bin"/"graphify.exe"
    if not cli.exists():
        cli=Path(shutil.which("graphify") or "graphify")
    failures=0
    for child,graph in graphs:
        print(f"\n== MASTER {args.master} / CHILD {child} ==\n",flush=True)
        result=subprocess.run([str(cli),"query",args.query,"--graph",str(graph),"--budget",str(args.budget)],
                              stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,encoding="utf-8",
                              errors="replace",timeout=50)
        print(result.stdout,flush=True)
        if result.returncode:
            failures+=1
            print(f"CHILD_QUERY_FAILED {child}: {result.returncode}",file=sys.stderr)
    if failures:
        raise SystemExit(failures)
if __name__=="__main__":
    main()
