'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const path=require('node:path');
const source=fs.readFileSync(path.join(__dirname,'../../catalog/assets/catalog-tls-expiry-notice.js'),'utf8');
function run({days=3,stored=null,admin='1',cert='a'.repeat(64),exists=true}={}) {
  const nodes=[];
  const storage=new Map();
  const date='2026-11-10';
  if(stored)storage.set('unrealdb.tlsExpiry.dismissed.'+admin+'.'+cert,stored);
  const meta=exists?{dataset:{days:String(days),expires:'2027-02-08 13:18 UTC',cert,admin}}:null;
  const make=(tag)=>({
    tag, attrs:{},children:[],
    setAttribute(k,v){this.attrs[k]=v;},
    appendChild(c){this.children.push(c);return c;},
    addEventListener(name,cb){this[name]=cb;},
    remove(){this.removed=true;},
    focus(){this.focused=true;}
  });
  const document={querySelector:()=>meta,head:make('head'),body:make('body'),createElement:make};
  const context={document,window:{localStorage:{getItem:(k)=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v)}},
    Date:class {toLocaleDateString(){return date}},console};
  vm.runInNewContext(source,context);
  return {document,storage,meta};
}
const assert=require('node:assert/strict');
let x=run();
assert.equal(x.document.body.children.length,1);
const dialog=x.document.body.children[0].children[0];
assert.equal(dialog.attrs.role,'alertdialog');
const close=dialog.children[2].children[0];
assert.equal(close.textContent,'Remind me tomorrow');
assert.equal(close.focused,true);
close.click();
assert.equal(x.document.body.children[0].removed,true);
assert.equal(x.storage.size,1);
assert.equal(run({stored:'2026-11-10'}).document.body.children.length,0);
assert.equal(run({stored:'2026-11-09'}).document.body.children.length,1);
assert.equal(run({days:-1}).document.body.children[0].children[0].children[0].textContent,
 'UnrealDB HTTPS certificate has expired');
assert.equal(run({exists:false}).document.body.children.length,0);
assert.equal(run({admin:'0'}).document.body.children.length,0);
console.log('PASS admin TLS popup: visible, dismiss once/day, reminder repeats next day, expired, no public/meta');
