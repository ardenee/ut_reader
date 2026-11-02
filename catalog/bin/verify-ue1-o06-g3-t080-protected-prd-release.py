#!/usr/bin/env python3
"""T080 protected PRD release: verify original 1610-file copy and all later closure changes."""
from pathlib import Path
import hashlib,json,subprocess
root=Path(__file__).resolve().parents[2]
prd=Path(r'Z:\Apache24\htdocs\unrealdb')
scratch=Path(r'C:\Temp\UnrealDB\O06')
proof=root/'catalog/compliance/evidence/o06/20261102-G3-T080-first-PRD-deploy-1610-original-SHA-verified.json'
before=scratch/'g3-t080-PRD-first-protected-SHA-preflight.json'
assert proof.is_file() and before.is_file()
m=json.loads(proof.read_text('utf8'))
pre=json.loads(before.read_text('utf8'))
assert m['all_copied_source_PRD_SHA256_identical'] and m['copied_file_count']==1610
assert len(m['copied'])==1610
assert m['previous_PRD_newer_only_EOL_unchanged']==645 and m['PRD_newer_actual_content_diff_count']==0
def sh(p):
 with p.open('rb') as h:return hashlib.file_digest(h,'sha256').hexdigest()
def git(*args):return subprocess.run(['git',*args],cwd=root,check=True,text=True,capture_output=True).stdout.strip()
head=git('rev-parse','HEAD')
assert head==git('rev-parse','origin/main'),'GitHub tracking main behind DEV HEAD'
assert git('status','--porcelain')=='','DEV source repo must be clean'
assert m['GitHub_main_verified']=='585105de6adfcd37892e00c7d340e9dfc5375b75'
changed=set(filter(None,git('diff','--name-only',m['GitHub_main_verified'],head,'--').splitlines()))
assert changed and all(not (name.startswith('.git/') or name.startswith('catalog/storage/') or name=='catalog/config.php') for name in changed)
for id,v in enumerate(m['copied'],1):
 rel=v['path'];a=root/rel;b=prd/rel
 assert a.is_file() and b.is_file(),('missing released file',rel)
 assert sh(a)==sh(b),('PRD bytes differ from DEV source',rel)
 if v['sha256']!=sh(a):
  assert rel in changed,('previously SHA-matched release file unexpectedly changed outside committed scope',rel)
for rel in sorted(changed):
 if rel=='catalog/compliance/evidence/o06/.gitattributes':pass
 a=root/rel;b=prd/rel
 assert a.is_file() and b.is_file(),('release update missing',rel)
 assert sh(a)==sh(b),('latest GitHub source not deployed',rel)
for rel in ('catalog/config.php','.git/config'):
 assert sh(prd/rel)==pre['protected_before'][rel]['sha256'],rel
assert (prd/'catalog/storage').is_dir()
assert (prd/'catalog/storage').stat().st_mtime_ns==pre['protected_before']['catalog/storage']['last_write_timestamp_ns']
code_root=root/'catalog/src/Infrastructure/Readers'
reader_files=list(code_root.glob('CatalogUE1*.php'))
assert len(reader_files)>=77
for p in reader_files:
 dst=prd/p.relative_to(root)
 assert dst.is_file()
 s=p.read_bytes();t=dst.read_bytes()
 assert s==t or s.replace(b'\r\n',b'\n')==t.replace(b'\r\n',b'\n'),('UE1 product code is different',str(p))
b=json.loads((root/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json').read_text('utf8'))
assert len(b['baseline'])==80
statuses={v['id']:v['status'] for v in b['baseline']}
assert all(v=='PASS' or (k=='T050' and v=='SOURCE_NA') for k,v in statuses.items())
assert sum(v=='PASS' for v in statuses.values())==79
for rel in ('catalog/compliance/evidence/o06/20261102-G3-T080-first-PRD-deploy-1610-original-SHA-verified.json',
 'catalog/compliance/evidence/o06/20261102-G3-T079-final-current-source-G3-plus-G5-one-time-review.json',
 'catalog/compliance/evidence/o06/20261102-G3-T077-76-original-test-method-owner-629-SHA-references.json'):
 assert sh(root/rel)==sh(prd/rel)
print(json.dumps({'G3_final_T080_protected_PRD_RELEASE_PASS':True,'GitHub_DEV_commit':head,
 'first_pass_original_SHA_copied_files_all_equal':1610,
 'final_commit_additional_changed_files_all_SHA_equal':len(changed),
 'PRD_preexisting_645_EO_L_only_differences_preserved':True,
 'current_UE1_readers_byte_or_newline_identical':len(reader_files),
 'PRD_config_git_and_storage_untouched':True,
 'final_80_frozen':{'PASS':79,'SOURCE_NA':1,'BLOCKED':0,'FAIL':0},
 'executed_original_engine_tests_this_deployment':0},indent=2))
