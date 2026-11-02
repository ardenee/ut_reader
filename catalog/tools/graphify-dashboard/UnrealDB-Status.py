#!/usr/bin/env python3
"""Read-only current UE1-UE5 contract view. Never reads archived journals."""
from pathlib import Path
from collections import Counter
from datetime import datetime, timezone
import json
import subprocess
import threading
import time

REPO = Path(__file__).resolve().parents[3] if (Path(__file__).resolve().parents[3] / "tests/contracts.json").is_file() else Path(r"C:\Users\arden\source\repos\ut_reader")
TESTS = REPO / "tests"
_CACHE = {"at": 0.0, "value": None}
_LOCK = threading.Lock()

def read(name):
    return json.loads((TESTS / name).read_text(encoding="utf-8-sig"))

def git_info():
    try:
        a = subprocess.run(["git", "-C", str(REPO), "log", "-3", "--format=%h%x09%cI%x09%s"],
                           capture_output=True, text=True, check=True, timeout=5)
        b = subprocess.run(["git", "-C", str(REPO), "status", "--porcelain", "--untracked-files=no"],
                           capture_output=True, text=True, check=True, timeout=5)
        commits = []
        for line in a.stdout.splitlines():
            p = line.split("\t", 2)
            if len(p) == 3:
                commits.append({"hash": p[0], "date": p[1], "subject": p[2][:160]})
        return {"available": True, "commits": commits, "tracked_changes": len(b.stdout.splitlines())}
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        return {"available": False, "commits": [], "tracked_changes": None}

def gather():
    c = read("contracts.json")
    paths = read("code-paths.json")
    definitions = {x["id"]: x for x in c["contracts"]}
    rows = c["engine_contracts"]
    counts = Counter(x["status"] for x in rows)
    engines = []
    for name in ("ue1", "ue2", "ue3", "ue4", "ue5"):
        subset = [x for x in rows if x["engine"] == name]
        terminal = all(x["status"] in ("PASS", "SOURCE_NA") for x in subset)
        engines.append({"id": name.upper(), "status": "ACCEPTED" if terminal else "NOT_REVIEWED",
                        "total": len(subset), "unreviewed": sum(x["status"] == "NOT_REVIEWED" for x in subset)})
    contracts = []
    for x in rows:
        a = definitions[x["contract_id"]]
        contracts.append({"id": x["key"], "status": x["status"], "branch": x["engine"],
                          "reader": ", ".join(a["code_path_roles"]), "remaining": a["user_risk"],
                          "purpose": a["purpose"], "source": None, "source_line": None,
                          "test": None})
    return {"ok": True, "read_only": True, "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
            "engine": "UE1–UE5", "operation": "FILE PARITY", "accepted": all(g["status"] == "ACCEPTED" for g in engines),
            "gates": engines,
            "source": {"total": len(rows), "counts": dict(counts), "updated_at": None},
            "tests": {"total": len(rows), "states": dict(counts), "events": []},
            "contracts": contracts, "test_plans": [],
            "active": {"contract": "UnrealDB end-to-end file parity", "stage": "INVENTORY",
                       "last_action": "Legacy results and test runners moved to read-only SHA archive.",
                       "next_step": "Trace source-selected production call paths and reuse existing native evidence.",
                       "updated_at_utc": None},
            "release": {"at": None, "description": "UE1 G3 historical 79 PASS / 1 SOURCE_NA, code deployed; no general UE1–UE5 acceptance asserted"},
            "git": git_info(), "graph_updated_at": None,
            "code_paths": {"total": paths["total_files"], "roles": paths["roles"]},
            "limitations": ["Filename-discovered roles do not demonstrate runtime reachability.",
                            "Archived original engine evidence is preserved but not counted as new end-to-end proof."]}

def snapshot():
    with _LOCK:
        if _CACHE["value"] is not None and time.monotonic() - _CACHE["at"] < 8:
            return _CACHE["value"]
        value = gather()
        _CACHE.update(at=time.monotonic(), value=value)
        return value

if __name__ == "__main__":
    print(json.dumps(snapshot(), indent=2, ensure_ascii=False))
