#!/usr/bin/env python3
"""Live O06 source discovery register. Never infer acceptance from discovery."""
import argparse, datetime, hashlib, json, re
from collections import Counter
from pathlib import Path

REPO=Path(__file__).resolve().parents[2]
DOC=REPO/"catalog/compliance/PROJECT-STATUS.md"
DATA=REPO/"catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json"
ROOTS={
"R82":Path(r"L:\Source\Games\Unreal\Unreal [v0.82] [1996-00-00]\SRC"),
"R83":Path(r"L:\Source\Games\Unreal\Unreal [v0.83] [1996-10-01]\Src"),
"R96":Path(r"L:\Source\Games\Unreal\Unreal [v0.84a] [1996-12-11]\Src"),
"U86":Path(r"L:\Source\Games\Unreal\Unreal [v0.86x] [1997-05-25]\Src"),
"U97":Path(r"L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src"),
"D97":Path(r"L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src"),
"C98":Path(r"L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]"),
"C227":Path(r"L:\Source\Games\Unreal\Unreal [v1.227]"),
"T99":Path(r"L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]"),
}
LABELS={"R82":"1996 v0.82 resource registrations", "R83":"1996 v0.83 resource registrations",
"R96":"1996 v0.84a resource registrations", "U86":"1997 v0.86x object class registrations", "U97":"1997 v0.867 object class registrations",
"D97":"1997 custom SerializeData definitions", "C98":"1998 v1.200 archive Serialize definitions",
"C227":"v1.227 source Serialize definitions (version-specific, provenance to verify)",
"T99":"1999 UT99 v400 archive Serialize definitions"}
STATES={"DISCOVERED","MAPPED","PARTIAL","DEV_PASS","PRD_PASS","ACCEPTED","GAP","EXCLUDED","BLOCKED"}
BEGIN="<!-- UE1-O06-LIVE-REGISTER-BEGIN -->"
END="<!-- UE1-O06-LIVE-REGISTER-END -->"
NOW=datetime.datetime.now(datetime.timezone.utc).isoformat(timespec="seconds")
BT=chr(96)
def find_symbols():
    found={}
    for branch,root in ROOTS.items():
        if not root.is_dir(): raise RuntimeError("Source root unavailable: "+str(root))
        for file in sorted(root.rglob("*.cpp")):
            try: lines=file.read_bytes().decode("latin-1").splitlines()
            except OSError: continue
            for lineno,line in enumerate(lines,1):
                sym=None
                if branch in ("R82","R83","R96"):
                    m=re.search(r"\bAUTOREGISTER_RESOURCE\s*\(\s*RES_\w+\s*,\s*(U\w+)\s*,",line)
                    if m: sym=m[1]
                elif branch in ("U86","U97"):
                    m=re.search(r"\bIMPLEMENT_(?:DB_)?CLASS\s*\(\s*([AU]\w+)\s*\)",line)
                    if m: sym=m[1]
                elif branch=="D97":
                    m=re.match(r"^\s*(?:void\s+)?([AU]\w+)::SerializeData\s*\(\s*FArchive\s*&",line)
                    if m: sym=m[1]+"::SerializeData"
                else:
                    m=re.match(r"^\s*(?:\w+\s+)*([A-Za-z_]\w*)::Serialize\s*\(\s*FArchive\s*&",line)
                    if m: sym=m[1]+"::Serialize"
                if sym is None: continue
                key=branch+":"+sym
                pos={"path":file.relative_to(root).as_posix(),"line":lineno}
                if key not in found:
                    found[key]={"id":key,"branch":branch,"symbol":sym,"locations":[],
                    "status":"DISCOVERED","reader":"","test":"","evidence":"",
                    "remaining":"Source-to-reader/test classification not yet completed",
                    "updated_at_utc":NOW}
                if pos not in found[key]["locations"]:found[key]["locations"].append(pos)
    return found
def clean(value):
    return str(value).replace("|","\\|").replace("\n"," ").replace(BT,"'")
