#!/usr/bin/env python3
"""Find source-observed UnrealDB operation cases affected by a changed product file."""
import argparse,json,subprocess,collections
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
def current():
 p=subprocess.run(['git','diff','--name-only','HEAD','--'],cwd=ROOT,capture_output=True,text=True,check=True)
 q=subprocess.run(['git','ls-files','--others','--exclude-standard'],cwd=ROOT,capture_output=True,text=True,check=True)
 return sorted(set(p.stdout.splitlines()+q.stdout.splitlines()))
def main():
 a=argparse.ArgumentParser(description='Map changed UnrealDB production files to the existing finite engine operation cases')
 a.add_argument('--path',action='append',default=[],help='Product file path relative to repository. Repeatable.')
 a.add_argument('--changed',action='store_true',help='Include current Git diff/untracked paths')
 args=a.parse_args()
 changed=set(p.replace('\\','/').lstrip('./') for p in args.path)
 if args.changed or not changed:changed.update(current())
 c=json.loads((ROOT/'tests/contracts.json').read_text('utf8'))
 rows=c['engine_contracts']
 affected={}
 for v in sorted(changed):
  hit=[x for x in rows if v in x['actual_unrealdb_paths'] or
        v.replace('/','\\').lower()==x['epic_source_anchor_file'].replace('/','\\').lower()]
  affected[v]={'cases':[x['key'] for x in hit],
   'engines':sorted({x['engine'] for x in hit}),
   'warning':'Direct source-path matches only. Dynamic registration, frontend caller, underlying shared class or service changes require manual call-chain examination.' if not hit else
   'Only the matching current route cases are candidates for regression; first check existing source/native evidence.'}
 print(json.dumps({'changed_source_files':len(changed),'directly_affected_engine_operation_cases':sorted(set(v for x in affected.values() for v in x['cases'])),
  'cross_engine_code_impact':affected,'original_native_tests_launched':0},indent=2))
if __name__=='__main__':main()
