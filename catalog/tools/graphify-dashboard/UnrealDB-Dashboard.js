// UnrealDB status extension: read-only. DOM nodes never interpolate journal HTML.
(()=>{'use strict';
const prefix='o06-';
const $=id=>document.getElementById(prefix+id);
const node=(tag,cls,text)=>{
  const e=document.createElement(tag);
  if(cls)e.className=cls;
  if(text!==undefined)e.textContent=String(text??'');
  return e;
};
const fmt=n=>Number(n||0).toLocaleString('en-IE');
const time=s=>{
  if(!s)return '—';
  const date=new Date(s);
  return Number.isNaN(date.getTime())?String(s).slice(0,19):
    date.toLocaleString('en-IE',{timeZone:'UTC',hour12:false,
      day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
};
const pill=(status)=>{
  const word=String(status||'UNVERIFIED').toUpperCase();
  let cls='o06-open';
  if(['PASS','MAPPED','ACCEPTED'].includes(word))cls='o06-pass';
  if(['FAIL','GAP'].includes(word))cls='o06-fail';
  if(['PARTIAL','OPEN','NOT ACCEPTED'].includes(word))cls='o06-warn';
  return node('span','o06-pill '+cls,word);
};
const state={data:null,visible:18};
function totals(d){
  const metrics=[
    ['Source sightings',d.source.total],
    ['Partial contracts',d.source.counts.PARTIAL||0],
    ['Current DEV PASS states',d.tests.states.PASS||0],
    ['Accepted gates',d.gates.filter(g=>g.status==='ACCEPTED').length+'/6']
  ];
  $('metrics').replaceChildren();
  for(const [label,value] of metrics){
    const item=node('div','o06-metric');
    item.append(node('strong',null,typeof value==='number'?fmt(value):value),
                node('span',null,label));
    $('metrics').append(item);
  }
  $('acceptance').textContent=d.operation+' · '+(d.accepted?'ACCEPTED':'NOT ACCEPTED');
  $('gates').replaceChildren();
  for(const gate of d.gates){
    const e=node('div','o06-gate');
    e.append(node('strong',null,gate.id),pill(gate.status));
    $('gates').append(e);
  }
}
function active(d){
  const a=d.active||{};
  $('current-contract').textContent=a.contract||'No active contract';
  $('stage').textContent='Stage: '+(a.stage||'unknown');
  $('action').textContent=a.last_action||'No completed action recorded';
  $('next').textContent=a.next_step||'No next action recorded';
  $('checkpoint-time').textContent='Checkpoint: '+time(a.updated_at_utc)+' UTC';
}
function release(d){
  const git=d.git||{};
  $('git-status').textContent=git.available
    ?(git.tracked_changes===0?'Tracked working tree clean':
      'Tracked file changes: '+fmt(git.tracked_changes)):
    'Git state temporarily unavailable';
  $('commits').replaceChildren();
  for(const commit of git.commits||[]){
    const row=node('div','o06-commit');
    const a=node('a',null,commit.hash);
    a.href='https://github.com/ardenee/ut_reader/commit/'+encodeURIComponent(commit.hash);
    a.target='_blank';a.rel='noopener noreferrer';
    const text=node('div',null,commit.subject);
    text.append(node('div','o06-meta',time(commit.date)+' UTC'));
    row.append(a,text);
    $('commits').append(row);
  }
  $('release').textContent=d.release
    ?d.release.description+' · Recorded '+time(d.release.at)+' UTC':
    'No release checkpoint found';
}
function tests(d){
  $('test-info').textContent=fmt(d.tests.total)+' current test states';
  $('events').replaceChildren();
  // A successful run has STARTED then PASS; show the latest terminal state
  // once, but preserve genuinely interrupted STARTED records.
  const seen=new Set();
  let shown=0;
  for(const event of d.tests.events||[]){
    const key=(event.run_id||'')+':'+(event.test||'');
    if(seen.has(key))continue;
    seen.add(key);
    if(shown++>=13)break;
    const tr=node('tr');
    const name=node('td',null,event.test||'Unknown');
    name.append(node('span','o06-meta',(event.commit||'')+
      (event.duration_ms!=null?' · '+fmt(event.duration_ms)+' ms':'')));
    const status=node('td');status.append(pill(event.state));
    tr.append(name,status,node('td',null,time(event.time)));
    $('events').append(tr);
  }
  if(!shown){
    const tr=node('tr');const td=node('td',null,'No journal results');
    td.colSpan=3;tr.append(td);$('events').append(tr);
  }
}
function planSelector(d){
  const previous=$('plan').value;
  $('plan').replaceChildren();
  for(const p of d.test_plans||[]){
    const o=node('option',null,p.id+' — '+(p.description||''));
    o.value=p.id;$('plan').append(o);
  }
  const next=(d.test_plans||[]).some(p=>p.id===previous)?previous:
    (d.test_plans||[]).some(p=>p.id===d.active.contract)?
      d.active.contract:(d.test_plans?.[0]?.id||'');
  $('plan').value=next;
  updateCommand();
}
function updateCommand(){
  const d=state.data;
  if(!d)return;
  const p=(d.test_plans||[]).find(x=>x.id===$('plan').value);
  const tier=$('tier').value;
  if(!p){
    $('command').textContent='No registered plan available.';
    $('plan-count').textContent='';$('copy').disabled=true;return;
  }
  const total=p.focus+(tier==='focus'?0:p.affected)+(tier==='gate'?p.gate:0);
  $('plan-count').textContent=fmt(total)+' selected tests';
  $('command').textContent=
    "& 'C:\\Users\\arden\\source\\repos\\ut_reader\\catalog\\bin\\run-ue1-o06-scoped.ps1' "+
    "-Contract '"+p.id+"' -Tier "+tier;
  $('copy').disabled=false;
}
function contracts(){
  const d=state.data;
  if(!d)return;
  const text=$('search').value.trim().toLowerCase();
  const status=$('status-filter').value;
  const rows=(d.contracts||[]).filter(c=>
    (!status||c.status===status)&&
    (!text||[c.id,c.reader,c.test,c.remaining,c.source,c.branch]
      .some(v=>String(v||'').toLowerCase().includes(text))));
  $('contract-count').textContent=fmt(rows.length)+' matching / '+fmt(d.source.total)+' source sightings';
  const tbody=$('contract-list');tbody.replaceChildren();
  for(const c of rows.slice(0,state.visible)){
    const tr=node('tr');
    const symbol=node('td',null,c.id);
    symbol.append(node('span','o06-meta',
      [c.source,c.source_line].filter(v=>v!=null).join(':')));
    const type=node('td');type.append(pill(c.status));
    const reader=node('td',null,c.reader||'—');
    if(c.test)reader.append(node('span','o06-meta',c.test));
    tr.append(symbol,type,reader,node('td',null,c.remaining||'—'));
    tbody.append(tr);
  }
  $('more').hidden=rows.length<=state.visible;
  $('more').textContent='Show more ('+fmt(rows.length-state.visible)+' remaining)';
}
function render(d){
  state.data=d;
  totals(d);active(d);release(d);tests(d);planSelector(d);contracts();
  $('updated').textContent='Live data: '+time(d.generated_at)+' UTC'+
    (d.graph_updated_at?' · Source graph: '+time(d.graph_updated_at)+' UTC':'');
  $('message').textContent='Read-only status loaded from DEV. Full engine acceptance remains '+(d.accepted?'complete.':'open.');
}
async function refresh(){
  const button=$('refresh');
  button.disabled=true;
  try{
    const response=await fetch('/api/unrealdb',{cache:'no-store'});
    if(!response.ok)throw new Error('HTTP '+response.status);
    const data=await response.json();
    if(!data.ok)throw new Error('Status source unavailable');
    render(data);
  }catch(err){
    $('message').textContent='Live verification status unavailable: '+err.message+
      '. Last successful values, if any, remain displayed.';
  }finally{button.disabled=false;}
}
$('refresh').addEventListener('click',refresh);
$('plan').addEventListener('change',updateCommand);
$('tier').addEventListener('change',updateCommand);
$('search').addEventListener('input',()=>{state.visible=18;contracts();});
$('status-filter').addEventListener('change',()=>{state.visible=18;contracts();});
$('more').addEventListener('click',()=>{state.visible+=25;contracts();});
$('copy').addEventListener('click',async()=>{
  const value=$('command').textContent;
  try{
    await navigator.clipboard.writeText(value);
    $('copy-result').textContent='DEV command copied. Run it only when you intend to start the tests.';
  }catch(err){
    $('copy-result').textContent='Select and copy the command displayed above.';
  }
});
refresh();
setInterval(()=>{if(document.visibilityState==='visible')refresh();},20000);
})();
