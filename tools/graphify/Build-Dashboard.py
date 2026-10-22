#!/usr/bin/env python3
"""Generate an offline local Graphify project dashboard. Never uploads graphs."""
import json
import os
import re
import html
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parent
OUTPUT = ROOT / "index.html"

def entries():
    projects=[]
    for directory in sorted(ROOT.iterdir()):
        if not directory.is_dir(): continue
        graph=directory/"graphify-out"/"graph.json"
        if not graph.is_file(): continue
        meta_path=directory/"project.json"
        try:
            meta=json.loads(meta_path.read_text(encoding="utf-8-sig")) if meta_path.exists() else {}
            data=json.loads(graph.read_text(encoding="utf-8"))
            nodes=len(data.get("nodes", []))
            edges=len(data.get("links", data.get("edges", [])))
            communities=len({str(x.get("community")) for x in data.get("nodes", []) if x.get("community") is not None})
            stat=graph.stat()
            projects.append({
                "id":directory.name,
                "name":str(meta.get("name") or directory.name),
                "family":str(meta.get("family") or ("UE1" if "ue1" in directory.name.lower() else "Other")),
                "version":str(meta.get("version") or ""),
                "source":str(meta.get("source") or ""),
                "detail":str(meta.get("detail") or ""),
                "nodes":nodes, "edges":edges, "communities":communities,
                "size":stat.st_size,
                "date":datetime.fromtimestamp(stat.st_mtime, tz=timezone.utc).isoformat(timespec="seconds"),
                "visual":(directory/"graphify-out"/"graph.html").exists(),
                "graph_path":str(graph),
            })
        except (OSError, json.JSONDecodeError, TypeError, ValueError) as exc:
            print(f"SKIPPED {directory.name}: {exc}")
    return projects

STYLE=r"""
:root{color-scheme:dark;--bg:#0b1020;--panel:#121c2d;--line:#273b52;--text:#e7f2ff;--dim:#a2b4ca;--accent:#42d8c7}
*{box-sizing:border-box}body{margin:0;background:radial-gradient(ellipse 70% 45% at 90% 0,#113f4b 0,transparent 80%),var(--bg);font:15px/1.5 system-ui,"Segoe UI",sans-serif;color:var(--text);min-height:100vh}
main{max-width:1320px;margin:auto;padding:44px 28px 80px}.top{display:flex;justify-content:space-between;gap:24px;align-items:start}
.brand{font-size:13px;letter-spacing:.19em;text-transform:uppercase;color:var(--accent);font-weight:750}
h1{font-size:clamp(32px,4.8vw,56px);letter-spacing:-.045em;margin:6px 0 10px;line-height:1.12}
.lead{color:var(--dim);max-width:760px;margin:0}.stamp{border:1px solid var(--line);background:#112436;padding:9px 13px;border-radius:9px;color:var(--dim);white-space:nowrap;font-size:12px}
.summary{margin:34px 0;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.stat{background:#142338ad;border:1px solid var(--line);padding:18px 20px;border-radius:12px}.stat b{display:block;font-size:27px;letter-spacing:-.03em}.stat span{color:var(--dim);font-size:12px}
.toolbar{display:flex;gap:12px;align-items:center;margin-bottom:18px;flex-wrap:wrap}input,select{background:var(--panel);border:1px solid #34465b;border-radius:10px;color:var(--text);padding:12px 14px;font:inherit}
input{flex:1;min-width:220px}select{min-width:150px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:15px}
.card{background:linear-gradient(143deg,#192c43,#111a2a);border:1px solid var(--line);border-radius:15px;padding:21px;display:flex;flex-direction:column;gap:12px}
.card:hover{border-color:#328d91}.tag{display:inline-block;padding:3px 8px;background:#183c41;color:#94e7de;border-radius:5px;font-size:11px;font-weight:700}
.card h2{font-size:21px;margin:6px 0 0;letter-spacing:-.02em}.detail{font-size:13px;color:var(--dim);min-height:19px}
.metrics{display:flex;gap:19px;flex-wrap:wrap}.metrics div{display:flex;flex-direction:column}.metrics strong{font-size:19px}.metrics small{color:var(--dim);font-size:11px}
.path{border-top:1px solid var(--line);padding-top:13px;overflow-wrap:anywhere;color:var(--dim);font-size:12px}
.actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:auto;padding-top:7px}
.actions a,.actions button,.copy{border:1px solid #376d76;background:#183d46;color:#c5fff4;padding:9px 13px;border-radius:8px;text-decoration:none;cursor:pointer;font-weight:650;font:inherit;font-size:12px}
.actions a:hover,.actions button:hover,.copy:hover{background:#235961}
.actions .outline{background:transparent;color:var(--dim);border-color:var(--line)}
footer{margin-top:36px;color:var(--dim);font-size:12px;line-height:1.6}
code{background:#1a2a3d;border-radius:4px;padding:1px 4px}#none{display:none;color:var(--dim);padding:28px;border:1px dashed var(--line);border-radius:12px}
#drawer{display:none;margin-top:20px;background:#14263c;border:1px solid #376d76;padding:20px;border-radius:12px}
#drawer pre{white-space:pre-wrap;overflow-wrap:anywhere;color:#e5eaff}#drawer .copy{float:right}
@media(max-width:760px){main{padding:30px 16px}.summary{grid-template-columns:repeat(2,minmax(0,1fr))}.top{display:block}.stamp{display:inline-block;margin-top:18px}}
"""

