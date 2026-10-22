#!/usr/bin/env python3
"""Local-only Graphify UI/scan service. No public/LAN listener or shell execution."""
import json
import re
import secrets
import subprocess
import sys
import threading
import traceback
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import unquote, urlsplit
import importlib.util
MASTER_SPEC = importlib.util.spec_from_file_location('graphify_masters', Path(__file__).with_name('Project-Masters.py'))
MASTER_LIB = importlib.util.module_from_spec(MASTER_SPEC)
MASTER_SPEC.loader.exec_module(MASTER_LIB)

HOST, PORT = "127.0.0.1", 8765
ROOT = Path.home() / "Graphify"
INDEX = ROOT / "index.html"
SCRIPT = ROOT / "Index-Code.ps1"
TOKEN = secrets.token_urlsafe(32)
ACTIVE = {}
LOCK = threading.Lock()
LOG = ROOT / "dashboard-service.log"

def time_now():
    return datetime.now(timezone.utc).isoformat(timespec="seconds")

def save_event(event):
    with (ROOT / "Dashboard-Index-Journal.jsonl").open("a", encoding="utf-8") as handle:
        handle.write(json.dumps({"at_utc": time_now(), **event}, ensure_ascii=False) + "\n")

def scan(name, folder):
    directory = ROOT / name
    log = directory / "scan.log"
    try:
        with log.open("a", encoding="utf-8") as output:
            output.write(f"\n=== {time_now()} INDEX START {folder} ===\n")
            output.flush()
            result = subprocess.run(
                ["powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy",
                 "Bypass", "-File", str(SCRIPT), "-Name", name, "-Source", str(folder),
                 "-Workers", "4"],
                stdout=output, stderr=subprocess.STDOUT, stdin=subprocess.DEVNULL,
                shell=False, check=False)
        state = "PASS" if result.returncode == 0 and (directory / "graphify-out" / "graph.json").exists() else "FAIL"
        save_event({"id": name, "state": state, "exit_code": result.returncode})
        with LOCK:
            ACTIVE[name] = {"state": state, "at_utc": time_now(),
                            "exit_code": result.returncode}
    except Exception:
        with log.open("a", encoding="utf-8") as output:
            traceback.print_exc(file=output)
        save_event({"id": name, "state": "FAIL", "reason": "exception: see scan.log"})
        with LOCK:
            ACTIVE[name] = {"state": "FAIL", "at_utc": time_now()}

