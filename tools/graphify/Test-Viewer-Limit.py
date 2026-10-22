#!/usr/bin/env python3
"""Live DEV HTML viewer slider acceptance; never modifies graph source or IDs."""
import json
import re
import urllib.error
import urllib.request
from pathlib import Path
import hashlib
import sys

ROOT = Path.home() / "Graphify"
BASE = "http://127.0.0.1:8765"


def api(path, method="GET", data=None, token=None, origin=True):
    headers = {}
    if data is not None:
        headers["Content-Type"] = "application/json"
    if token is not None:
        headers["X-Graphify-Token"] = token
    if method == "POST" and origin:
        headers["Origin"] = BASE
    req = urllib.request.Request(
        BASE + path, data=json.dumps(data).encode("utf8") if data is not None else None,
        method=method, headers=headers)
    try:
        with urllib.request.urlopen(req, timeout=12) as response:
            return response.status, json.loads(response.read()) if path.startswith("/api/") else response.read().decode("utf8")
    except urllib.error.HTTPError as error:
        return error.code, json.loads(error.read())


def viewer_html_nodes(graph_html: Path):
    content = graph_html.read_text(encoding="utf-8")
    prefix = "const RAW_NODES = "
    offset = content.index(prefix) + len(prefix)
    nodes, used = json.JSONDecoder().raw_decode(content[offset:])
    assert content[offset+used:].lstrip().startswith(";")
    assert len(nodes) == len({str(x["id"]) for x in nodes})
    assert len(nodes) == len({str(x["label"]).casefold() for x in nodes})
    return nodes


def preflight():
    with urllib.request.urlopen(BASE + "/", timeout=12) as response:
        page = response.read().decode("utf8")
    assert 'id="viewer-limit"' in page and 'min="2000" max="5000" step="500"' in page
    assert 'id="apply-viewer"' in page
    token = re.search(r'const LOCAL_GRAPHIFY_TOKEN="([^"]+)";', page)
    assert token, "Dashboard token missing"
    token = token.group(1)
    code, data = api("/api/viewer")
    assert code == 200 and data["minimum"] == 2000 and data["maximum"] == 5000 and data["step"] == 500
    old = data["node_limit"]
    for invalid in (1999, 5001, 4250, "5000", True):
        status, item = api("/api/viewer", "POST", {"node_limit":invalid}, token=token)
        assert status == 400, (invalid,status,item)
    status, item = api("/api/viewer", "POST", {"node_limit":5000})
    assert status == 403
    status, item = api("/api/viewer", "POST", {"node_limit":5000},token=token,origin=False)
    assert status == 403
    status, data = api("/api/viewer")
    assert status == 200 and data["node_limit"] == old
    print("VIEWER_HTML_VISIBLE_AND_API_RANGE_AUTH_PRECHECK_PASS")
    return token


def verify_all():
    code, state = api("/api/viewer")
    assert code == 200 and state["node_limit"] == 5000, state
    assert state["viewer"]["state"] in ("IDLE", "PASS"), state  # Service can restart after a PASS.
    before=json.loads((ROOT/"viewer-source-hashes-before.json").read_text(encoding="utf8"))
    total=0
    full=0
    aggregated=0
    for graph_file in ROOT.glob("*/graphify-out/graph.json"):
        project=graph_file.parent.parent.name
        digest=hashlib.sha256(graph_file.read_bytes()).hexdigest()
        assert digest==before[project],("graph.json changed",project)
        source=json.loads(graph_file.read_text(encoding="utf8"))
        visual=viewer_html_nodes(graph_file.parent/"graph.html")
        n=len(source["nodes"])
        if n<=5000:
            full+=1
            assert len(visual)==n,(project,n,len(visual))
            assert {str(x["id"]) for x in visual}=={str(x["id"]) for x in source["nodes"]}
        else:
            aggregated+=1
            communities={str(x["community"]) for x in source["nodes"]}
            assert len(visual)==len(communities),(project,len(communities),len(visual))
            assert len(visual)<=5000
            assert {str(x["id"]) for x in visual}==communities
        total+=1
    assert total==len(before)>=27
    print(f"GRAPHIFY_VIEWER_5000_ALL_PASS {total}/{total}, full={full}, condensed={aggregated}")
    print("ORIGINAL_GRAPH_JSON_SHA256_AND_IDS_UNCHANGED_ALL_PASS")


def main():
    mode=sys.argv[1] if len(sys.argv)>1 else "preflight"
    if mode=="preflight":
        preflight()
    elif mode=="apply":
        token=preflight()
        code, response=api("/api/viewer","POST",{"node_limit":5000},token)
        assert code==202,(code,response)
        print("VIEWER_5000_APPLY_ACCEPTED_ASYNC", response)
    elif mode=="verify":
        verify_all()
    else:
        raise ValueError("mode preflight/apply/verify")


if __name__=="__main__":
    main()
