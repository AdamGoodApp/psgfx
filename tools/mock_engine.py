#!/usr/bin/env python3
"""File-backed stand-in for the PS5 Upload app's local engine and the console behind it
(development only), following the engine's POST /api/mgmt/call and the payload's runtime.c,
register.c, mgmt_fs.c and shell builtins.
Usage: python3 tools/mock_engine.py ROOT PORT   (set MOCK_FW13=1 to mimic firmware 12/13 register behaviour,
MOCK_NOT_PAIRED=1 for a console that hasn't accepted PS5 Upload)
Paths map under ROOT. Writes are allowed under /data, /user and /mnt; reads also under /system*."""
import base64, json, os, shutil, sys
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

ROOT = sys.argv[1]; PORT = int(sys.argv[2])
WRITABLE = ("/data/", "/user/", "/mnt/")
READABLE = WRITABLE + ("/system/", "/system_data/", "/system_ex/")

def P(p): return os.path.join(ROOT, p.lstrip("/"))
def writable(p): return p.startswith(WRITABLE) and ".." not in p
def readable(p, unsafe): return (p.startswith(READABLE if unsafe else WRITABLE)) and ".." not in p

class Refused(Exception): pass

def call(method, label, j):
    if method == 51:  # app.list
        apps = []
        base = P("/user/app")
        for tid in sorted(os.listdir(base)) if os.path.isdir(base) else []:
            lnk = os.path.join(base, tid, "mount.lnk"); src = open(lnk).read() if os.path.exists(lnk) else ""
            name = tid
            pj = os.path.join(base, tid, "sce_sys", "param.json")
            if os.path.exists(pj):
                name = json.load(open(pj))["localizedParameters"]["en-US"]["titleName"]
            apps.append({"title_id": tid, "title_name": name, "src": src, "image_backed": False})
        return json.dumps({"apps": apps}).encode()
    if method == 56:  # app.db_query - names from app.db (mocked by ROOT/appdb.json)
        dbp = os.path.join(ROOT, "appdb.json")   # {"apps":[{"title_id":..,"app_id":..,"name":..}]}
        if os.environ.get("MOCK_NO_APPDB") or not os.path.exists(dbp): raise Refused("unsupported_in_mock")
        return open(dbp, "rb").read()
    if method == 38:  # fs.read (the engine loops to the limit, capped at 2 MiB like LEGACY_READ_MAX)
        p = j.get("path", "")
        if not readable(p, j.get("unsafe", False)): raise Refused("fs_read_path_not_allowed")
        if not os.path.isfile(P(p)): raise Refused("fs_read_stat_failed")
        with open(P(p), "rb") as f: f.seek(j.get("offset", 0)); return f.read(min(j.get("limit", 2 << 20), 2 << 20))
    if method == 33:  # fs.list, at most 256 a call
        p = j.get("path", "")
        if not os.path.isdir(P(p)): raise Refused("fs_list_dir_opendir_errno_2")
        names = sorted(os.listdir(P(p))); off = j.get("offset", 0); lim = min(j.get("limit", 256), 256)
        ents = [{"name": n, "kind": "dir" if os.path.isdir(os.path.join(P(p), n)) else "file",
                 "size": os.path.getsize(os.path.join(P(p), n)), "mtime": 0} for n in names[off:off + lim]]
        return json.dumps({"path": p, "entries": ents, "returned": len(ents), "truncated": off + lim < len(names)}).encode()
    if method == 35:  # fs.mkdir
        p = j.get("path", "")
        if not writable(p): raise Refused("path_unsafe")
        os.makedirs(P(p), exist_ok=True); return b""
    if method == 39:  # fs.write: whole file, replaced atomically; the folder must exist
        p = j.get("path", "")
        if not writable(p): raise Refused("path_unsafe")
        data = base64.b64decode(j["bytes"])
        if len(data) > (16 << 20 if j.get("large") else 256 << 10): raise Refused("too_large")
        if not os.path.isdir(os.path.dirname(P(p))): raise Refused("open_failed")
        if j.get("mode") == "create" and os.path.exists(P(p)): raise Refused("exists")
        open(P(p) + ".tmp", "wb").write(data); os.replace(P(p) + ".tmp", P(p))
        print("WROTE", p, len(data), flush=True)
        return json.dumps({"ok": True, "size": len(data)}).encode()
    if method == 87:  # shell.exec - only `cp SRC DST`, which (like the payload's) has no path policy
        a = j.get("cmd", "").split()
        if len(a) != 3 or a[0] != "cp": return json.dumps({"exit_code": 127, "timed_out": False, "stdout": "unsupported in mock\n"}).encode()
        if not os.path.isfile(P(a[1])): return json.dumps({"exit_code": 1, "timed_out": False, "stdout": "cp: %s: No such file or directory\n" % a[1]}).encode()
        with open(P(a[1]), "rb") as f, open(P(a[2]), "r+b" if os.path.exists(P(a[2])) else "wb") as g:
            g.truncate(0); g.write(f.read())
        print("CP", a[1], a[2], flush=True)
        return json.dumps({"exit_code": 0, "timed_out": False, "stdout": ""}).encode()
    if method == 48:  # app.register - stage sce_sys like register.c
        src = j.get("src_path", "").rstrip("/")
        pj = json.load(open(P(src + "/sce_sys/param.json"))); tid = pj["titleId"]
        ua = P("/user/app/" + tid); meta = P("/user/appmeta/" + tid)
        os.makedirs(ua, exist_ok=True); os.makedirs(meta, exist_ok=True)
        shutil.copytree(P(src + "/sce_sys"), ua + "/sce_sys", dirs_exist_ok=True)
        if os.path.exists(P(src + "/sce_sys/icon0.png")): shutil.copyfile(P(src + "/sce_sys/icon0.png"), ua + "/icon0.png")
        for n in os.listdir(P(src + "/sce_sys")):
            if n == "param.json" or n.lower().endswith((".png", ".dds", ".at9")):
                shutil.copyfile(P(src + "/sce_sys/" + n), os.path.join(meta, n))
        open(ua + "/mount.lnk", "w").write(src)
        print("REGISTER", tid, "from", src, flush=True)
        if os.environ.get("MOCK_FW13"): raise Refused("register_install_api_unavailable")
        return json.dumps({"title_id": tid, "title_name": tid, "used_nullfs": True}).encode()
    raise Refused("unsupported_in_mock")