class Handler(BaseHTTPRequestHandler):
    def safe_host(self):
        return self.headers.get("Host") in (f"127.0.0.1:{PORT}", f"localhost:{PORT}")
    def send_body(self, code, content, content_type):
        self.send_response(code)
        self.send_header("Content-Type", content_type)
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        if self.path in ("/", "/index.html"):
            self.send_header("Content-Security-Policy", "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:")
        self.send_header("Content-Length", str(len(content)))
        self.end_headers()
        self.wfile.write(content)
    def send_json(self, status, payload):
        self.send_body(status, json.dumps(payload, ensure_ascii=False).encode("utf-8"), "application/json; charset=utf-8")
    def do_GET(self):
        if not self.safe_host():
            return self.send_json(403, {"error": "Unrecognized Host"})
        path = unquote(urlsplit(self.path).path)
        if path in ("/", "/index.html"):
            data = INDEX.read_text(encoding="utf-8")
            data = data.replace("<script>", f'<script>const LOCAL_GRAPHIFY_TOKEN={json.dumps(TOKEN)};</script><script>', 1)
            return self.send_body(200, data.encode("utf-8"), "text/html; charset=utf-8")
        if path == "/api/masters":
            return self.send_json(200, {"masters": MASTER_LIB.read_masters()})
        if path == "/api/status":
            with LOCK:
                jobs = dict(ACTIVE)
            return self.send_json(200, {"jobs": jobs, "local_only": True})
        m = re.fullmatch(r"/([a-z0-9][a-z0-9_.-]{0,63})/graphify-out/graph\.html", path)
        if m:
            file = ROOT / m[1] / "graphify-out" / "graph.html"
            if file.is_file():
                return self.send_body(200, file.read_bytes(), "text/html; charset=utf-8")
        return self.send_json(404, {"error": "Not found"})
    def do_POST(self):
        if not self.safe_host():
            return self.send_json(403, {"error": "Unrecognized Host"})
        endpoint = urlsplit(self.path).path
        if endpoint not in ("/api/add", "/api/masters"):
            return self.send_json(404, {"error": "Not found"})
        if self.headers.get("Origin") not in (f"http://127.0.0.1:{PORT}", f"http://localhost:{PORT}"):
            return self.send_json(403, {"error": "Origin must be local dashboard"})
        if self.headers.get("X-Graphify-Token") != TOKEN:
            return self.send_json(403, {"error": "Invalid session token"})
        if not self.headers.get("Content-Type", "").lower().startswith("application/json"):
            return self.send_json(415, {"error": "JSON required"})
        try:
            size = int(self.headers.get("Content-Length", "0"))
            if not 0 < size <= 4096: raise ValueError("Request size invalid")
            request = json.loads(self.rfile.read(size))
            if endpoint == "/api/masters":
                with LOCK:
                    master_id=MASTER_LIB.add_master(request.get("name", ""))
                return self.send_json(201, {"ok": True, "id": master_id})
            master = MASTER_LIB.validate_master(request.get("master", "unrealdb"))
            folder = request.get("folder", "")
            custom_name = request.get("name", "")
            if not isinstance(folder, str) or len(folder) > 1024: raise ValueError("Invalid folder")
            folder = folder.strip().strip('"')
            source = Path(folder)
            if not source.is_dir(): raise ValueError("Folder does not exist on DEV")
            if not isinstance(custom_name, str) or len(custom_name) > 64: raise ValueError("Invalid project name")
            name = custom_name.strip() or source.name
            slug = re.sub(r"[^a-z0-9_.-]+", "-", name.lower()).strip(".-")
            if not slug or len(slug) > 64: raise ValueError("Invalid project name")
            # Keep existing UnrealDB graph IDs stable; namespace all other masters.
            if master != "unrealdb":
                slug = master[:28] + "-" + slug[:35]
            with LOCK:
                if any(job["state"] == "STARTED" for job in ACTIVE.values()):
                    return self.send_json(409, {"error": "Another graph is being indexed. Try again after it finishes."})
                target = ROOT / slug
                existing = target / "project.json"
                if existing.exists():
                    old = json.loads(existing.read_text(encoding="utf-8-sig"))
                    if old.get("source", "").casefold() != str(source).casefold():
                        return self.send_json(409, {"error": f"Project ID '{slug}' exists for another source. Enter a unique project name."})
                target.mkdir(parents=True, exist_ok=True)
                family = next((n for n in ("UE1", "UE2", "UE3", "UE4", "UE5") if n.lower() in slug), "Project")
                meta = {"name": name, "family": family, "master": master, "version": "", "source": str(source),
                        "detail": "Added from local dashboard; code-only graph"}
                existing.write_text(json.dumps(meta, indent=2), encoding="utf-8")
                ACTIVE[slug] = {"state": "STARTED", "at_utc": time_now()}
                save_event({"id": slug, "state": "STARTED", "source": str(source), "master": master})
                threading.Thread(target=scan, args=(slug, source), daemon=True).start()
            return self.send_json(202, {"ok": True, "id": slug, "source": str(source),
                                         "message": "Index started. Dashboard will refresh when ready."})
        except (ValueError, TypeError, json.JSONDecodeError, OSError) as exc:
            return self.send_json(400, {"error": str(exc)})

def main():
    ROOT.mkdir(exist_ok=True)
    print(f"Graphify dashboard serving only http://{HOST}:{PORT}", flush=True)
    with ThreadingHTTPServer((HOST, PORT), Handler) as server:
        server.serve_forever()

if __name__ == "__main__":
    main()
