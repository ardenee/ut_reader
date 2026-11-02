#!/usr/bin/env python3
"""Deterministic UnrealDB source-path discovery. Indexing is NOT a pass claim."""
import hashlib,json,re,subprocess,collections
from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
TEST=ROOT/'tests'
ROLE={
'SOURCE':('sourceprofile','sourcefamily','sourceoverlay','version','licensee','sourcepolicy'),
'FILE':('packageheader','fileheader','packagefamily','fileidentity','packageinfo','iostoreinfo','fileinfo','summary'),
'CONTAINER':('archive','binaryreader','compress','encrypt','oodle','zen','pak','iostore','toc','ucas','utoc'),
'NAME':('namemap','fname','identityhash','nametable','namecandidate','dictionary','surrogate'),
'TABLE':('importmap','importreader','importprojection','exportreader','exportmap','exportpayload','publicexport'),
'OBJECT':('serialization','serializer','nativeobject','property','script','bytecode','struct','mesh','model','animation','font','sound','music','texture','material','level','asset','bsp','bulk','primitive','actor'),
'LINKER':('providerselector','providerkey','verifyimport','importresolver','packagestore','linker','redirect','outer','objectresolver','packageindex'),
'DEPENDENCY':('dependency','dependencies','missing','resolved','crossgame','closur','references','refmap'),
'DATABASE':('projection','metadata','staging','candidate','persistence','schema','sql','repository','migrat','backfill','rebuild'),
'INGEST':('upload','ingest','importservice','sourcefetch','source-scan','unverifiedfile','verifyfile','fileverification'),
'OUTPUT':('download','generatedpackage','exportservice','bundle','mirror','federation','storage'),
'ERROR':('error','invalid','corrupt','malformed','recovery','diagnostic','reject'),
'JOBS':('job','worker','queue','background','maintenance','scheduler','retry')
}
ENGINES={
 'ue1':r'(?i)(?:ue1|ut99|unrealgold|unreal-v|c227|c98|classic)',
 'ue2':r'(?i)(?:ue2|ut2003|ut2004|unreal2|ue25)',
 'ue3':r'(?i)(?:ue3|udk|ut3)',
 'ue4':r'(?i)(?:ue4|ut4|uasset|uexp|ubulk)',
 'ue5':r'(?i)(?:ue5|zen|iostore|utoc|ucas)'
}
def tracked_files():
 raw=subprocess.run(['git','ls-files','-z'],cwd=ROOT,capture_output=True,check=True).stdout
 paths=raw.decode('utf8','replace').split('\0')
 return sorted(ROOT/path for path in paths if path and
  ((path.startswith('catalog/bin/') and path.endswith(('.php','.py','.ps1'))) or
   (path.startswith('catalog/') and
    not path.startswith(('catalog/bin/','catalog/compliance/','catalog/tools/','catalog/storage/','catalog/vendor/')) and
    path.endswith(('.php','.js','.ts','.mjs','.sql')))))
def classify(p):
 rel=p.relative_to(ROOT).as_posix();fn=p.stem.lower()
 tags=sorted(k for k,v in ROLE.items() if any(token in fn for token in v))
 if not tags:
  if rel.startswith('catalog/src/'):
   tags=['DATABASE'] if 'Persistence/' in rel else ['JOBS'] if 'Application/' in rel else ['OUTPUT'] if 'Presentation/' in rel else ['GENERAL']
  elif rel.startswith('catalog/bin/'):tags=['OPERATIONS']
  else:tags=['UI']
 eng=[key for key,pattern in ENGINES.items() if re.search(pattern,p.name)] or ['shared']
 b=p.read_bytes()
 m=re.search(r'\bclass\s+(\w+)',b.decode('utf8','replace'))
 return {'path':rel,'sha256':hashlib.sha256(b).hexdigest(),'bytes':len(b),
 'engine_hints':eng,'path_roles':tags,'review_priority':
 'CORE' if rel.startswith('catalog/src/Infrastructure/') and set(tags)&{'FILE','CONTAINER','TABLE','OBJECT','LINKER','DEPENDENCY','DATABASE','INGEST'} else
 'ENTRYPOINT' if rel.startswith('catalog/') and rel.count('/')==1 else 'RELATED',
 'trace_status':'UNTRACED_STATIC_CANDIDATE','class':m.group(1) if m else None}
def main():
 rows=[classify(p) for p in tracked_files()]
 result={'schema_version':1,'provenance':'SHA of current Git-tracked operational PHP, JavaScript, TypeScript and SQL paths; filename roles and engine hints require runtime call verification',
 'total_files':len(rows),'roles':dict(sorted(collections.Counter(k for x in rows for k in x['path_roles']).items())),
 'engine_hints':dict(sorted(collections.Counter(k for x in rows for k in x['engine_hints']).items())),
 'paths':rows}
 (TEST/'code-paths.json').write_text(json.dumps(result,indent=2,ensure_ascii=False)+'\n',encoding='utf8')
 print(json.dumps({'total_paths_mapped':len(rows),'role_counts':result['roles'],'engine_hints':result['engine_hints']},indent=2))
if __name__=='__main__':main()
