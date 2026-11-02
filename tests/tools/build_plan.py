#!/usr/bin/env python3
"""Render human-readable contract scope from ONE machine-readable register."""
from pathlib import Path
import json,collections
R=Path(__file__).resolve().parents[2]
doc=json.loads((R/'tests/contracts.json').read_text('utf8'))
items=doc['contracts'];rows=doc['engine_contracts']
lookup=collections.defaultdict(list)
for r in rows:lookup[r['contract_id']].append((r['engine'].upper(),r['status']))
table=[
 '# Required UnrealDB file-parity behaviours — UE1 through UE5',
 '',
 '> Auto-generated from [contracts.json](contracts.json). Edit the single JSON register, not this table.',
 '',
 'Each row describes **what** must agree with the selected Epic source, **why** users care, and **what result ends verification**. It does not authorise tests in advance. First map existing native/source evidence and trace actual code paths. The applicable engine cells are current *planning states*, not summaries of old G3/G5 evidence.',
 '',
 '| ID | UnrealDB behaviour | Failure affecting users | Epic-equivalent stopping condition | Applicable engines |',
 '|---|---|---|---|---|'
]
def clean(v):return str(v).replace('|','/').replace('\n',' ')
for c in items:
 table.append('| '+ ' | '.join([c['id'],clean(c['name']),clean(c['user_risk']),
  clean(c['pass_condition']),', '.join(e for e,s in lookup[c['id']])])+' |')
table += [
 '',
 '## Mandatory evidence fields for one accepted engine/contract result',
 '',
 '1. Engine and game revision including container and loader provenance.',
 '2. Exact Epic function and source SHA or authentic compiled-oracle SHA, with version/branch used.',
 '3. The real UnrealDB reader/provider/persistence/website call chain and current code SHA.',
 '4. Genuine file(s), required preexisting mount context, expected source output and actual UEDB5/visible output.',
 '5. Exact field/reference/edge comparison plus error/unknown semantics.',
 '6. PASS / FAIL / BLOCKED / SOURCE_NA with reason and one SHA-pinned final accepted record.',
 '',
 '## Order of investigation (not a new test queue)',
 '',
 '**First:** UE1 package identity and end-to-end dependencies (F02, F05–F06, F11–F19, F22), since wrong providers and falsely missing dependencies affect users immediately. Reuse the frozen original 80-test and G5 acceptance before authorising any new original-engine run.',
 '',
 '**Then:** remaining UE1 visible archive/extraction, errors and file-download paths (F01, F03–F10, F20–F21). Only actual unverified file effects remain open.',
 '',
 '**Next:** UE2/UE2.5 and UE3, based on their own Epic/game source and different linker, import and archive branches. Do not borrow UE1 results by default.',
 '',
 '**Finally:** UE4 and UE5 end-to-end database/provider/UX coverage, reusing the already accepted UE4.27.2 and UE5.8.3 original package/Zen/IoStore differentials rather than rerunning them.',
 '',
 'Never expand the catalogue or add a test because the method happens to exist. A new required contract requires an observable UnrealDB user-facing failure not already represented above and explicit approval.',
 ''
]
(R/'tests/TEST-PLAN.md').write_text('\n'.join(table),encoding='utf8')
print('GENERATED',len(items),'BEHAVIOURS_AND',len(rows),'APPLICABLE_ENGINE_CONTRACTS')