def render(entries):
    counts=Counter(e["status"] for e in entries)
    head=[
    BEGIN,"## O06 live source serializer register","",
    "**Canonical recovery reference:** this table is updated during discovery, mapping, implementation, tests and deployment, not retrospectively.",
    "",
    "Found source entries: **"+str(len(entries))+"** ("+", ".join(k+"="+str(sum(e["branch"]==k for e in entries)) for k in ROOTS)+").",
    "",
    "**Current per-entry status:** "+", ".join(k+"="+str(counts[k]) for k in sorted(counts))+".",
    "",
    "**States:** "+BT+"DISCOVERED"+BT+" = located in Epic source only; "+BT+"MAPPED"+BT+" = source behavior/reader mapped; "+
    BT+"PARTIAL"+BT+" = implementation or tests exist but contract incomplete; "+
    BT+"GAP"+BT+" = confirmed missing implementation; "+BT+"BLOCKED"+BT+" = named prerequisite; "+
    BT+"EXCLUDED"+BT+" = proven non-saveable or runtime-only; "+
    BT+"DEV_PASS"+BT+"/"+BT+"PRD_PASS"+BT+" = explicit source-scoped named tests passed at that target; "+
    BT+"ACCEPTED"+BT+" = source contract signed off. An entry that has passed a narrow fixture but may have unresolved fields remains PARTIAL.",
    "",
    "**Mandatory live workflow:** (1) On discovering a symbol, add it immediately as DISCOVERED with source location. "+
    "(2) Update to MAPPED/PARTIAL/GAP when the reader/code is inspected, naming remaining work. "+
    "(3) Record test script, evidence and exact outcome when run; change the same row before starting another task. "+
    "(4) Commit/push/deploy the updated status with each bounded checkpoint. "+
    "(5) Never turn a registration or a corpus-only result into serializer acceptance. "+
    "Never replace an older failure without retaining its journal. The O06 test runner records STARTED then PASS/FAIL here automatically, while leaving class-level acceptance unchanged. The six O06 gates remain fixed even if new rows are discovered.",
    "",
    "Machine-readable file: "+BT+"catalog/compliance/UE1-O06-SERIALIZER-REGISTER.json"+BT+
    ". Regenerate/update using "+BT+"python catalog/bin/sync-ue1-o06-serializers.py"+BT+
    " or "+BT+"--set-status ID STATUS --reader ... --test ... --evidence ... --remaining ..."+BT+
    ". Existing statuses survive rescans. Paths below are relative to their corresponding Epic source roots. "+
    "**These rows are source sightings, not all distinct on-disk requirements.**",
    ""]
    for branch in ROOTS:
        rows=[e for e in entries if e["branch"]==branch]
        head += ["### "+branch+": "+LABELS[branch]+" ("+str(len(rows))+")","",
        "| Serializer / class | Epic source path:line | State | Reader / test | Outstanding / evidence |",
        "|---|---|---|---|---|"]
        for e in rows:
            source="; ".join(x["path"]+":"+str(x["line"]) for x in e["locations"])
            targets="; ".join(v for v in (e.get("reader",""),e.get("test","")) if v) or "—"
            reason="; ".join(v for v in (e.get("remaining",""),e.get("evidence","")) if v) or "—"
            verify=e.get("last_verification")
            state=e["status"]
            if verify:
                state+="; latest "+verify["target"]+" test "+verify["state"]
            head.append("| "+BT+clean(e["symbol"])+BT+" | "+BT+clean(source)+BT+
                        " | **"+clean(state)+"** | "+clean(targets)+" | "+clean(reason)+" |")
        head.append("")
    head += [END]
    return "\n".join(head)
