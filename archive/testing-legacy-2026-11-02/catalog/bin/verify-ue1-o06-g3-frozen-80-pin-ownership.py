#!/usr/bin/env python3
"""Read-only frozen G3 80-ID SHA ownership audit. Never launches Unreal binaries.

This is an evidence-integrity check, NOT T077 compiled method acceptance;
T078-80 release remains ineligible while required tests are BLOCKED.
"""
from pathlib import Path
import hashlib,json,collections,sys
ROOT=Path(__file__).resolve().parents[2]
REGISTER=ROOT/'catalog/compliance/UE1-O06-G3-EXECUTION-TEST-BACKLOG.json'
data=json.loads(REGISTER.read_text(encoding='utf-8-sig'))
rows=data['baseline']
issues=[]
expected=['T%03d'%i for i in range(1,81)]
if len(rows)!=80 or [r['id'] for r in rows]!=expected:
    issues.append('invalid fixed G3 ID baseline')
paths={}
counts=collections.Counter(r['status'] for r in rows)
for r in rows:
    tid=r['id']
    if r['status'] in ('PASS','SOURCE_NA') and not (r.get('verified_evidence') or []):
        issues.append(tid+' accepted without original SHA ownership')
    for category in ('verified_evidence','partial_verified_evidence'):
        for e in r.get(category) or []:
            if not isinstance(e,dict) or not e.get('path'):continue
            relative=e['path']
            file=ROOT/relative
            if relative not in paths:
                if not file.is_file():paths[relative]={'status':'missing'}
                else:paths[relative]={'status':'present',
                                      'sha256':hashlib.sha256(file.read_bytes()).hexdigest(),
                                      'bytes':file.stat().st_size}
            check=paths[relative]
            if check['status']!='present':issues.append(tid+' missing '+relative)
            elif e.get('sha256')!=check['sha256']:
                issues.append(tid+' stale SHA '+relative)
            elif 'bytes' in e and e['bytes']!=check['bytes']:
                issues.append(tid+' stale size '+relative)
            elif not e.get('sha256'):
                issues.append(tid+' lacks SHA '+relative)
blocked=[r['id'] for r in rows if r['status']=='BLOCKED']
all_final=not blocked and counts.get('FAIL',0)==0 and counts.get('PENDING',0)==0
result={'ok':not issues,'frozen_id_count':len(rows),'status_counts':dict(counts),
        'all_required_compiled_native_methods_accepted':False if blocked else all_final,
        'current_unique_shas_checked':len(paths),'hash_size_errors':issues,
        'blocked_ids':blocked,'source_justified_NA_ids':[r['id'] for r in rows if r['status']=='SOURCE_NA'],
        'original_engine_sessions_reexecuted':0,
        'full_G3_release_eligible':False if blocked else all_final}
print(json.dumps(result,indent=2,ensure_ascii=False))
sys.exit(0 if not issues else 1)