def job(op, j):
    if op == 1:  # job.run DELETE
        p = j.get("path", "")
        if not writable(p): raise Refused("fs_delete_path_not_allowed")
        try: os.remove(P(p))
        except FileNotFoundError: raise Refused("fs_delete_failed")
        print("DELETED", p, flush=True)
        return json.dumps({"ok": True}).encode()
    raise Refused("unsupported_in_mock")

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_POST(self):
        if self.path != "/api/mgmt/call":
            self.send_response(404); self.end_headers(); return
        req = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        label = req.get("label", "")
        try:
            if os.environ.get("MOCK_NOT_PAIRED"): raise Refused("not_paired")
            body = base64.b64decode(req.get("body_b64", ""))
            j = json.loads(body) if body[:1] == b"{" else {}
            out = call(req["method"], label, j) if req["op"] == "call" else job(req["job_op"], j)
            r = {"ok": True, "result": {"kind": "bytes", "body_b64": base64.b64encode(out).decode()}}
        except Refused as e:
            r = {"ok": False, "error": {"kind": "mgmt", "label": label, "status": 7, "cause": str(e)}}
        b = json.dumps(r).encode()
        self.send_response(200); self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(b))); self.end_headers(); self.wfile.write(b)

print("mock engine up", flush=True)
ThreadingHTTPServer(("127.0.0.1", PORT), H).serve_forever()
