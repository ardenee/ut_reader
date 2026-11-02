#!/usr/bin/env python3
"""Generate ONLY source-observed UnrealDB operation test plan from the ONE register."""
from pathlib import Path
import json,collections
R=Path(__file__).resolve().parents[2]
doc=json.loads((R/'tests/contracts.json').read_text('utf8'))
rows=doc['engine_contracts']
assert len(rows)==32
out=['# Tests derived from UnrealDB operations that exist in production code','',
 '> Generated from [contracts.json](contracts.json). 24 generic behaviour types are risk labels, not tests. Only these 32 path/engine cases have concrete existing implementation paths. No native test has been authorised or run as a result of this plan.','',
 'NOT_REVIEWED means the real production call and applicable Epic game-source version must be traced before any new differential run. Existing original proof is reused first.','',
 '| Engine / real operation | UnrealDB path (actual) | Original source anchor | Why verify? | Exact stopping condition | State |',
 '|---|---|---|---|---|---|']
for x in rows:
 def short(v):return str(v).split('/')[-1].replace('|','/')
 impl=', '.join(short(p) for p in x['actual_unrealdb_paths'])
 epic=x['epic_source_anchor_file'].replace('\\','/').split('/')[-1]
 out.append('| '+ ' | '.join([x['key'],impl,epic,
 x['test_purpose'].replace('|','/'),x['acceptance_condition'].replace('|','/'),x['status']])+' |')
out += ['','## Engine version/source selection — mandatory first step','','| Engine | Reader owner | Game-specific exceptions |',
 '|---|---|---|']
by=collections.defaultdict(list)
for x in rows:by[x['engine']].append(x)
for engine,rr in by.items():
 head=rr[0]
 out.append('| '+engine.upper()+' | '+head['actual_unrealdb_paths'][-1].split('/')[-1]+
   ' (see ARCHITECTURE.md for real reader class) | '+
   head['source_provenance_constraint'].replace('|','/')+' |')
out+=['','## Stop rules','','Before any new executable differential: identify the real entrypoint -> reader -> snapshot -> provider -> persisted edge -> user page; record the exact Epic function and game revision; map old accepted evidence SHA; specify a genuine file and expected original result. If the previous evidence is applicable, do not run a new test.','',
 'After acceptance, update the corresponding engine+operation row only. A new test must detect a specific realistic false missing dependency, wrong provider, damaged payload or incorrect database/UI outcome. Do not test a function merely because it exists.','']
(R/'tests/TEST-PLAN.md').write_text('\n'.join(out),encoding='utf8')
print('GENERATED_IMPLEMENTATION_DERIVED_TEST_CASES',len(rows))
