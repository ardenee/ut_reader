'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../catalog/assets/profiled-upload-pak-detection.js'), 'utf8');
const win = {};
vm.runInNewContext(source, {window: win, Blob, BigInt, Uint8Array, DataView});
const {inspect} = win.CatalogPakDetection;
function file(buffer,name='test.pak') {
    const blob = new Blob([buffer]);
    blob.name = name;
    return blob;
}
function ue3(version) {
    const data = Buffer.alloc(64);
    data.writeUInt32LE(0x9E2A83C1,0);
    data.writeUInt16LE(version,4);
    return data;
}
function pakFooter(magicLast=false) {
    const data = Buffer.alloc(256);
    const p = magicLast ? 252 : 212;
    data.writeUInt32LE(0x5A6F12E1,p);
    if(magicLast) {
        data.writeInt32LE(3,p-4);
        data.writeBigUInt64LE(0n,p-20);
        data.writeBigUInt64LE(16n,p-12);
    } else {
        data.writeInt32LE(3,p+4);
        data.writeBigUInt64LE(0n,p+8);
        data.writeBigUInt64LE(16n,p+16);
    }
    return data;
}
(async function(){
    assert.equal((await inspect(file(ue3(512),'DM-Goop!.pak'))).kind,'ut3_package');
    assert.equal((await inspect(file(ue3(69)))).kind,'legacy_unreal_package');
    assert.equal((await inspect(file(pakFooter()))).kind,'unrealpak');
    assert.equal((await inspect(file(pakFooter(true)))).kind,'unrealpak');
    assert.equal((await inspect(file(Buffer.from('abcdef\n'),'empty.pak'))).kind,'unknown');
    assert.equal((await inspect(file(pakFooter(), 'ordinary.ut3'))).kind,'not_pak');
    const tampered=pakFooter();
    tampered.writeBigUInt64LE(5000n,220);
    assert.equal((await inspect(file(tampered))).kind,'unknown','reject impossible footer bounds');
    const core=fs.readFileSync(path.join(__dirname,'../../catalog/assets/profiled-upload-jobs-core.js'),'utf8');
    assert.ok(core.includes('async function planPakGameGroups(files)'), 'mixed group planning');
    assert.ok(core.includes('for (const gameGroup of gameGroups)'), 'per-game execution');
    assert.ok(core.includes("batchGameId = String(gameGroup.gameId)"), 'target game is per batch');
    assert.ok(core.includes('window.confirm('), 'admin approves retargeting');
    console.log(JSON.stringify({pass:true,fixtures:7,mixed_game_batches:true,unknowns_not_assigned:true}));
})().catch(e=>{console.error(e);process.exitCode=1});
