#!/usr/bin/env python3
"""G3 grouped T071/T072/T075/T076 source/native skipped tagged-value audit.
Original 1999 v400 class sources, original stock v432 compiled dump,
single later 469f-only ClipMarker source explicitly NOT substituted for v432.
No original native process is run.
"""
from pathlib import Path
import hashlib,json,re,collections,sys
ROOT=Path(__file__).resolve().parents[2]
e=ROOT/'catalog/compliance/evidence/o06'
f=e/'20261031-G3-T072-T075-T076-original-v432-608-skip32-readonly.json'
j=json.loads(f.read_text(encoding='utf-8-sig'))
err=[];checks=0;matches=[]
def ck(ok,msg):
 global checks
 checks+=1
 if not ok:err.append(msg)
ck(j.get('ok') is True and j.get('checks')==1705 and j.get('failures')==[],'G3 corrected source-native original dump logic 1705/1705')
ck(j.get('source_class_default_tags')==608 and j.get('original_native_warning_skip_tag_count')==32,'all 608 original native class defaults and 32 deliberate native skip tags')
ck(j.get('native_exact_value_matches')==575 and j.get('native_config_ini_overrides')==1,'575 exact original native values plus one actual config override')
ck(j.get('current_ini_SHA_drift_unchanged') is True,'do not falsify originally captured runtime config SHA')
ck(j.get('genuine_native_original_engine_reruns_by_checker')==0 and j.get('T072_T075_T076_full_aggregate_accepted') is False,
   'source/native partial checker does not fabricate full 3-owner acceptance')
manifest=json.loads((ROOT/'catalog/compliance/UE1-O06-G5-UCLASS-DEFAULTS-NATIVE-608.json').read_text(encoding='utf-8'))
for section in ('original_package','original_compiled_core','actual_compiled_session_memory','native_class_log'):
 p=Path(manifest[section]['path'])
 ck(p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==manifest[section]['sha256'],'original UE1 compiled saved native source bytes SHA '+section)
ck(hashlib.sha256(Path(manifest['native_config_ini']['path']).read_bytes()).hexdigest()!=manifest['native_config_ini']['sha256'],
  'original historical runtime source INI cannot be silently repinned')
source=Path(r'L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Engine\Classes')
later=Path(r'L:\Source\Games\UT99\OldUnreal-UTPatch469f-SDK-Windows\Engine\Classes')
seen=set();sources={};v400=0;late=0
for x in j['original_native_warning_skip_tag_values']:
 cl=x['class'];name=x['property'];actual=x['original_native_loaded_value']
 key=(cl,name)
 ck(key not in seen,'unique typed original skipped CDO property '+cl+'.'+name);seen.add(key)
 ck(x['source_archive_value_kind']=='epic_skipped_declared_tag_bytes'
   and x['archive_declared_tag_policy']=='native_warning_skip_declared_tag_bytes'
   and x['native_property_address']>0 and x['native_property_offset']>=0,
   'source-skipped native value at actual original UProperty address '+cl+'.'+name)
 p=source/(cl+'.uc')
 original_file=p.is_file()
 if not original_file:p=later/(cl+'.uc')
 ck(p.is_file(),'authored original class source exists for '+cl)
 if not p.is_file():continue
 content=p.read_text(encoding='latin1')
 k=content.lower().rfind('defaultproperties')
 ck(k>=0,'source defaultproperties block exists '+cl)
 if k<0:continue
 body=content[k:]
 pattern=r'(?im)^\s*'+re.escape(name)+r'\s*=\s*(?:"([^"]*)"|([^\s\r\n;}]+))'
 rawvalues=[m.group(1) if m.group(1) is not None else m.group(2) for m in re.finditer(pattern,body)]
 def normalize(val):
  s=val.strip()
  m=re.match(r"^\w+'(?:Engine\.)?([^']+)'$",s)
  if m:return m.group(1).split('.')[-1]
  return s.split('.')[-1] if name.lower() in ('texture','icon') else s
 norm=[normalize(s) for s in rawvalues]
 found=actual in norm
 ck(found,'native actual original compiled default value vs original authored source '+cl+'.'+name+'='+str(actual))
 if original_file:v400+=1
 else:late+=1
 label='1999-11-30 original UT99 v400 source' if original_file else 'later 469f source corroboration only; DOES NOT prove same v432 source'
 sha=hashlib.sha256(p.read_bytes()).hexdigest();sources[str(p)]={'sha256':sha,'profile':label}
 matches.append({'class':cl,'property':name,'tag_type':x['declared_tag_type'],
  'actual_native_compiled_v432_value':actual,'source_proven_default_literal':rawvalues,
  'source_version_provenance':label,'source_sha256':sha,'source_file_private_path':str(p),
  'runtime_loaded_pointer_or_string_observed_in_compiled_dump':True,
  'archive_declared_tag_was_skipped_not_decoded':True})
ck(len(seen)==32 and v400==31 and late==1,'31 source-original v400 matches plus one 469f source ClipMarker, never falsely equated')
ck(dict(collections.Counter(x['declared_tag_type'] for x in j['original_native_warning_skip_tag_values']))==
   {'ObjectProperty':24,'NameProperty':4,'StrProperty':4},'32 source skipped actual original runtime type distribution')
report={'ok':not err,'checks':checks,'errors':err,
 'original_compiled_stock_v432_loaded_cdo_native_skips_verified':len(matches),
 'actual_original_1999_UT99_v400_source_literal_31':v400,
 'later_469f_only_ClipMarker_source_corroboration_not_native_v432_source':late,
 'original_native_value_total':608,'original_native_exact_serialized_matches':575,
 'original_native_config_loaded_override':1,
 'original_native_declared_skipped_values':32,
 'original_original_captured_ini_SHA_unavailable':True,
 'no_original_native_engine_reexecution':True,
 'no_T071_T072_T075_T076_full_gate_claim':True,
 'source_files_pinned':sources,'original_loaded_native_skipped_fields':matches}
print(json.dumps({k:v for k,v in report.items() if k not in ('source_files_pinned','original_loaded_native_skipped_fields')},indent=2))
out=e/'20261031-G3-T071-T072-T075-T076-32-original-v432-source-native-skips-verified.json'
out.write_text(json.dumps(report,indent=2)+'\n',encoding='utf-8')
sys.exit(0 if not err else 1)
