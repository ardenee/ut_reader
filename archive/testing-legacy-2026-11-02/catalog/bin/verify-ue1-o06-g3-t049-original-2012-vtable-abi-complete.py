#!/usr/bin/env python3
"""Frozen G3 T049: original compiled 1999 v432, 2012 C227i and 2026 C227k
array network method gates, including genuine 2012 compiled ABI source exclusion.
Does NOT execute previously accepted native engine sessions.
"""
from pathlib import Path
import hashlib,json,struct,re,sys
import pefile
ROOT=Path(__file__).resolve().parents[2]
EV=ROOT/'catalog/compliance/evidence/o06'
checks=0;fail=[]
def check(x,why):
 global checks;checks+=1
 if not x:fail.append(why)
def sha(p):return hashlib.sha256(Path(p).read_bytes()).hexdigest()
source=ROOT/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicPropertyNativeReader.php'
native_reader=ROOT/'catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php'
old=Path(r'C:\Temp\UnrealDB\O06\Native-C227i-G304\System\Core.dll')
new=Path(r'C:\Temp\UnrealDB\O06\Native-227k\System\Core.dll')
headers=Path(r'L:\Source\Games\Unreal\Unreal [v1.227]\Core\Inc\UnType.h')
for p,expect in [
 (old,'287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'),
 (new,'c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'),
 (headers,'b7faf265555ee3c05a03368826613ad68ea5f1bc90076451b908c44b7b77cf69'),
 (source,'5a4739d636f5916235f76f1503996033c166b7633b29e565c5ffa7f2d57d39be'),
 (native_reader,'afca90b65cfb192d23e5d0aaa0f449acb651c85548fb81ccac25bce3a9963714'),
 (EV/'20261030-G3-T049-original-v432-NET.cpp','8fc4e713db1ce80e9e2500b3e925d1f93cbc1aafe9857cbcbbd53e4e39a7f0d3'),
 (EV/'20261030-G3-T049-original-v432-NET.log','e261223209c7e2b81731f98eae7f6a44f97d9cd2081b344082ad4fb3db630892'),
 (EV/'20261029-G3-T049-C227k-original-24-NetSerialize-8-real-int-arrays.log','b1974ebaf64ae972fa4f12ee06f7a349c2fd2bd017c575c168e8e88eee1c5343'),
 (EV/'20261029-G3-T049-C227k-ArrayProperty-original-native.log','8b8cad0ad940ed5a55b6b805b4e88a0fc1fd861e3709208b50423fc050f480a4'),
 (EV/'20261029-G3-T049-C227k-original-NetSerialize-probe.cpp','0c42c3dc5c2949babb3b289e5aa838d746e28af7795d1d747c50d0f37c9db6dd'),
 (EV/'20261030-G3-T049-three-original-28pass-PARTIAL.json','eafb24e48277500afa09dbd3caad921e1b06d4e6cdcfed2bd756a726df8dabbf')
]:
 check(p.is_file() and sha(p)==expect,'original source/native compiled SHA '+str(p))
pold=pefile.PE(str(old));pnew=pefile.PE(str(new))
def exports(p):
 return {e.name.decode('ascii','replace'):e.address for e in p.DIRECTORY_ENTRY_EXPORT.symbols if e.name}
a,b=exports(pold),exports(pnew)
name='??_7UArrayProperty@@6B@';parent='??_7UProperty@@6B@'
check(a.get(name)==0x84274,'original 2012 compiled UArrayProperty vftable RVA0x84274')
check(a.get(parent)==0x83be4,'original 2012 compiled UProperty base vftable RVA0x83be4')
check(b.get(name)==0xc2294,'original 2026 compiled UArrayProperty vftable RVA0xC2294')
def entry(pe,rva,i):
 return struct.unpack('<I',pe.get_data(rva+i*4,4))[0]-pe.OPTIONAL_HEADER.ImageBase
check(entry(pold,a[name],32)==0x49fe0,
      'genuine 2012 class vtable NetSerializeItem index32 is actual Core.dll RVA0x49FE0')
check(pold.get_data(0x49fe0,8)==bytes.fromhex('b801000000c20c00'),
      'genuine compiled 2012 UArray NetSerializeItem returns TRUE ret12, no archive writes')
check(entry(pold,a[name],40)==0x48260 and entry(pold,a[parent],40)==0x48260,
      'both original 2012 UArray and UProperty compiled vtables slot40 last method GetID')
check(entry(pold,a[name],41)==0xa0b04 and entry(pold,a[parent],41)==0xa0790,
      'original 2012 next slot41 is adjacent class RTTI pointer, NOT callable ShouldSerializeItem')
check(entry(pold,a[name],42)==0x3ba10 and entry(pold,a[name],43)==0x3bab0
      and entry(pold,a[name],44)==0x3bb40,
      'original 2012 class vtable ends at slot40: next 3 pointers are entirely NEXT class virtual table')
check(not any('ShouldSerializeItem@UArrayProperty' in k for k in a),
      'genuine 2012 compiled DLL does not export UArrayProperty ShouldSerializeItem method')
