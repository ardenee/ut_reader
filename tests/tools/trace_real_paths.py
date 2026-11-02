#!/usr/bin/env python3
"""Bounded proof that proposed test cases correspond to real PHP calls.
No Epic engine testing; no DB writes; only --write annotates canonical contract JSON.
"""
from pathlib import Path
import argparse,json
ROOT=Path(__file__).resolve().parents[2]
REG=ROOT/'tests/contracts.json'
anchors={
 'dispatch':[
 ('catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php','CatalogReaderResolver::resolve('),
 ('catalog/src/Infrastructure/Readers/CatalogReaderResolver.php','if (in_array($engineKey,')],
 'tables':[],
 'verify_import':[],
 'mount_provider':[
 ('catalog/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php','$this->selector->select('),
 ('catalog/src/Infrastructure/Metadata/PdoUedb5PhysicalProviderSelector.php','public function select(')],
 'dependency_publish':[
 ('catalog/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php','$this->rebuilder->rebuild('),
 ('catalog/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php','$this->publisher->publish(')],
 'website_dependency':[
 ('catalog/game-missing.php','new PdoGameMissingDependencyQuery('),
 ('catalog/dependency-cross-examine.php','new PdoGameDependencyCrossExamineQuery(')]
}
builders={
 'ue1':['Uedb5UnrealSnapshotBuilder::build(','Uedb5Ut99SnapshotBuilder::build('],
 'ue2':['Uedb5Unreal2SnapshotBuilder::build(','Uedb5Ut2003SnapshotBuilder::build(','Uedb5Ut2004SnapshotBuilder::build('],
 'ue3':['Uedb5Ut3SnapshotBuilder::build('],
 'ue4':['Uedb5Ut4SnapshotBuilder::build('],
 'ue5':['Uedb5Ue5ZenIoStoreSnapshotBuilder::build(']
}
imports={
 'ue1':'PdoUe1VerifyImportProjectionResolver::resolveInMemoryOutcome(',
 'ue2':'PdoUe2VerifyImportProjectionResolver::resolveInMemoryOutcome(',
 'ue3':'PdoUe3VerifyImportProjectionResolver::resolveInMemoryOutcome(',
 'ue4':'PdoUe4VerifyImportProjectionResolver::resolveInMemoryOutcome(',
 'ue5':'Uedb5Ue5ZenDependencyResolver::resolve('
}
def sites(case):
 key=case['operation'];engine=case['engine']
 if key in anchors and anchors[key]:return anchors[key]
 if key=='tables':
  owner=('catalog/src/Infrastructure/Import/CatalogZenIoStorePackagePublisher.php' if engine=='ue5'
          else 'catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php')
  return [(owner,v) for v in builders[engine]]
 if key=='verify_import':
  owner=('catalog/src/Infrastructure/Metadata/Uedb5DependencyRebuilder.php' if engine=='ue5'
        else 'catalog/src/Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php')
  return [(owner,imports[engine])]
 if key=='iostore_ingest':return [
  ('catalog/src/Infrastructure/Jobs/CatalogIoStoreImportJobHandler.php','CatalogIoStoreContainerStorage('),
  ('catalog/src/Infrastructure/Jobs/CatalogIoStoreImportJobHandler.php','JobType::INDEX_IOSTORE_PACKAGE_NAMES'),
  ('catalog/src/Infrastructure/Jobs/CatalogIoStoreImportJobHandler.php','JobType::PUBLISH_IOSTORE_PACKAGES'),
  ('catalog/src/Infrastructure/Import/CatalogZenIoStorePackagePublisher.php','Uedb5Ue5ZenIoStoreSnapshotBuilder::build(')]
 if key=='ut2004_legacy_ue1':return [('catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php',"$sourceKey === 'ut2004'")]
 raise ValueError(case['key'])
def source_site(path,pattern):
 source=ROOT/path
 assert source.is_file(),path
 raw=source.read_text('utf8')
 pos=raw.find(pattern)
 assert pos>=0,(path,pattern)
 return {'file':path,'line':raw.count('\n',0,pos)+1,'call_site':pattern}
def main():
 parser=argparse.ArgumentParser()
 parser.add_argument('--write',action='store_true',help='Update line-numbered static evidence in single register')
 args=parser.parse_args()
 doc=json.loads(REG.read_text('utf8'));count=0
 for row in doc['engine_contracts']:
  found=[source_site(path,pattern) for path,pattern in sites(row)]
  assert found
  if args.write:
   row['php_source_sites']=found
   row['code_path_evidence']='CONFIRMED_PHP_STATIC_CALLSITES; RUNTIME_EXECUTION_UNPROVEN'
  else:
   assert row.get('php_source_sites')==found,('Source changed; rerun --write only after review',row['key'])
  count+=len(found)
 if args.write:REG.write_text(json.dumps(doc,indent=2,ensure_ascii=False)+'\n',encoding='utf8')
 print(json.dumps({'engine_operation_cases':len(doc['engine_contracts']),
 'confirmed_static_implementation_call_sites':count,
 'actual_runtime_proven_cases':sum(x['runtime_call_chain_verified'] for x in doc['engine_contracts']),
 'tests_executed':0,'register_updated':args.write},indent=2))
if __name__=='__main__':main()
