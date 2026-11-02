// All current counts come from tests/contracts.json, never archived G3 journals.
(()=>{'use strict';
const $=id=>document.getElementById('o06-'+id);
const make=(tag,cls,txt)=>{let x=document.createElement(tag);if(cls)x.className=cls;if(txt!==undefined)x.textContent=String(txt??'');return x;};
const fmt=v=>Number(v||0).toLocaleString('en-IE');
const pill=status=>{const s=String(status||'NOT_REVIEWED').toUpperCase();
  return make('span','o06-pill '+(s==='PASS'||s==='ACCEPTED'?'o06-pass':s==='FAIL'?'o06-fail':'o06-warn'),s);};
const state={data:null,visible:20};
function display(d){
 state.data=d;
 const rows=[['Required engine/behaviour pairs',d.source.total],['Awaiting source-path review',d.source.counts.NOT_REVIEWED||0],
 ['Open production mismatches',d.source.counts.FAIL||0],['Mapped production PHP paths',d.code_paths.total]];
 $('metrics').replaceChildren(...rows.map(([label,val])=>{let n=make('div','o06-metric');n.append(make('strong',null,fmt(val)),make('span',null,label));return n;}));
 $('acceptance').textContent=d.accepted?'UE1–UE5 PARITY ACCEPTED':'END-TO-END REVIEW IN PROGRESS';
 $('gates').replaceChildren(...d.gates.map(g=>{let n=make('div','o06-gate');n.append(make('strong',null,g.id),pill(g.status));return n;}));
 $('current-contract').textContent=d.active.contract;
 $('stage').textContent='Stage: '+d.active.stage;
 $('action').textContent=d.active.last_action;
 $('next').textContent=d.active.next_step;
 $('checkpoint-time').textContent='Current data read from Git checkout';
 $('git-status').textContent=d.git.available?(d.git.tracked_changes+' tracked changes in DEV'):'Git unavailable';
 $('commits').replaceChildren(...(d.git.commits||[]).map(c=>{let row=make('div','o06-commit');let link=make('a',null,c.hash);link.href='https://github.com/ardenee/ut_reader/commit/'+encodeURIComponent(c.hash);link.target='_blank';link.rel='noopener noreferrer';row.append(link,make('div',null,c.subject));return row;}));
 $('release').textContent=d.release.description;
 $('updated').textContent='Current register read at '+d.generated_at;
 $('message').textContent='Live register; historical test scripts do not create new pass counts.';
 renderContracts();
}
function renderContracts(){
 const d=state.data;if(!d)return;
 const needle=$('search').value.toLowerCase().trim(),select=$('status-filter').value;
 const found=(d.contracts||[]).filter(c=>(!select||c.status===select)&&
 [c.id,c.branch,c.reader,c.purpose,c.remaining].some(s=>String(s||'').toLowerCase().includes(needle)));
 $('contract-count').textContent=fmt(found.length)+' shown of '+fmt(d.source.total);
 $('contract-list').replaceChildren(...found.slice(0,state.visible).map(c=>{let tr=make('tr');let td=make('td',null,c.id);tr.append(td);let st=make('td');st.append(pill(c.status));tr.append(st,make('td',null,c.reader),make('td',null,c.remaining));return tr;}));
 $('more').hidden=found.length<=state.visible;$('more').textContent='Show more ('+fmt(Math.max(0,found.length-state.visible))+')';
}
async function refresh(){const b=$('refresh');b.disabled=true;try{const r=await fetch('/api/unrealdb',{cache:'no-store'});if(!r.ok)throw Error('HTTP '+r.status);const data=await r.json();if(!data.ok)throw Error('Invalid register');display(data);}catch(e){$('message').textContent='Current parity register unavailable: '+e.message;}finally{b.disabled=false;}}
$('refresh').addEventListener('click',refresh);$('search').addEventListener('input',()=>{state.visible=20;renderContracts();});
$('status-filter').addEventListener('change',()=>{state.visible=20;renderContracts();});
$('more').addEventListener('click',()=>{state.visible+=30;renderContracts();});
refresh();setInterval(()=>{if(document.visibilityState==='visible')refresh();},20000);
})();
