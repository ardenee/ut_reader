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
ensure(len(rows)==len({r['key'] for r in rows})==115,'115 unique engine contracts')
status=collections.Counter(x['status'] for x in rows)
allowed=set(contracts['outcomes'])
for x in rows:
 c=catalog[x['contract_id']]
 ensure(x['key']==x['engine']+'-'+x['contract_id'],'contract ID/name match')
 ensure(x['engine'] in c['engines'] and x['status'] in allowed,'applicable engine and allowed state')
 if x['status']=='PASS':
  ensure(x['epic_source_files'] and x['unrealdb_code_paths'] and x['acceptance_proof'] and x['last_verified_product_sha256'],
    'PASS requires exact Epic source / current implementation / original proof')
 if x['status']=='SOURCE_NA':
  ensure(x['acceptance_proof'] and x['epic_source_files'],'SOURCE_NA requires source proof')
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
 'applicable_engine_contracts':len(contracts['engine_contracts']),'contract_states':dict(status),
 'current_production_PHP_and_operational_paths_SHA_verified':len(rows),
 'original_native_engine_tests_executed':0},indent=2))
