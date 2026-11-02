#!/usr/bin/env python3
"""Read-only integrity gate. No Epic engine execution, no generated test files."""
import collections,hashlib,json,subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def load(path):return json.loads((ROOT/path).read_text('utf-8-sig'))
def git(*args):return subprocess.run(['git',*args],cwd=ROOT,capture_output=True,check=True).stdout
def sha(path):
 with path.open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()
def ensure(ok,what):
 if not ok:raise RuntimeError(what)
contracts=load('tests/contracts.json')
catalog={c['id']:c for c in contracts['contracts']}
ensure(len(catalog)==len(contracts['contracts'])==24,'24 fixed behaviour categories')
rows=contracts['engine_contracts']
ensure(len(rows)==len({r['key'] for r in rows})==32,'32 unique source-observed application paths')
status=collections.Counter(x['status'] for x in rows)
allowed=set(contracts['outcomes'])
for x in rows:
 ensure(x['key']==x['engine']+'-'+x['operation'].replace('_','-'),'engine operation ID must be unique')
 ensure(x['engine'] in ('ue1','ue2','ue3','ue4','ue5') and x['status'] in allowed,
       'real engine and accepted test state')
 ensure(bool(x['test_purpose']) and bool(x['acceptance_condition']) and bool(x['required_fixture']),
       'no test without user risk, expected result and genuine fixture')
 ensure(x['actual_unrealdb_paths'] and x['epic_source_anchor_file'],
       'every test needs a real UnrealDB path and original source candidate')
 ensure(Path(x['epic_source_anchor_file']).is_file(),'Epic original source file absent '+x['key'])
 for f in x['actual_unrealdb_paths']:
  ensure((ROOT/f).is_file(),'test refers to nonexistent UnrealDB code '+x['key']+': '+f)
 for id in x['contract_ids']:
  ensure(id in catalog and x['engine'] in catalog[id]['engines'],
         'behaviour not valid for chosen engine '+x['key']+': '+id)
 if x['status']=='PASS':
  ensure(x['runtime_call_chain_verified'] and x['result_evidence'] and x['affected_code_sha256'],
         'PASS requires runtime call-chain proof and original native/source result')
 if x['status']=='SOURCE_NA':
  ensure(x['result_evidence'] is not None,'SOURCE_NA requires explicit source proof')
old=load('archive/testing-legacy-2026-11-02/manifest.json')
ensure(old['original_file_count']==len(old['files'])==3632,'frozen file count mismatch')
index={}
for b in git('ls-files','--stage','-z').split(b'\0'):
 if not b:continue
 a,p=b.split(b'\t',1);index[p.decode('utf8')]=a.split()[1].decode('ascii')
archive_bytes=0
for x in old['files']:
 p=ROOT/x['archived']
 ensure(p.is_file(),'lost archived evidence '+x['archived'])
 ensure(p.stat().st_size==x['bytes'] and sha(p)==x['sha256'],'archived original worktree sha mismatch '+x['archived'])
 ensure(index.get(x['archived'])==x['original_git_blob_oid'],'archived exact original Git blob mismatch '+x['archived'])
 archive_bytes+=x['bytes']
inventory=load('tests/code-paths.json')
from importlib.util import spec_from_file_location,module_from_spec
spec=spec_from_file_location('current_codepath_index',ROOT/'tests/tools/index_codepaths.py')
mod=module_from_spec(spec);spec.loader.exec_module(mod)
existing={p.relative_to(ROOT).as_posix() for p in mod.tracked_files()}
rows=inventory['paths']
ensure(inventory['total_files']==len(rows)==len(existing),'missing tracked product path')
ensure({x['path'] for x in rows}==existing,'live code-path inventory needs refresh')
for x in rows:
 p=ROOT/x['path']
 ensure(p.is_file() and sha(p)==x['sha256'],'live product source changed, rebuild inventory '+x['path'])
callgraph=load('tests/callgraph.json')
ensure(callgraph['nodes']==len(rows) and callgraph['edges_count']==len(callgraph['edges']),
       'static callgraph needs regeneration')
for edge in callgraph['edges']:
 ensure(edge['from'] in existing and edge['to'] in existing, 'static edge path not in current code inventory')
print(json.dumps({'integrity':'PASS','original_archive_files_exact_SHA_and_Git_blob':len(old['files']),
 'legacy_original_stored_bytes':archive_bytes,'current_engine_behaviour_categories':len(catalog),
 'actual_implemented_engine_operation_cases':len(contracts['engine_contracts']),'contract_states':dict(status),
 'current_production_PHP_and_operational_paths_SHA_verified':len(rows),
 'original_native_engine_tests_executed':0},indent=2))
