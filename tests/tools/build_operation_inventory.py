from __future__ import annotations
from pathlib import Path
from collections import Counter, defaultdict
import hashlib
import json
import sys

ROOT = Path(__file__).resolve().parents[2]
DEFINITIONS = ROOT/'tests/tools/operation_inventory_definitions.json'
OUTPUT = ROOT/'tests/OPERATION-INVENTORY.md'
INDEX = json.loads((ROOT/'tests/code-paths.json').read_text('utf-8'))
KNOWN = {x['path']: x for x in INDEX['paths']}
CONTRACTS = json.loads((ROOT/'tests/contracts.json').read_text('utf-8'))['engine_contracts']
OPERATIONS = json.loads(DEFINITIONS.read_text('utf-8'))
WORKER_REGISTRY = (ROOT/'catalog/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php').read_text('utf-8')
ENGINE_MARKERS = [('1','UE1'),('2','UE2'),('3','UE3'),('4','UE4'),('C','UE5 classic'),('Z','UE5 Zen')]
TICK=chr(96)
def code(x):return TICK+str(x)+TICK
def line(path, hint):
    file=ROOT/path
    if not file.is_file():
        raise ValueError('Missing original code file: '+path)
    s=file.read_text('utf-8','replace')
    pos=s.find(hint)
    if pos<0:
        raise ValueError('Missing exact source anchor '+repr(hint)+' in '+path)
    if path not in KNOWN:
        raise ValueError('Original source path absent from tests/code-paths.json: '+path)
    digest=hashlib.sha256(file.read_bytes()).hexdigest()
    if digest!=KNOWN[path]['sha256']:
        raise ValueError('Tracked source changed without code-path index refresh: '+path)
    return s.count('\n',0,pos)+1
def link(path, ln):
    return '[{}:{}](https://github.com/ardenee/ut_reader/blob/main/{}#L{})'.format(
        path.split('/')[-1],ln,path,ln)
def reference_type(row):
    source=(ROOT/row['entry']).read_text('utf-8','replace')
    implementing_class=Path(row['implementation']).stem
    if row['implementation_hint'] in source or implementing_class in source:
        return 'named reference'
    entry_class=Path(row['entry']).stem
    if row['entry'].startswith('catalog/src/Infrastructure/Jobs/') and entry_class in WORKER_REGISTRY:
        return 'registered job'
    return 'needs caller trace'

def validate():
    keys=set()
    for row in OPERATIONS:
        key=(row['group'],row['name'])
        if key in keys:raise ValueError('Duplicate operation '+str(key))
        keys.add(key)
        for e in row['engines']:
            if e not in dict(ENGINE_MARKERS):raise ValueError('Unexpected engine marker '+e)
        line(row['entry'],row['entry_hint'])
        line(row['implementation'],row['implementation_hint'])
        evidence=row.get('route_evidence',[])
        if evidence:
            if row.get('route_confidence')!='SOURCE_ROUTE_PINNED_NOT_EXECUTED':
                raise ValueError('Caller route is not identified as static source only: '+row['name'])
            for node in evidence:
                line(node['path'],node['anchor'])
        area=row['area']
        if area not in {'dispatch','tables','verify_import','mount_provider','dependency_publish','website_dependency','ut2004_legacy_ue1','iostore_ingest'}:
            raise ValueError('Contract mapping not an existing operation area: '+area)
    return keys
