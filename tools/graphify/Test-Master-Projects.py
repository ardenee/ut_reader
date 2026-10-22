#!/usr/bin/env python3
"""Master grouping regression, including real loopback API and scoped graph query."""
import json
import re
import subprocess
import sys
import urllib.error
import urllib.request
from pathlib import Path

ROOT=Path.home()/"Graphify"
BASE="http://127.0.0.1:8765"
def request(path,method="GET",body=None,token=None):
    headers={}
    if body is not None:headers["Content-Type"]="application/json"
    if token is not None:headers["X-Graphify-Token"]=token
    if method=="POST":headers["Origin"]=BASE
    raw=json.dumps(body).encode("utf-8") if body is not None else None
    req=urllib.request.Request(BASE+path,data=raw,headers=headers,method=method)
    try:
        with urllib.request.urlopen(req,timeout=10) as resp:
            return resp.status,resp.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        return exc.code,exc.read().decode("utf-8")
status,body=request("/")
assert status==200
assert 'id="master-project"' in body and 'id="new-master"' in body
token=re.search(r'const LOCAL_GRAPHIFY_TOKEN="([^"]+)";',body)
assert token
token=token.group(1)
status,raw=request("/api/masters")
assert status==200
initial=json.loads(raw)["masters"]
assert {"unrealdb","other"}.issubset({x["id"] for x in initial})
assert all(x["master"]=="unrealdb" for x in [json.loads(p.read_text(encoding="utf-8-sig")) for p in ROOT.glob("*/project.json")])
print("MASTER_INDEX_AND_13_EXISTING_CHILDREN_PASS")
status,raw=request("/api/masters", "POST", {"name":"Temporary Master QA"})
assert status==403
status,raw=request("/api/add", "POST", {"name":"bad","folder":str(ROOT),"master":"unknown-master"},token)
assert status==400
print("MASTER_VALIDATION_PASS")
created=None
try:
    status,raw=request("/api/masters","POST",{"name":"Temporary Master QA"},token)
    assert status==201,(status,raw)
    created=json.loads(raw)["id"]
    status,raw=request("/api/masters")
    assert created in {m["id"] for m in json.loads(raw)["masters"]}
    status,page=request("/?master="+created)
    assert status==200 and f'"id": "{created}"' in page, "Created master not published to dashboard"
    print("CREATE_NEW_MASTER_API_AND_UI_PASS",created)
finally:
    if created:
        file=ROOT/"masters.json"
        obj=json.loads(file.read_text(encoding="utf-8-sig"))
        obj["masters"]=[m for m in obj["masters"] if m["id"]!=created]
        file.write_text(json.dumps(obj,ensure_ascii=False,indent=2),encoding="utf-8")
subprocess.run([sys.executable,str(ROOT/"Build-Dashboard.py")],check=True,stdout=subprocess.DEVNULL)
query=subprocess.run([sys.executable,str(ROOT/"Query-Master.py"),"--master","unrealdb","--child","unrealdb",
                      "--query","CatalogUE1OriginalUnrealfileDatabaseDataReader","--budget","160"],
                     capture_output=True,text=True,encoding="utf-8",errors="replace",timeout=60)
assert query.returncode==0,(query.returncode,query.stdout[:200],query.stderr)
print("SCOPED_UNREALDB_QUERY_PASS")
blocked=subprocess.run([sys.executable,str(ROOT/"Query-Master.py"),"--master","other","--child","unrealdb",
                        "--query","CatalogUE1OriginalUnrealfileDatabaseDataReader","--budget","100"],
                       capture_output=True,text=True,timeout=15)
assert blocked.returncode!=0 and "No child graphs" in blocked.stderr
print("CROSS_MASTER_QUERY_BLOCKED_PASS")
print("MASTER_PROJECT_ACCEPTANCE_PASS")