def save(entries):
    items=sorted(entries.values(),key=lambda e:(list(ROOTS).index(e["branch"]),e["symbol"]))
    DATA.write_text(json.dumps({"schema":1,"updated_at_utc":NOW,"entries":items},indent=2,ensure_ascii=False)+"\n",encoding="utf-8")
    body=DOC.read_text(encoding="utf-8-sig")
    report=render(items)
    if BEGIN in body and END in body:
        start=body.index(BEGIN);end=body.index(END,start)+len(END)
        body=body[:start]+report+body[end:]
    else:
        anchor="## O06 fixed closure board"
        if anchor not in body:raise RuntimeError("O06 insertion anchor missing")
        body=body.replace(anchor,report+"\n\n"+anchor,1)
    # Refresh recovery summary at the same moment as each source/test change.
    counts=Counter(e["status"] for e in items)
    mapped=len(items)-counts["DISCOVERED"]
    with_tests=sum(bool(e.get("last_verification")) for e in items)
    current="| Active engine / operation | **UE1 / O06 — "+str(len(items))+(
        " source entries, "+str(mapped)+" classified, "+str(with_tests)+
        " with latest test outcome; statuses "+
        ", ".join(k+"="+str(counts[k]) for k in sorted(counts))+
        ". Six fixed gates remain OPEN; see live table below.** |")
    body=re.sub(r"(?m)^\| Active engine / operation \|[^\n]*$",lambda _:current,body,count=1)
    with_latest=[e for e in items if e.get("last_verification")]
    modified=max(with_latest,key=lambda e:e["last_verification"].get("at_utc","")) if with_latest else max(items,key=lambda e:e.get("updated_at_utc",""))
    v=modified.get("last_verification")
    activity=(modified["id"]+" -> "+modified["status"]+
              ("; latest "+v["target"]+" "+v["state"]+" at "+v["at_utc"] if v else "")+
              "; recorded "+modified.get("updated_at_utc",""))
    activity_line="| Latest serializer status event | "+activity.replace("|","/")+" |"
    if "| Latest serializer status event |" in body:
        body=re.sub(r"(?m)^\| Latest serializer status event \|[^\n]*$",
                    lambda _:activity_line,body,count=1)
    else:
        body=body.replace(current,current+"\n"+activity_line,1)
    today=datetime.datetime.now().astimezone().date().isoformat()
    body=re.sub(r"(?m)^Updated: \*\*\d{4}-\d{2}-\d{2}\*\*",
                "Updated: **"+today+"**",body,count=1)
    DOC.write_text(body,encoding="utf-8")
    print("REGISTER_ENTRIES="+str(len(items)))
    print("BRANCHES="+str(dict(Counter(x["branch"] for x in items))))
    print("STATES="+str(dict(Counter(x["status"] for x in items))))
