#!/usr/bin/env python3
"""Navigation-only PHP references. Static hints are NOT proved runtime call paths."""
from pathlib import Path
import re,json,collections
R=Path(__file__).resolve().parents[2]
db=json.loads((R/'tests/code-paths.json').read_text('utf8'))
nodes=db['paths']
names=collections.defaultdict(list)
for n in nodes:
 if n.get('class'):names[n['class'].lower()].append(n['path'])
edges=[];unresolved=collections.Counter()
pat=re.compile(r'\b(new\s+|extends\s+|use\s+|(?<![\w\\])\b)([A-Za-z_][A-Za-z_0-9\\]*)\s*(::|\(|;)',re.I)
for node in nodes:
 file=R/node['path']
 if not node['path'].endswith('.php'):continue
 text=file.read_text('utf8',errors='replace')
 seen=set()
 for m in pat.finditer(text):
  token=m.group(2).split('\\')[-1]
  if not token or token.lower()==(node.get('class') or '').lower():continue
  matches=names[token.lower()]
  if not matches:continue
  if len(matches)>1:
   unresolved[token]+=1;continue
  dest=matches[0]
  if dest==node['path']:continue
  kind='new' if (m.group(1) or '').lower().strip()=='new' else 'extends' if (m.group(1) or '').lower().strip()=='extends' else 'use' if (m.group(1) or '').lower().strip()=='use' else 'static_or_expression'
  key=(dest,kind)
  if key in seen:continue
  seen.add(key)
  edges.append({'from':node['path'],'to':dest,'syntax':kind,'line':text.count('\n',0,m.start())+1})
result={'schema_version':1,
 'warning':'Static lexical PHP class references only; includes false positives and misses dynamic DI/reflection/SQL/job dispatch. Trace real runtime execution before contract acceptance.',
 'nodes':len(nodes),'edges_count':len(edges),
 'edge_types':dict(collections.Counter(x['syntax'] for x in edges)),
 'ambiguous_class_names':dict(unresolved.most_common(15)),
 'edges':sorted(edges,key=lambda e:(e['from'],e['to'],e['syntax']))}
out=R/'tests/callgraph.json'
out.write_text(json.dumps(result,indent=2)+'\n',encoding='utf8')
print(json.dumps({'PHP_nodes':len(nodes),'lexical_edges':len(edges),'types':result['edge_types'],'ambiguous_class_refs':len(unresolved)},indent=2))
