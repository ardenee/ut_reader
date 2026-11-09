'use strict';
// Regression for browser-side removal of ExportMap Ref hyperlinks.
// DOM operations are faked minimally; the functions under test are extracted
// directly from the shipped catalog-ui.js, not copied into this test.
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname,'../../catalog/assets/catalog-ui.js'),'utf8');
const start = source.indexOf('    function targetIdsFor(link) {');
const end = source.indexOf('    function movePackageRefsToIndexTooltips(',start);
if (start < 0 || end < start) throw new Error('Browser self-link function not found');
const context = { document: { createElement: ()=>({className:'',textContent:''}) }, decodeURIComponent };
vm.createContext(context);
vm.runInContext(source.slice(start,end),context);
function mock(classes, target, row) {
  const link = {
    className:classes.join(' '),textContent:'1',
    classList:{contains:(c)=>classes.includes(c)},
    dataset:{},getAttribute:(name)=>name==='href'?'file-examine.php?target='+target+'#'+target:null,
    closest:(name)=>name==='tr'?{id:row}:null,
    replaceWith(node){this.replaced=node}
  };
  return link;
}
const exportRef=mock(['xref','mono','examine-export-self-ref'],'export-0','export-0');
const importRef=mock(['xref','mono','examine-import-self-ref'],'import-0','import-0');
const genericSelf=mock(['xref','mono'],'export-0','export-0');
const unrelated=mock(['xref','mono'],'export-1','export-0');
const links=[exportRef,importRef,genericSelf,unrelated];
context.removeSelfReferenceLinks({querySelectorAll:()=>links});
if(exportRef.replaced || importRef.replaced)
  throw new Error('Import/Export Ref must remain clickable');
if(!genericSelf.replaced) throw new Error('Generic self-reference should still be unwrapped');
if(unrelated.replaced) throw new Error('External reference must remain clickable');
console.log('PASS: Export and Import self links remain anchors after browser init; other links unaffected');
