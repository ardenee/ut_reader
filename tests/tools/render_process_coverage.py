#!/usr/bin/env python3
"""Local-only, read-only process navigator from the ONE UnrealDB contract register.

A source-index entry is a candidate, not proof of an executed path. Do not turn
PASS statuses into blanket Epic engine parity; original tests remain scoped.
"""
import argparse
import json
import html
import pathlib
import re
import collections

ROOT=pathlib.Path(__file__).resolve().parents[2]

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--output",type=pathlib.Path,required=True)
    opts=parser.parse_args()
    contracts=json.loads((ROOT/"tests/contracts.json").read_text("utf-8"))
    paths=json.loads((ROOT/"tests/code-paths.json").read_text("utf-8"))
    rows=[]
    for item in contracts["engine_contracts"]:
        rows.append({
            "key":item["key"],"engine":item["engine"],"operation":item["operation"],
            "status":item["status"],"source":item["epic_source_anchor_file"],
            "paths":item["actual_unrealdb_paths"],"risk":item["test_purpose"],
            "evidence":item.get("result_evidence") or "",
            "extra":item.get("additional_evidence",[]),
            "source_sites":item.get("php_source_sites",[]),
            "runtime_verified_for_recorded_scope":item.get("runtime_call_chain_verified",False),
            "scope_note":"Trace does not yet include a native UE5 classic mount session" if item["key"]=="ue5-mount-provider"
                else "Only registered scope tested; independent untraced branches are not certified",
        })
    index=paths["paths"]
    probable=[r["path"] for r in index if r.get("review_priority")=="ENTRYPOINT" and
              re.search(r"file|pak|iostore|download|upload|source|scan|import|dependency|missing|export|archive|package|unverified|asset",r["path"],re.I)]
    counts=dict(collections.Counter(r["status"] for r in rows))
    operation_definitions=json.loads((ROOT/"tests/tools/operation_inventory_definitions.json").read_text("utf-8"))
    operation_rows=[]
    engine_cells=0
    for index,op in enumerate(operation_definitions,1):
        engine_cells+=len(op["engines"])
        trace=[]
        for step in op.get("route_evidence",[]):
            source_path=ROOT/step["path"]
            contents=source_path.read_text("utf-8","replace")
            offset=contents.find(step["anchor"])
            if offset<0:
                raise RuntimeError("Operation route anchor not found: "+op["name"]+" / "+step["path"])
            line=contents.count("\n",0,offset)+1
            trace.append({"file":step["path"],"line":line,"anchor":step["anchor"]})
        operation_rows.append({
            "id":index,"name":op["name"],"group":op["group"],"engines":op["engines"],
            "area":op["area"],"route":trace,"source_anchors_checked":len(trace)>0,
            "runtime_verified_in_this_inventory":False
        })
    data={"cases":rows,"entrypoints":probable,"operations":operation_rows,
          "applicable_engine_cells":engine_cells,"static_route_count":sum(x["source_anchors_checked"] for x in operation_rows),
          "source_files_indexed":paths["total_files"],
          "statuses":counts,"categories":contracts["contracts"],
          "runtime_example":"ue5-mount-provider","warning":"Static or historical evidence only unless explicitly shown as Xdebug observed."}
    payload=json.dumps(data,ensure_ascii=False).replace("</","<\\/")
    out=opts.output
    out.parent.mkdir(parents=True,exist_ok=True)
    page='''<!doctype html><html lang="en"><meta charset="utf-8">
<title>UnrealDB / Epic | Process audit coverage</title>
<style>
body{font:14px/1.5 system-ui,Arial,sans-serif;color:#172638;background:#f6f8fb;margin:0}
header{background:#10263c;color:#fff;padding:1.4rem 2rem}
h1{margin:.1rem 0;font-size:1.6rem}header p{opacity:.85}
main{max-width:1480px;margin:auto;padding:1rem 1.5rem}
label{margin-right:.7rem}select,input{padding:.55rem;border:1px solid #aac0d1;border-radius:6px}
.controls{padding:1rem;background:#fff;position:sticky;top:0;z-index:2;display:flex;flex-wrap:wrap;gap:.7rem}
.card{margin:.7rem 0;padding:1rem;background:#fff;border:1px solid #cfd9e3;border-radius:9px}
.card summary{font-weight:600;cursor:pointer}.sub{color:#52606c}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}
.metric{padding:1rem;background:#fff;border:1px solid #cfd9e3;border-radius:8px}
.number{font-size:1.6rem;font-weight:700}.warning{background:#fff9e8;border-left:4px solid #a46b22;padding:1rem}
.code{font:12px/1.6 Consolas,monospace;overflow-wrap:anywhere}
a{color:#15629c}pre{white-space:pre-wrap;overflow-wrap:anywhere}strong{font-weight:700}
.status{padding:2px 7px;background:#e7edf3;border-radius:9px}
footer{margin:1rem 0;color:#55657a}
</style><header><h1>UnrealDB / Epic — process audit</h1>
<p>Every row comes from the existing 32-case contract register; no new tests or PASS counters are invented.</p>
</header><main><section class="warning"><strong>Coverage is not certification.</strong>
Function and file links from static analysis are candidates. Actual PHP execution is verified only for the referenced observed trace; Epic runtime mount state was not captured.
Historical PASS means only that the previously recorded scope passed.</section>
<section class="grid" id="metrics"></section>
<details class="card" open><summary>UnrealDB operations mapped from actual source (NOT Epic verification)</summary>
<p class="sub">The inventory is discovery-only. Every checked route is a possible source path; no new PHP runtime or Epic engine execution is claimed from this view. Five UE engines, with separate UE5 classic and Zen branches.</p>
<section class="controls"><label>Source engine / format <select id="operation-engine"><option value="">All</option><option value="1">UE1</option><option value="2">UE2</option><option value="3">UE3</option><option value="4">UE4</option><option value="C">UE5 classic</option><option value="Z">UE5 Zen</option></select></label><label>Find operation / file <input id="operation-search" placeholder="import, IoStore, provider"></label></section>
<p id="operation-count" class="sub"></p><div id="operation-rows"></div></details>
<p><a href="/unrealdb/graphify-out/process-comparison.html">Open the observed UE5 classic PHP / Epic source process comparison</a></p>
<section class="controls"><label>Engine <select id="engine"><option value="">All engines</option>
<option value="ue1">UE1</option><option value="ue2">UE2</option><option value="ue3">UE3</option><option value="ue4">UE4</option><option value="ue5">UE5</option></select></label>
<label>Status <select id="status"><option value="">All statuses</option><option value="BLOCKED">BLOCKED</option><option value="PASS">PASS, scoped</option></select></label>
<label>Find path / operation <input id="search" placeholder="mount, import, file-info.php"></label></section>
<div id="cases"></div><details class="card"><summary>Untraced candidate web entrypoints</summary><p class="sub">Discovered from source names only; inclusion does NOT imply an active route or Epic parity proof.</p><pre id="entrypoints" class="code"></pre></details>
<footer>Source of truth: tests/contracts.json and tests/code-paths.json on DEV. Epic originals remain under L:\\Source.</footer></main>
<script type="application/json" id="data">'''+payload+'''</script>
<script>
const d=JSON.parse(document.getElementById('data').textContent);
const esc=x=>String(x??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
document.getElementById('metrics').innerHTML=[
['Contract routes',d.cases.length],['Scoped prior PASS',d.statuses.PASS||0],
['Reopened / blocked',d.statuses.BLOCKED||0],['Indexed source files',d.source_files_indexed],
['Potential web entrypoints',d.entrypoints.length],
['Source-mapped operations, NOT verified',d.operations.length],
['Applicable engine/format cells',d.applicable_engine_cells],
['Checked static source routes',d.static_route_count]
].map(x=>'<div class="metric"><div class="number">'+esc(x[1])+'</div>'+esc(x[0])+'</div>').join('');
document.getElementById('entrypoints').textContent=d.entrypoints.join('\\n');
function drawOperations(){
 const e=document.getElementById('operation-engine').value;
 const q=document.getElementById('operation-search').value.trim().toLowerCase();
 const filtered=d.operations.filter(op=>(!e||op.engines.includes(e))
   &&(!q||JSON.stringify(op).toLowerCase().includes(q)));
 document.getElementById('operation-count').textContent=
   filtered.length+' source-mapped operations shown. Static references only; runtime traces and Epic parity are separate.';
 document.getElementById('operation-rows').innerHTML=filtered.map(op=>{
   const source=op.route.map(step=>'<a target="_blank" rel="noopener noreferrer" href="https://github.com/ardenee/ut_reader/blob/main/'+encodeURI(step.file)+'#L'+step.line+'">'
      +esc(step.file.split('/').pop())+':'+step.line+'</a> ('+esc(step.anchor)+')').join(' → ');
   return '<details class="card"><summary>#'+op.id+' '+esc(op.name)+' <span class="status">SOURCE ONLY</span></summary>'
     +'<p><strong>Group:</strong> '+esc(op.group)+'; <strong>Area:</strong> '+esc(op.area)+'</p>'
     +'<p class="code">'+source+'</p><p class="sub">No new original-engine execution or end-to-end parity result asserted.</p></details>';
 }).join('')||'<p>No matching operation.</p>';
}
['operation-engine','operation-search'].forEach(id=>document.getElementById(id).addEventListener('input',drawOperations));
drawOperations();
function draw(){
 const engine=document.getElementById('engine').value,status=document.getElementById('status').value;
 const query=document.getElementById('search').value.trim().toLowerCase();
 const rows=d.cases.filter(r=>(!engine||r.engine===engine)&&(!status||r.status===status)
   &&(!query||JSON.stringify(r).toLowerCase().includes(query)));
 document.getElementById('cases').innerHTML=rows.map(r=>
   '<details class="card"><summary>'+esc(r.key)+' <span class="status">'+esc(r.status)+'</span>'
   + (r.key==='ue5-mount-provider'?' (Xdebug observed)':'')+'</summary>'
   +'<p><strong>Risk:</strong> '+esc(r.risk)+'</p>'
   +'<p class="sub">'+esc(r.scope_note)+'</p>'
   +'<p><strong>Epic source file:</strong> <span class="code">'+esc(r.source)+'</span></p>'
   +'<p><strong>Potential UnrealDB path:</strong></p><pre class="code">'+r.paths.map(esc).join('\\n')+'</pre>'
   +'<p><strong>Retained evidence:</strong> <span class="code">'+esc(r.evidence)+'</span></p>'
   +(r.extra.length?'<p>Additional evidence: '+r.extra.map(esc).join(', ')+'</p>':'')
   +'</details>').join('')||'<p>No matching operation cases.</p>';
}
['engine','status','search'].forEach(id=>document.getElementById(id).addEventListener('input',draw));
draw();
</script></html>'''
    out.write_text(page,encoding="utf-8")
    print(json.dumps({"output":str(out),"cases":len(rows),"blocked":counts.get("BLOCKED",0),
          "static_entrypoint_candidates":len(probable),"file_index":paths["total_files"],
          "certified_new_runtime_paths":0},indent=2))
if __name__=="__main__":
    main()