def build():
    validate()
    statuses=Counter(x['status'] for x in CONTRACTS)
    applies=Counter(e for row in OPERATIONS for e in row['engines'])
    groups=defaultdict(list)
    for i,row in enumerate(OPERATIONS,1):
        groups[row['group']].append((i,row))
    total=sum(applies.values())
    reference_counts=Counter(reference_type(row) for row in OPERATIONS)
    source_routed=sum(bool(row.get('route_evidence')) for row in OPERATIONS)
    by_dimension={
        'Engine serialization / provider semantics':sum(row['group'][0] in '235' for row in OPERATIONS),
        'Catalog metadata projections / visible outcomes':sum(row['group'][0] in '46' for row in OPERATIONS),
        'Intake / recovery workflow correctness':sum(row['group'][0] in '17' for row in OPERATIONS),
    }
    assert sum(by_dimension.values())==len(OPERATIONS)
    plain=[
        '# UnrealDB implementation operation inventory — UE1–UE5',
        '',
        '**Discovery-only; not a second test register.** All existing acceptance cases and their PASS/BLOCKED statuses remain in [contracts.json](contracts.json). This document identifies *what UnrealDB code actually implements* so subsequent source-parity work can be measured by operation, engine and actual entrypoint.',
        '',
        '**Origin:** {} distinct operations found at actual code entrypoint/worker and implementation file pairs (each named source anchor checked against the current tracked files).'.format(len(OPERATIONS)),
        '',
        '**Legend:** ✓ = source-mapped implementation and route exists, **not a runtime trace or Epic PASS**; — = not applicable. UE5 classic and UE5 Zen are two independent formats belonging to the *same fifth engine*. One operation may have several more entrypoints; this table gives a representative real route.',
        '',
        '**Progress:** Discovery coverage = {} named operations, {} engine/format-applicable code paths. Engine audit acceptance remains {} PASS / {} BLOCKED across {} *coarser* existing cases. These numbers must not be divided into each other or treated as the percentage of actual Epic-correct operations.'.format(
            len(OPERATIONS),total,statuses['PASS'],statuses['BLOCKED'],len(CONTRACTS)),
        '',
        '**Static linkage:** {} rows now have explicit checked caller-route steps. Of all {} rows, {} have a directly named implementation source reference, {} have an existing worker-factory registration (a job route) and {} have only a loose two-file lexical link. These categories overlap with explicit routes; **none of this is an observed runtime execution or Epic parity test**.'.format(source_routed,len(OPERATIONS),reference_counts['named reference'],reference_counts['registered job'],reference_counts['needs caller trace']),
        '',
        '**Review domains:** ' + '; '.join('{} {}'.format(total,label) for label,total in by_dimension.items()) + '. These domains distinguish source-comparable Unreal binary/loader semantics from application-specific persistence, websites and queue reliability; Epic does not implement UnrealDB uploads or MySQL.',
        '',
        '| Engine / branch | Applicable discovered operations |',
        '|---|---:|',
    ]
    for key,label in ENGINE_MARKERS:
        plain.append('| {} | {} |'.format(label,applies[key]))
    ue5unique=sum('C' in row['engines'] or 'Z' in row['engines'] for row in OPERATIONS)
    plain += ['| UE5 (unique combined operations, no C/Z double count) | {} |'.format(ue5unique),'',
        '### Counting rules',
        '',
        '- Operations are file-format/content, storage, dependency, and output *behaviours*, not PHP source-file counts or new tests. Shared jobs and website reads are counted once as operations, but are applicable to each engine in which the route can process original sources.',
        '- ✓ is **static code discovery**: the entrypoint and implementing method/class exist and are in the tracked production source index. Dynamic router selection, actual run-time reachability, original Epic function, valid version branch, and UEDB5/site outcome remain separate audit work.',
        '- Avoid auditing unrelated engine render/physics/GC/gameplay. The main verified-file pipeline stores header, name, import and export metadata; optional/diagnostic asset-object payload decoders are outside its current core acceptance.',
        '- All existing source-pinned PASS results retain their narrow original scope. A broad PASS for '+code('tables')+' does not magically prove every underlying operation below.',
        '',
    ]
    plain.extend([
        '### Canonical engine-specific reader and snapshot branches',
        '',
        '| Engine | Actual format reader | Source profile / boundary |',
        '|---|---|---|',
        '| UE1 | '+code('catalog/src/Infrastructure/Readers/CatalogUE1PackageReader.php')+' | Unreal and UT99 source overlays, not inferred from package version alone |',
        '| UE2 | '+code('catalog/src/Infrastructure/Readers/CatalogUE2PackageReader.php')+' | Unreal II, UT2003, UT2004 and separately UE2-owned pre-v100 UT2004 wire-format reader |',
        '| UE3 | '+code('catalog/parsers/UE3CatalogReader.php')+' | UT3 package summary, names, imports, exports and compression fields |',
        '| UE4 | '+code('UE4/UnrealPackageReader.php')+' | UE4 classic package tables and independent version gates |',
        '| UE5 classic | '+code('UE5/UnrealPackageReader.php')+' | UE5 classic package summary and LinkerLoad-shaped imports/exports |',
        '| UE5 Zen | '+code('catalog/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php')+' | Distinct IoStore TOC and Zen package-ID/PackageStore path |',
        '',
    ])
    plain.extend([
        '## Source-anchored operation routes - full current inventory',
        '',
        '**{} source-anchored operation routes** have a checked series of entrypoint, job dispatch, service and/or implementation tokens. They are *source-only candidate execution paths*, NOT captured Xdebug calls or live PRD execution. Every linked step is checked against both the literal source and its tracked file SHA-256. The selected representative chain is not an exhaustive callgraph and dynamic dispatch/branch reachability is **not proven** by linked code fragments alone.'.format(source_routed),
        '',
        '| Operation | Source-pinned caller chain (entrypoint / worker / implementation) | Evidence class |',
        '|---|---|---|',
    ])
    for number,row in enumerate(OPERATIONS,1):
        evidence=row.get('route_evidence')
        if not evidence:
            continue
        links=[]
        for node in evidence:
            links.append(link(node['path'],line(node['path'],node['anchor'])))
        plain.append('| {} {} | {} | Static code anchors, **no runtime execution claimed** |'.format(number,row['name'],' → '.join(links)))
    plain.extend([
        '',
        '**Corrections to previous route assumptions:** Public uploads are quarantined and staged unverified, not automatically published. The admin source-scan page uses a synchronous runner distinct from the registered background scan worker. The package-coverage page consumes cached projections separately populated by a coverage analyzer. The file-examine dependency endpoint queries source-current UEDB5 SQL directly, not through the compact dependency facade. File search-index refresh is separate from compact name/object candidate publication; IoStore name indexing is separate from Zen package publication. Source-identity repair and possible-misnamed scanning are distinct queued operations. UE4 and UE5 classic have independent source readers for version, soft reference, DependsMap and preload fields. The cooked Zen ScriptObjects reader has no identified live publication caller and is excluded from this core denominator.',
        '',
    ])
    for group,items in groups.items():
        plain += ['## '+group,'',
            '| # | UnrealDB operation | UE1 | UE2 | UE3 | UE4 | UE5 classic | UE5 Zen | Current route → implementing source | Link confidence | Existing contract area |',
            '|---:|---|:--:|:--:|:--:|:--:|:--:|:--:|---|---|---|']
        for number,row in items:
            markers=['✓' if e in row['engines'] else '—' for e,_ in ENGINE_MARKERS]
            start=link(row['entry'],line(row['entry'],row['entry_hint']))
            impl=link(row['implementation'],line(row['implementation'],row['implementation_hint']))
            plain.append('| {} | {} | {} | {} → {} | {} | {} |'.format(
                number,row['name'],' | '.join(markers),start,impl,reference_type(row),code(row['area'])))
        plain.append('')
    plain += [
        '## Explicitly excluded from the baseline',
        '',
        '| Code found | Why it is not automatically a core UE1–UE5 source-parity operation |',
        '|---|---|',
        '| '+code('CatalogUE1ClassicNativeObjectReader')+', UE1 resource Mesh/Sound/Texture/geometry decoders | Diagnostic '+code('catalog/bin/inspect-unreal-file.php')+' and unverified staging helpers exist; normal verified UEDB5 snapshot builders preserve *summary/name/import/export tables*. Do not impose unrelated native payload tests. |',
        '| UE4 thumbnail/asset-registry payload parser helpers | The UE4 reader parses some additional records, but the source-selected '+code('Uedb5Ut4SnapshotBuilder')+' stores only the sections it explicitly emits. A parser method alone does not establish a published/visible behaviour. |',
        '| Epic renderer, physics, garbage collection and game scripting execution | No matched UnrealDB package/catalogue user-facing operation was identified. |',
        '| '+code('Uedb5ZenScriptObjectsReader')+' | Only a diagnostic-only cooked ScriptObjects decoder was identified; no live publication caller was found. The Zen package-ID/script import resolver is already counted independently. |',
        '| Website auth, UI theme, admin TOTP, GeoIP, arbitrary telemetry | Generic site functionality, not an Unreal package format operation. |',
        '',
        '## How progress will be measured',
        '',
        '1. **Discovery:** number of source-identified operation families with real code anchors: {} rows. This is a current inventory baseline, **not** proof that dynamic-code discovery is exhaustive.'.format(len(OPERATIONS)),
        '2. **Applicability:** each ✓ cell is a concrete engine/branch operation to examine, not a separate test case. Current source matrix has {} applicable cells; UE5 is still a single engine.'.format(total),
        '3. **Runtime audit:** checked static source routes are mapped, but no runtime function execution is established by these token chains. Trace real source-selected inputs and the resulting UEDB5/user outputs under the matching existing case in '+code('tests/contracts.json')+'.',
        '4. **Epic differential:** compare only that implemented behaviour to the applicable Epic engine/game source revision and original fixture. Where operations share a verified canonical function or byte semantics, reuse accepted evidence instead of repeating tests.',
        '5. **Outcome:** keep prior scoped acceptance statuses authoritative and change them only with matching new evidence. Add no new standalone PASS scores or parallel acceptance register.',
        '',
        '**Regenerate source mapping:** '+code('python tests/tools/build_operation_inventory.py')+' after editing definitions in '+code('tests/tools/operation_inventory_definitions.json')+'. The tool only reads repository source, checks anchors, and updates this Markdown file. It does not touch PRD, source packages, or any database.',
        '',
    ]
    OUTPUT.write_text('\n'.join(plain).rstrip()+'\n','utf-8')
    print(json.dumps({'document':str(OUTPUT),'operation_count':len(OPERATIONS),
        'applicable_engine_branch_cells':total,'per_engine_branch':dict(applies),
        'ue5_unique':ue5unique,
        'group_counts':{g:len(v) for g,v in groups.items()},
        'existing_contracts':len(CONTRACTS),'existing_statuses':dict(statuses),
        'source_linkage':dict(reference_counts),'explicit_source_routes_checked':source_routed,
        'review_domains':by_dimension},indent=2))

if __name__=='__main__':
    build()