def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--set-status",nargs=2,metavar=("ID","STATE"))
    for name in ("reader","test","evidence","remaining"):
        ap.add_argument("--"+name)
    ap.add_argument("--add-entry",nargs=4,metavar=("ID","BRANCH","SYMBOL","PATH:LINE"))
    ap.add_argument("--bulk-json",help="File containing an array of explicit mapping/status updates")
    ap.add_argument("--sync-journal",action="store_true",help="Refresh latest named DEV/PRD test outcome on each mapped entry")
    ap.add_argument("--record-test",nargs=2,metavar=("TEST","TARGET"),help="Update status immediately after a journalled named test")
    args=ap.parse_args()
    old={x["id"]:x for x in json.loads(DATA.read_text(encoding="utf-8-sig"))["entries"]} if DATA.exists() else {}
    found={} if args.sync_journal or args.record_test else find_symbols()
    new=0
    for key,entry in found.items():
        if key in old:old[key]["locations"]=entry["locations"]
        else:old[key]=entry;new+=1
    if args.add_entry:
        key,branch,symbol,loc=args.add_entry
        match=re.fullmatch(r"(.+):(\d+)",loc)
        if branch not in ROOTS or not match:ap.error("Invalid branch or path:line")
        if key not in old:
            old[key]={"id":key,"branch":branch,"symbol":symbol,"locations":[{"path":match[1],"line":int(match[2])}],
            "status":"DISCOVERED","reader":"","test":"","evidence":"",
            "remaining":"New discovery, not assessed","updated_at_utc":NOW};new+=1
    if args.set_status:
        key,state=args.set_status
        if key not in old:ap.error("Unknown source ID; add discovery first: "+key)
        if state not in STATES:ap.error("Invalid state: "+state)
        e=old[key]
        for field in ("reader","test","evidence","remaining"):
            val=getattr(args,field)
            if val is not None:e[field]=val
        if state in ("DEV_PASS","PRD_PASS","ACCEPTED") and (not e["test"] or not e["evidence"]):
            ap.error("PASS requires a named test and evidence")
        if state in ("PARTIAL","GAP","BLOCKED") and not e["remaining"]:
            ap.error("Unresolved state needs remaining-work details")
        e["status"]=state;e["updated_at_utc"]=NOW
    if args.bulk_json:
        updates=json.loads(Path(args.bulk_json).read_text(encoding="utf-8-sig"))
        if not isinstance(updates,list):ap.error("Bulk status input must be an array")
        for update in updates:
            key=update.get("id");state=update.get("status")
            if key not in old:ap.error("Unknown serializer ID: "+str(key))
            if state not in STATES:ap.error("Invalid state: "+str(state))
            e=old[key]
            for field in ("reader","test","evidence","remaining"):
                if field in update:e[field]=update[field]
            if state in ("DEV_PASS","PRD_PASS","ACCEPTED") and (not e["test"] or not e["evidence"]):
                ap.error("PASS requires named test and evidence: "+key)
            if state in ("PARTIAL","GAP","BLOCKED") and not e["remaining"]:
                ap.error("Missing reason for incomplete state: "+key)
            if e.get("test") and not (REPO/"catalog/bin"/e["test"]).exists():
                ap.error("Referenced test is missing: "+str(e["test"]))
            if e.get("test") and not e.get("evidence"):
                journal=REPO/"catalog/compliance/UE1-O06-TEST-STATUS.json"
                if journal.exists():
                    rows=json.loads(journal.read_text(encoding="utf-8-sig")).get("tests",[])
                    passed=[t for t in rows if t.get("test")==e["test"] and
                            t.get("state")=="PASS"]
                    passed.sort(key=lambda t:(t.get("target")=="PRD",t.get("event_at_utc","")),reverse=True)
                    if passed:
                        last=passed[0]
                        e["evidence"]=(last.get("target","DEV")+" historical PASS at "+
                                        last.get("event_at_utc","")+"; "+
                                        last.get("log","")+"; scoped test, not full class acceptance")
                    else:
                        e["evidence"]="Named source fixture exists; no journaled PASS confirmed"
            e["status"]=state;e["updated_at_utc"]=NOW
    if args.sync_journal or args.record_test:
        journal=REPO/"catalog/compliance/UE1-O06-TEST-STATUS.json"
        if not journal.exists():ap.error("O06 test journal status missing")
        tests=json.loads(journal.read_text(encoding="utf-8-sig")).get("tests",[])
        count=0
        for e in old.values():
            if not e.get("test"):continue
            if args.record_test and e["test"]!=args.record_test[0]:continue
            relevant=[x for x in tests if x.get("test")==e["test"] and
                       x.get("state") in ("PASS","FAIL","STARTED")]
            if args.record_test:
                relevant=[x for x in relevant if x.get("target")==args.record_test[1]]
            if not relevant:continue
            latest=max(relevant,key=lambda x:x.get("event_at_utc",""))
            e["last_verification"]={
                "target":latest.get("target","DEV"),
                "state":latest["state"],
                "at_utc":latest.get("event_at_utc",""),
                "test_sha256":latest.get("test_sha256",""),
                "source_sha256":latest.get("source_sha256",""),
                "log":latest.get("log",""),
            }
            e["updated_at_utc"]=NOW
            count+=1
        print("JOURNAL_TEST_ROWS_REFRESHED="+str(count))
    save(old)
    print("NEW_DISCOVERIES="+str(new))
if __name__=="__main__":main()
