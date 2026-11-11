'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const jsRoot=path.join(__dirname,'../../catalog/assets');
const detectionSource=fs.readFileSync(path.join(jsRoot,'profiled-upload-pak-detection.js'),'utf8');
const core=fs.readFileSync(path.join(jsRoot,'profiled-upload-jobs-core.js'),'utf8');
const win={};
vm.runInNewContext(detectionSource,{window:win, Blob, BigInt, Uint8Array, DataView});
const {inspect}=win.CatalogPakDetection;
const makeFile=(bytes,name)=>{const blob=new Blob([bytes]);blob.name=name;return blob};
const ut3=Buffer.alloc(64);ut3.writeUInt32LE(0x9E2A83C1,0);ut3.writeUInt16LE(512,4);
const pak=Buffer.alloc(256);pak.writeUInt32LE(0x5A6F12E1,212);pak.writeInt32LE(3,216);pak.writeBigUInt64LE(0n,220);pak.writeBigUInt64LE(16n,228);
const UT3=makeFile(ut3,'DM-Goop!.pak'), UT4=makeFile(pak,'DM-UT4-WindowsNoEditor.pak');
const bad=makeFile(Buffer.from('abcdef\n'),'empty.pak');
const nonpak=makeFile(Buffer.from('placeholder'),'ordinary.upk');
const start=core.indexOf('    async function planPakGameGroups(files) {');
const end=core.indexOf('    function shownName(file) {',start);
assert.ok(start>0&&end>start,'routing helper missing');
const fnSource=core.slice(start,end);
function planner(selected,confirm=true){
  const opts=[
    {value:'6',dataset:{engine:'UE3'},textContent:'Unreal Tournament 3'},
    {value:'7',dataset:{engine:'UE4'},textContent:'Unreal Tournament 4'},
    {value:'14',dataset:{engine:'UE5'},textContent:'Satisfactory'},
  ];
  const sel={value:String(selected),options:opts,selectedIndex:opts.findIndex(o=>o.value===String(selected))};
  const prompts=[],alerts=[];
  const window={
    CatalogPakDetection:{inspect},
    confirm:(message)=>{prompts.push(message);return confirm},
    alert:(message)=>alerts.push(message)
  };
  const form={querySelector:()=>sel};
  const isPak=(f)=>String(f.name||'').toLowerCase().endsWith('.pak');
  const plan=new Function('form','window','isPak',fnSource+'return planPakGameGroups;')(form,window,isPak);
  return {plan,sel,prompts,alerts};
}
(async function(){
  let x=planner(7);
  let groups=await x.plan([UT3,UT4,nonpak]);
  assert.equal(groups.length,2);
  assert.equal(groups.find(g=>g.gameId==='6').files.length,1);
  assert.equal(groups.find(g=>g.gameId==='7').files.length,2);
  assert.equal(x.prompts.length,1);
  x=planner(7);
  groups=await x.plan([UT3]);
  assert.equal(groups.length,1);
  assert.equal(groups[0].gameId,'6');
  assert.equal(x.sel.value,'6','single-game retargeting updates selector');
  x=planner(6);
  groups=await x.plan([UT4,nonpak]);
  assert.equal(groups.length,2);
  assert.equal(groups.find(g=>g.gameId==='7').files.length,1);
  assert.match(x.prompts[0],/not definitive game ownership/);
  x=planner(6);
  groups=await x.plan([bad]);
  assert.equal(groups,null);
  assert.equal(x.alerts.length,1);
  x=planner(7,false);
  groups=await x.plan([UT3]);
  assert.equal(groups,null,'user may reject retargeting');
  x=planner(7);
  groups=await x.plan([UT4]);
  assert.equal(groups.length,1);
  assert.equal(groups[0].gameId,'7');
  x=planner(14);
  groups=await x.plan([UT3,UT4]);
  assert.equal(groups.length,2);
  assert.equal(groups.find(g=>g.gameId==='6').files.length,1);
  assert.equal(groups.find(g=>g.gameId==='14').files.length,1);
  console.log(JSON.stringify({pass:true,scenarios:7,automatic_mixed_game_batch_split:true,explicit_approval:true,invalid_ut3_rejected:true}));
})().catch(e=>{console.error(e);process.exitCode=1});
