#!/usr/bin/env python3
"""Dashboard HTTP and real folder-scan acceptance; local DEV only."""
import json
import re
import time
import urllib.error
import urllib.request

BASE="http://127.0.0.1:8765"
def req(path, method="GET", payload=None, token=None, origin=None, host=None):
    data=json.dumps(payload).encode("utf-8") if payload is not None else None
    headers={}
    if data is not None:headers["Content-Type"]="application/json"
    if token is not None:headers["X-Graphify-Token"]=token
    if origin is not None:headers["Origin"]=origin
    if host is not None:headers["Host"]=host
    request=urllib.request.Request(BASE+path, data=data, headers=headers, method=method)
    try:
        with urllib.request.urlopen(request,timeout=10) as response:
            return response.status, response.read().decode("utf-8")
    except urllib.error.HTTPError as exc:
        return exc.code,exc.read().decode("utf-8")
status,page=req("/")
assert status==200 and 'id="new-project"' in page
token=re.search(r'const LOCAL_GRAPHIFY_TOKEN="([^"]+)";',page)
assert token,"Server-injected CSRF token absent"
token=token.group(1)
status,body=req("/api/status")
assert status==200 and json.loads(body)["local_only"] is True
print("GET_LOCAL_DASHBOARD_PASS")
valid={"folder":r"L:\Source\Engine\UE1","name":"ue1-full-root"}
status,body=req("/api/add", "POST", valid, token, origin="https://example.com")
assert status==403,(status,body)
status,body=req("/api/add", "POST", valid, "invalid-token",origin=BASE)
assert status==403,(status,body)
status,body=req("/api/add", "POST", {"folder":r"Z:\This\Does\Not\Exist","name":"invalid"}, token,origin=BASE)
assert status==400,(status,body)
print("CSRF_AND_FOLDER_VALIDATION_PASS")
status,body=req("/api/add", "POST", valid,token,origin=BASE)
assert status==202,(status,body)
print("ADD_PROJECT_ACCEPTED",body)
for poll in range(100):
    status,body=req("/api/status")
    assert status==200,(status,body)
    jobs=json.loads(body)["jobs"]
    job=jobs.get("ue1-full-root",{})
    if job.get("state")=="PASS":
        status,page=req("/")
        assert status==200 and '"id": "ue1-full-root"' in page,(status,len(page))
        status,graph=req("/ue1-full-root/graphify-out/graph.html")
        assert status==200 and "<html" in graph.lower()
        print("INDEX_COMPLETE_HTML_GRAPH_AND_DASHBOARD_PASS",job)
        break
    if job.get("state")=="FAIL":
        raise RuntimeError("Index failed; see C:\\Users\\arden\\Graphify\\ue1-full-root\\scan.log")
    if poll%6==0:print("INDEX_STATUS",job)
    time.sleep(2)
else:
    raise RuntimeError("No completed index in test polling window")
