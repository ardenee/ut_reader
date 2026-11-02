#!/usr/bin/env python3
"""Self-contained operational verification of O06 scoped tests and checkpointing."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
sys.dont_write_bytecode = True
import tempfile

ROOT = Path(__file__).resolve().parents[2]
PLAN = ROOT / "catalog/compliance/UE1-O06-TEST-PLAN.json"
CHECKPOINT = ROOT / "catalog/bin/update-ue1-o06-checkpoint.py"
RUNNER = ROOT / "catalog/bin/run-ue1-o06-scoped.ps1"
checks = {}
def check(label, condition):
    checks[label] = bool(condition)

plan = json.loads(PLAN.read_text(encoding="utf-8-sig"))
check("plan_schema", plan.get("schema") == 1)
check("plan_contracts_exist", len(plan.get("contracts", {})) >= 5)
for key, entry in plan["contracts"].items():
    for tier in ("focus", "affected", "gate"):
        names = entry.get(tier, [])
        check(f"{key}.{tier}.unique", len(names) == len(set(names)))
        check(f"{key}.{tier}.exist", all((ROOT / "catalog/bin" / name).is_file() for name in names))
    check(f"{key}.owned_readers_exist", all(
        (ROOT / "catalog/src/Infrastructure/Readers" / name).is_file()
        for name in entry.get("readers", [])))

spec = importlib.util.spec_from_file_location("o06checkpoint", CHECKPOINT)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix="UE1O06checkpoint-") as tmp:
    temp = Path(tmp)
    module.ACTIVE = temp / "active.json"
    module.WORK = temp / "work.jsonl"
    module.TEST_EVENTS = temp / "tests.jsonl"
    module.OUT = temp / "live.md"
    active = {"contract": "self-test-contract", "stage": "testing",
              "last_action": "synthetic start", "next_step": "resume synthetic",
              "updated_at_utc": module.now()}
    module.write_atomic(module.ACTIVE, json.dumps(active) + "\n")
    begin = {"test": "verify-ue1-self-test.php", "target": "DEV",
             "state": "STARTED", "run_id": "self-test", "event_at_utc": module.now()}
    module.write_atomic(module.TEST_EVENTS, json.dumps(begin) + "\n")
    module.render(active)
    contents = module.OUT.read_text(encoding="utf-8")
    check("started_visible_in_live_checkpoint",
          "STARTED" in contents and "resume synthetic" in contents)
    finish = {**begin, "state": "PASS", "duration_ms": 12}
    with module.TEST_EVENTS.open("a", encoding="utf-8") as f:
        f.write(json.dumps(finish) + "\n")
    module.render(active)
    contents = module.OUT.read_text(encoding="utf-8")
    check("pass_replaces_in_progress_latest_state",
          "PASS 1; FAIL 0; STARTED 0" in contents)
    check("preserves_prior_started_event_in_history",
          contents.count("verify-ue1-self-test.php") == 2)
    check("active_file_retained", module.ACTIVE.exists())
result = subprocess.run(
    ["powershell", "-NoProfile", "-File", str(RUNNER), "-Contract",
     "t99-local-inherited-struct", "-Tier", "focus", "-ListOnly"],
    capture_output=True, text=True, timeout=30)
check("focus_preview_exits_cleanly", result.returncode == 0)
check("focus_preview_two_tests", "selected=2" in result.stdout and
      result.stdout.count("PLAN ") == 2)
check("source_runner_blocks_prd", "PRD test execution is disabled" in
      (ROOT / "catalog/bin/run-ue1-o06-tests.ps1").read_text())
check("live_per_event_hooks_present",
      (ROOT / "catalog/bin/run-ue1-o06-tests.ps1").read_text().count(
          "Update-LiveCheckpoint") == 3)
print(json.dumps({"ok": all(checks.values()), "checks": len(checks),
                  "passed": sum(checks.values()),
                  "failed": [key for key, ok in checks.items() if not ok],
                  "note": "Only synthetic isolated checkpoint state; no PRD or paid services."},
                 indent=2))
sys.exit(0 if all(checks.values()) else 1)