HTML=r"""<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Graphify · Local Projects</title><style>__STYLE__</style></head>
<body><main><header class="top"><div><div class="brand">Graphify · Local Intelligence</div><h1>Source Graph Library</h1>
<p class="lead">Explore local code relationships by engine and project. Code-only indexes run on DEV; no cloud service or account required. New indexes automatically appear here when built using the index script.</p></div>
<div class="stamp">Updated __STAMP__</div></header>
<section class="summary" id="summary" aria-label="Graph totals"></section>
<div class="toolbar"><input id="filter" type="search" placeholder="Search project, version, source…" aria-label="Search graphs">
<select id="family" aria-label="Filter engine"><option value="">All categories</option></select>
<select id="order" aria-label="Sort graphs"><option value="family">Group by engine</option><option value="newest">Most recently indexed</option><option value="nodes">Largest graph first</option><option value="name">Name A–Z</option></select></div>
<div class="cards" id="cards"></div><div id="none">No indexed projects match those filters.</div>
<section id="drawer"><button class="copy" id="copy">Copy command</button><h2>Refresh index</h2><p>Run this command in PowerShell on DEV. Browsers do not have permission to execute local programs.</p><pre id="command"></pre></section>
<footer>All graphs are stored under <code>C:\Users\arden\Graphify</code>. The HTML views aggregate large graphs; use CLI/MCP for full symbol-level queries.
Data comes from the last local extraction and may be stale. Graph relationships help discovery but do not prove Epic serialization equivalence.
<strong>Nothing in this dashboard exposes an HTTP service.</strong></footer></main>
<script>
const projects=__DATA__;
const $=id=>document.getElementById(id), fmt=x=>Number(x).toLocaleString('en-IE');
function el(tag,cls,text){const v=document.createElement(tag);if(cls)v.className=cls;if(text!==undefined)v.textContent=text;return v}
let lastCommand='';
function command(p){
 let source=p.source||'<SOURCE DIRECTORY>';
 return "& 'C:/Users/arden/Graphify/Index-Code.ps1' -Name '"+p.id.replace(/'/g,"''")+"' -Source '"+source.replace(/'/g,"''")+"' -Workers 4";
}
function showCommand(p){lastCommand=command(p);$('command').textContent=lastCommand;$('drawer').style.display='block';$('drawer').scrollIntoView({behavior:'smooth',block:'nearest'})}
$('copy').onclick=async()=>{try{await navigator.clipboard.writeText(lastCommand);$('copy').textContent='Copied'}catch(e){$('copy').textContent='Select command above to copy'}};
const families=[...new Set(projects.map(p=>p.family))].sort();
for(const f of families){const o=el('option',null,f);o.value=f;$('family').append(o)}
const totals=[['Indexed projects',projects.length],['Source nodes',projects.reduce((a,p)=>a+p.nodes,0)],['Relationships',projects.reduce((a,p)=>a+p.edges,0)],['Graph JSON size',((projects.reduce((a,p)=>a+p.size,0))/1048576).toFixed(1)+' MiB']];
totals.forEach(([name,value])=>{let stat=el('div','stat');stat.append(el('b',null,typeof value==='number'?fmt(value):value),el('span',null,name));$('summary').append(stat)});
function draw(){
 const q=$('filter').value.toLowerCase().trim(),fam=$('family').value,sort=$('order').value;
 let rows=projects.filter(p=>(!fam||p.family===fam)&&[p.name,p.version,p.source,p.id,p.detail].join(' ').toLowerCase().includes(q));
 rows.sort((a,b)=>sort==='newest'?b.date.localeCompare(a.date):sort==='nodes'?b.nodes-a.nodes:sort==='name'?a.name.localeCompare(b.name):a.family.localeCompare(b.family)||a.name.localeCompare(b.name));
 $('cards').replaceChildren();$('none').style.display=rows.length?'none':'block';
 for(const p of rows){
  let card=el('article','card'),heading=el('div');
  heading.append(el('span','tag',p.family+(p.version?' · '+p.version:'')),el('h2',null,p.name));
  card.append(heading,el('div','detail',p.detail||'Local code graph'));
  let metrics=el('div','metrics');
  [['Nodes',p.nodes],['Edges',p.edges],['Communities',p.communities],['JSON MiB',(p.size/1048576).toFixed(1)]].forEach(([k,v])=>{let m=el('div');m.append(el('strong',null,typeof v==='number'?fmt(v):v),el('small',null,k));metrics.append(m)});card.append(metrics);
  card.append(el('div','path','Source: '+(p.source||'Source path not configured')));
  let acts=el('div','actions');
  const link=el('a',null,p.visual?'Open interactive graph':'HTML not generated');
  link.href=encodeURIComponent(p.id)+'/graphify-out/graph.html';
  if(!p.visual){link.removeAttribute('href');link.className='outline'}
  let cli=el('button','outline','Refresh command');cli.onclick=()=>showCommand(p);
  acts.append(link,cli);card.append(acts);$('cards').append(card);
 }
}
['filter','family','order'].forEach(k=>$(k).addEventListener(k==='filter'?'input':'change',draw));draw();
</script></body></html>"""

def main():
    data=entries()
    output=HTML.replace("__STYLE__",STYLE).replace("__STAMP__",html.escape(datetime.now().astimezone().strftime("%Y-%m-%d %H:%M %Z")))
    output=output.replace("__DATA__",json.dumps(data,ensure_ascii=False).replace("</","<\\/"))
    OUTPUT.write_text(output,encoding="utf-8")
    print(f"DASHBOARD_UPDATED path={OUTPUT} graphs={len(data)} nodes={sum(p['nodes'] for p in data)}")
    for project in data:print("  ",project["id"],project["nodes"],project["edges"],project["visual"])
if __name__=="__main__":main()
