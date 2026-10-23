#!/usr/bin/env python3
"""Read-only live UnrealDB O06 status for the loopback-only Graphify UI.

No test execution, user-selected paths, writes, or external connections.
Every displayed result is sourced from the checked-out UnrealDB repository.
"""
from collections import Counter, deque
from datetime import datetime, timezone
from pathlib import Path
import json
import re
import subprocess
import threading
import time

REPO = Path(r"C:\Users\arden\source\repos\ut_reader")
COMPLIANCE = REPO / "catalog" / "compliance"
_CACHE = {"at": 0.0, "value": None}
_LOCK = threading.Lock()


def json_file(name, default):
    path = COMPLIANCE / name
    try:
        return json.loads(path.read_text(encoding="utf-8-sig"))
    except (OSError, ValueError, TypeError):
        return default


def tail_jsonl(name, limit=40):
    path = COMPLIANCE / name
    try:
        with path.open(encoding="utf-8-sig") as stream:
            lines = deque(stream, maxlen=limit)
        return [json.loads(line) for line in lines if line.strip()]
    except (OSError, ValueError, TypeError):
        return []


def git_info():
    try:
        command = ["git", "-C", str(REPO)]
        result = subprocess.run(
            command + ["log", "-3", "--format=%h%x09%cI%x09%s"],
            text=True, capture_output=True, timeout=5, check=True)
        commits = []
        for line in result.stdout.splitlines():
            fields = line.split("\t", 2)
            if len(fields) == 3:
                commits.append({"hash": fields[0], "date": fields[1],
                                "subject": fields[2][:180]})
        result = subprocess.run(
            command + ["status", "--porcelain", "--untracked-files=no"],
            text=True, capture_output=True, timeout=5, check=True)
        return {"commits": commits,
                "tracked_changes": len(result.stdout.splitlines()),
                "available": True}
    except (OSError, subprocess.TimeoutExpired, subprocess.CalledProcessError):
        return {"commits": [], "tracked_changes": None, "available": False}


def gate_statuses():
    gates = [{"id": f"G{i}", "status": "OPEN"} for i in range(1, 7)]
    try:
        content = (COMPLIANCE / "PROJECT-STATUS.md").read_text(
            encoding="utf-8-sig")
    except OSError:
        return gates
    for line in content.splitlines():
        match = re.match(r"^\|\s*\*\*(G[1-6])\s+—[^|]*\|\s*[^|]*\|\s*(.*?)\s*\|", line)
        if not match:
            continue
        stage, statement = match.groups()
        if stage == "G1" and re.search(r"\bACCEPTED\b", statement, re.I):
            gates[0]["status"] = "ACCEPTED"
        elif re.search(r"\bBLOCKED\b", statement, re.I):
            gates[int(stage[1])-1]["status"] = "BLOCKED"
        elif re.search(r"\bACCEPTED\b", statement, re.I) and not re.search(
            r"NOT ACCEPTED|PARTIAL|OPEN", statement, re.I):
            gates[int(stage[1])-1]["status"] = "ACCEPTED"
    return gates


def gather():
    active = json_file("UE1-O06-ACTIVE.json", {})
    register = json_file("UE1-O06-SERIALIZER-REGISTER.json", {})
    tests = json_file("UE1-O06-TEST-STATUS.json", {})
    plan = json_file("UE1-O06-TEST-PLAN.json", {})
    entries = register.get("entries", [])
    counts = Counter(x.get("status", "UNKNOWN") for x in entries)
    tests_now = [x for x in tests.get("tests", [])
                 if x.get("target") == "DEV"]
    states = Counter(x.get("state", "UNKNOWN") for x in tests_now)
    event_rows = [
        {"time": e.get("event_at_utc"), "test": e.get("test"),
         "state": e.get("state"), "duration_ms": e.get("duration_ms"),
         "run_id": e.get("run_id"), "commit": e.get("commit")}
        for e in tail_jsonl("UE1-O06-TEST-EVENTS.jsonl", 70)
        if e.get("target") == "DEV"
        and e.get("state") in ("STARTED", "PASS", "FAIL")
    ]
    # The most recent event at the tail comes first in the visual timeline.
    event_rows.reverse()
    work = tail_jsonl("UE1-O06-WORK-EVENTS.jsonl", 30)
    releases = [x for x in work if x.get("stage") == "released-verified"]
    last_release = releases[-1] if releases else None
    contracts = []
    for e in entries:
        locations = e.get("locations", [])
        source = locations[0] if locations else {}
        contracts.append({
            "id": e.get("id"), "branch": e.get("branch"),
            "status": e.get("status"), "reader": str(e.get("reader") or "")[:250],
            "test": e.get("test"), "remaining": str(e.get("remaining") or "")[:650],
            "source": source.get("path"), "source_line": source.get("line"),
            "updated_at": e.get("updated_at_utc"),
        })
    contract_plans = [
        {"id": key, "description": value.get("description"),
         "focus": len(value.get("focus", [])),
         "affected": len(value.get("affected", [])),
         "gate": len(value.get("gate", []))}
        for key, value in plan.get("contracts", {}).items()]
    try:
        graph_stamp = datetime.fromtimestamp(
            (Path(__file__).parent / "unrealdb/graphify-out/graph.json").stat().st_mtime,
            tz=timezone.utc).isoformat(timespec="seconds")
    except OSError:
        graph_stamp = None
    return {
        "ok": True, "read_only": True, "generated_at": datetime.now(
            timezone.utc).isoformat(timespec="seconds"),
        "engine": "UE1", "operation": "O06", "accepted": False,
        "active": {k: active.get(k) for k in
                   ("contract", "stage", "last_action", "next_step",
                    "updated_at_utc")},
        "gates": gate_statuses(),
        "source": {"total": len(entries), "counts": dict(counts),
                   "updated_at": register.get("updated_at_utc")},
        "tests": {"total": len(tests_now), "states": dict(states),
                  "updated_at": tests.get("updated_at_utc"),
                  "events": event_rows[:24]},
        "contracts": contracts,
        "test_plans": contract_plans,
        "release": ({"at": last_release.get("updated_at_utc"),
                     "description": last_release.get("last_action")}
                    if last_release else None),
        "git": git_info(), "graph_updated_at": graph_stamp,
        "limitations": [
            "Synthetic/regression PASS is not independent Epic parity.",
            "Source status PARTIAL does not mean the contract is accepted.",
            "Release status is the last journaled deployment, not a live PRD audit.",
        ],
    }


def snapshot():
    # Multiple UI polls share the immutable recent snapshot.
    with _LOCK:
        if _CACHE["value"] is not None and time.monotonic() - _CACHE["at"] < 8:
            return _CACHE["value"]
        result = gather()
        _CACHE.update(at=time.monotonic(), value=result)
        return result


if __name__ == "__main__":
    print(json.dumps(snapshot(), indent=2, ensure_ascii=False))
