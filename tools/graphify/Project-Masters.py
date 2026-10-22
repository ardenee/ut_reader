"""Local Graphify master-project configuration, shared by dashboard and server."""
import json
import re
from pathlib import Path

ROOT=Path.home()/"Graphify"
CONFIG=ROOT/"masters.json"
DEFAULT={"schema":1,"masters":[{"id":"unrealdb","name":"UnrealDB & Unreal Engine","description":"UnrealDB and the versioned Epic source graphs"},{"id":"other","name":"Other Projects","description":"Projects not related to UnrealDB"}]}

def read_masters():
    if not CONFIG.exists():
        CONFIG.write_text(json.dumps(DEFAULT,indent=2),encoding="utf-8")
    obj=json.loads(CONFIG.read_text(encoding="utf-8-sig"))
    if not isinstance(obj.get("masters"),list):raise ValueError("Malformed masters.json")
    return obj["masters"]

def add_master(name):
    if not isinstance(name,str) or not 1<=len(name.strip())<=80:
        raise ValueError("Master name must contain 1-80 characters")
    name=name.strip()
    slug=re.sub(r"[^a-z0-9-]+","-",name.lower()).strip("-")
    if not slug or len(slug)>64:raise ValueError("Invalid master ID")
    masters=read_masters()
    if any(x["id"]==slug for x in masters):
        raise ValueError("Master project already exists")
    masters.append({"id":slug,"name":name,"description":"Local project collection"})
    CONFIG.write_text(json.dumps({"schema":1,"masters":masters},indent=2,ensure_ascii=False),encoding="utf-8")
    return slug

def validate_master(master):
    if not isinstance(master,str) or master not in {m["id"] for m in read_masters()}:
        raise ValueError("Select a valid master project")
    return master
