#!/usr/bin/env python3
"""Durable, generated UE1 O06 checkpoint. No external Python dependencies."""
import argparse
import datetime as dt
import json
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
COMPLIANCE = ROOT / "catalog" / "compliance"
ACTIVE = COMPLIANCE / "UE1-O06-ACTIVE.json"
WORK = COMPLIANCE / "UE1-O06-WORK-EVENTS.jsonl"
TEST_EVENTS = COMPLIANCE / "UE1-O06-TEST-EVENTS.jsonl"
OUT = COMPLIANCE / "UE1-O06-LIVE-CHECKPOINT.md"

def now():
    return dt.datetime.now(dt.timezone.utc).isoformat(timespec="seconds")

def write_atomic(path, value):
    temp = path.with_suffix(path.suffix + ".tmp")
    temp.write_text(value, encoding="utf-8", newline="\n")
    os.replace(temp, path)

def read_jsonl(path):
    if not path.is_file():
        return []
    return [json.loads(line) for line in path.read_text(encoding="utf-8-sig").splitlines() if line.strip()]

def render(active):
    events = read_jsonl(TEST_EVENTS)
    latest = {}
    for e in events:
        if e.get("target", "DEV") == "DEV":
            latest[e.get("test", "")] = e
    recent = [e for e in events if e.get("target", "DEV") == "DEV"][-18:]
    counts = {k: sum(e.get("state") == k for e in latest.values())
              for k in ("STARTED", "PASS", "FAIL")}
    escape = lambda s: str(s or "").replace("|", "\\|").replace("\n", " ")
    lines = [
        "# UE1 O06 — live working checkpoint",
        "",
        "**Generated automatically after every DEV test STARTED/PASS/FAIL.** "
        "This is operational status, not evidence of full Epic parity.",
        "",
        f"- **Contract:** `{escape(active.get('contract', 'unassigned'))}`",
        f"- **Stage:** {escape(active.get('stage', 'not_started'))}",
        f"- **Last completed action:** {escape(active.get('last_action', 'none'))}",
        f"- **Resume at:** {escape(active.get('next_step', 'inspect source register'))}",
        f"- **Active changed at:** {escape(active.get('updated_at_utc', 'unknown'))}",
        f"- **Latest DEV test states:** PASS {counts['PASS']}; FAIL {counts['FAIL']}; STARTED {counts['STARTED']}",
        "- **Acceptance:** O06 IMPLEMENTING / NOT ACCEPTED; test coverage is not compiled-engine proof.",
        "",
        "## Recent test events (append-only evidence retained)",
        "",
        "| UTC | Test | State | Duration (ms) | Evidence |",
        "|---|---|---|---:|---|",
    ]
    for e in reversed(recent):
        evidence = escape(e.get("log", ""))
        lines.append(f"| {escape(e.get('event_at_utc', ''))} | "
                     f"{escape(e.get('test', ''))} | {escape(e.get('state', ''))} | "
                     f"{escape(e.get('duration_ms', ''))} | {evidence} |")
    lines += [
        "",
        "## Recovery procedure",
        "",
        "1. Read this checkpoint and `git status --short`.",
        "2. Inspect the latest O06 TEST-EVENTS record. A STARTED event without terminal result is interrupted.",
        "3. Resume only the pending contract and source inputs; use the scoped test runner.",
        "4. Do not delete logs, rerun unchanged PASS evidence or deploy until verified.",
        "",
        "Full test/source evidence: [UE1-O06-TEST-EVENTS.jsonl](UE1-O06-TEST-EVENTS.jsonl), "
        "[UE1-O06-SERIALIZER-REGISTER.json](UE1-O06-SERIALIZER-REGISTER.json).",
        "",
    ]
    write_atomic(OUT, "\n".join(lines))

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--activate", help="Epic source contract ID, e.g. t99-inherited-struct")
    ap.add_argument("--stage", help="current stage")
    ap.add_argument("--action", help="completed step")
    ap.add_argument("--next", dest="next_step", help="exact next action for timeout recovery")
    args = ap.parse_args()
    active = json.loads(ACTIVE.read_text(encoding="utf-8-sig")) if ACTIVE.is_file() else {
        "contract": "unassigned", "stage": "not_started",
        "last_action": "No active contract declared",
        "next_step": "Select one Epic serialization contract",
        "updated_at_utc": now(),
    }
    changed = False
    for attr, key in [("activate", "contract"), ("stage", "stage"),
                      ("action", "last_action"), ("next_step", "next_step")]:
        value = getattr(args, attr)
        if value is not None and active.get(key) != value:
            active[key] = value
            changed = True
    if changed:
        active["updated_at_utc"] = now()
        write_atomic(ACTIVE, json.dumps(active, indent=2, ensure_ascii=False) + "\n")
        with WORK.open("a", encoding="utf-8", newline="\n") as f:
            f.write(json.dumps({"at_utc": now(), **active},
                               separators=(",", ":"), ensure_ascii=False) + "\n")
            f.flush()
            os.fsync(f.fileno())
    elif not ACTIVE.exists():
        write_atomic(ACTIVE, json.dumps(active, indent=2) + "\n")
    render(active)
    print(f"O06_CHECKPOINT contract={active['contract']} stage={active['stage']} generated={OUT.name}")

if __name__ == "__main__":
    main()
