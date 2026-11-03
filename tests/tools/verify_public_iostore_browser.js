'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const repo = path.resolve(__dirname, '..', '..');
const client = fs.readFileSync(path.join(repo, 'catalog/assets/public-upload.js'), 'utf8');
const worker = fs.readFileSync(path.join(repo, 'catalog/assets/upload-file-inspector-worker.js'), 'utf8');
const start = client.indexOf('    function isIoStoreName(name) {');
const end = client.indexOf('    function addLog(status, name, message) {', start);
assert.ok(start > 0 && end > start, 'actual public-upload browser pairing logic must be present');
const exposed = vm.runInNewContext(
    '(function(){' + client.slice(start, end)
    + ' return {validateIoStoreBatch, isIoStoreName, ioStoreStem}; })()',
    {Set, Map}
);
const item = (name, folder = '') => ({
    file:{name, size:100},
    relativePath:folder ? folder + '/' + name : name
});
const goodSets = [
    [item('global.utoc'), item('global.ucas')],
    [item('FactoryGame-WindowsServer.utoc'), item('FactoryGame-WindowsServer.ucas')],
    [item('pakchunk0.utoc'), item('pakchunk0.ucas'), item('pakchunk0_s1.ucas')],
    [item('A.utoc','First'), item('A.ucas','First'), item('A.utoc','Second'), item('A.ucas','Second')],
    [item('GLOBAL.UTOC'), item('global.ucas')]
];
for (const set of goodSets) assert.equal(exposed.validateIoStoreBatch(set).size, 0);
const badSets = [
    [item('orphan.utoc')],
    [item('orphan.ucas')],
    [item('bad.utoc'), item('other.ucas')],
    [item('duplicate.utoc'), item('duplicate.utoc'), item('duplicate.ucas')],
    [item('map.utoc','A'), item('map.ucas','B')]
];
for (const set of badSets) assert.ok(exposed.validateIoStoreBatch(set).size >= 1);
assert.equal(exposed.isIoStoreName('FactoryGame-WindowsServer.utoc'), true);
assert.equal(exposed.isIoStoreName('global.UCAS'), true);
assert.equal(exposed.isIoStoreName('Map.uasset'), false);
assert.equal(exposed.ioStoreStem(item('pakchunk0_s2.ucas')), 'pakchunk0');
const admin = fs.readFileSync(path.join(repo, 'catalog/assets/profiled-upload-jobs-core.js'), 'utf8');
const adminPage = fs.readFileSync(path.join(repo, 'catalog/profiled-upload.php'), 'utf8');
assert.ok(admin.includes('dedicated.requestSubmit()'), 'Admin selected pair must use IoStore importer');
assert.ok(admin.includes('tocTransfer.items.add(tocs[0])'), 'Original TOC File must be retained');
assert.ok(admin.includes('partTransfer.items.add(file)'), 'Original UCAS Files must be retained');
assert.ok(adminPage.includes('id="profiled-iostore-form"'), 'Admin upload UI requires complete-pair form');
assert.ok(adminPage.includes('action="iostore-import.php"'), 'Admin form must use trusted IoStore POST');
assert.ok(client.includes('container_pending'));
assert.ok(client.includes("ioStoreSubmissionKey + '/'"));
assert.ok(worker.includes("if (extension === 'utoc')"));
assert.ok(worker.includes("if (extension === 'ucas')"));
const originalToc = fs.readFileSync('D:/UE5/Paks/global.utoc');
assert.equal(originalToc.subarray(0,16).toString('ascii'), '-==--==--==--==-');
console.log(JSON.stringify({
    result:'PASS',
    genuineCurrentOriginalTocMagicVerified:true,
    completePairCases:goodSets.length,
    rejectedOrphanOrAmbiguousCases:badSets.length,
    actualBrowserCodeEvaluated:true,
    actualInspectorIoStoreRulesPresent:true,
    newAcceptanceContractIDs:0
},null,2));