check(b.get('?ShouldSerializeItem@UArrayProperty@@UBEHPBEH@Z')==0x81440,
      'original 2026 binary DOES export source-specific ShouldSerializeItem at RVA0x81440')
check(entry(pnew,b[name],55)==0x81440,
      'original 2026 UArrayProperty virtual table slot55 ACTUALLY points to compiled ShouldSerializeItem')
check(b.get('?NetSerializeItem@UArrayProperty@@UBEHAAVFArchive@@PAVUPackageMap@@PAX@Z')==0x7e8b0
      and entry(pnew,b[name],38)==0x7e8b0,
      'real original 2026 compiled network method is source-selected slot38')
text=headers.read_text(encoding='latin1')
i=text.index('class CORE_API UArrayProperty : public UProperty')
h=text[i:text.index('};',i)]
check('UBOOL ShouldSerializeItem(const BYTE* Data, INT Index) const;' in h,
      '2012-distributed SDK header DOES declare newer Should method: source-vs-binary build skew explicitly identified')
check('UBOOL NetSerializeItem( FArchive& Ar, UPackageMap* Map, void* Data ) const;' in h,
      '2012 source header records array net method with actual binary exported counterpart')
current=source.read_text(encoding='utf-8')
check("'should_serialize_method'=>'compiled_2012_vtable_absent_SDK_header_only'" in current,
      'production reader explicitly source-gates unobservable original 2012 Should ABI, never guesses boolean')
check("'native_output_bytes_for_0_1_2_int_values'=>[2,10,18]" in current
      and "'native_output_bytes_for_0_1_2'=>[0,0,0]" in current,
      'source-gated 227k vs v432/2012 actual archived network method lengths')
check("'network_packet_payload_decoding_in_package_file_reader']=false" in current,
      'network packet codec is EXCLUDED from on-disk .u file parser scope')
rdr=native_reader.read_text(encoding='utf-8')
check('CatalogUE1ClassicSourceFamily::UNREAL_V227I' in rdr
  and 'CatalogUE1ClassicSourceFamily::UNREAL_V227K' in rdr,
  'source-specific actual native-engine profile propagated beyond shared v227 archived package family')
prior=json.loads((EV/'20261029-G3-T049-C227i227k-NetSerialize-59pass-PARTIAL.json').read_text(encoding='utf-8-sig'))
check(prior['ok'] is True and prior['checks']==59 and prior['status']=='PARTIAL_ONLY',
      'prior sealed original actual 2012/2026 compiled Net and Should native 59/59 scope evidence reused')
check(prior['original_2012_native_NetSerializeItem']['serialized_bytes']==0
      and prior['original_2012_native_NetSerializeItem']['return']==1,
      '2012 genuine compiled Core.dll Net result=1 zero bytes')
check(prior['original_2026_native_NetSerializeItem']['native_successful_calls']==24,
      'original compiled 2026 Core.dll 24 actual network byte-level tests with 8 original Engine properties')
check(prior['original_2026_native_ShouldSerializeItem']['native_boolean_calls']==32,
      'original compiled 2026 Core.dll 32 real actual ShouldSerializeItem boolean tests')
prev28=json.loads((EV/'20261030-G3-T049-three-original-28pass-PARTIAL.json').read_text(encoding='utf-8-sig'))
check(prev28['ok'] is True and prev28['checks']==28 and
      prev28['new_original_v432_native_successful_NetSerializeItem_cases']==3,
      'original 1999 v432 actual compiled three real native array network method 28/28 evidence reused')
log=(EV/'20261030-G3-T049-original-v432-NET.log').read_bytes()
if log.startswith(b'\xff\xfe'):log=log[2:].decode('utf-16le')
else:log=log.decode('utf-8','replace')
check('G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=' in log and 'native_error=0' in log
      and 'G3_T049_ORIGINAL_V432_NET_ALL_PASS=1' in log,
      'original 1999 compiled retail Core.dll loaded original genuine package and full native Net probes passed')
for i in [0,1,2]:
 check(f'G3_T049_ORIGINAL_V432_NET case={i} result=1 nativeCount={i} bytes=0 hex=' in log,
      'actual new original v432 Net called once per original native object array count '+str(i))
ok=not fail
print(json.dumps({'ok':ok,'checks':checks,'failures':fail,
 'full_frozen_g3_ids':{'T049':'PASS'} if ok else {},
 'original_native_profiles':['UT99 v432','original 2012 C227i','original 2026 C227k'],
 'original_2012_array_vtable_slots':41,
 'original_2012_sdk_header_declared_Should_but_compiled_virtual_method_absent':True,
 'original_2026_ShouldSerializeItem_vtable_index':55,
 '2012_unobservable_branch_exclusion':'compilation/ABI mismatch: next word after GetID is next class RTTI, no original callable virtual Should',
 'real_network_Net_method_writes_for_int_count_0_1_2':{'v432':[0,0,0],'C227i':[0,0,0],'C227k':[2,10,18]},
 'network_packet_codec_in_U_package_reader':'EXCLUDED',
 'already_accepted_original_native_execution_rerun':0,
 'overall_G3_complete':False},indent=2,ensure_ascii=False))
sys.exit(0 if ok else 1)
