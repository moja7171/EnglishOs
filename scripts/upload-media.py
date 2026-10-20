"""Upload the missions' mp3/mp4 and picks mp3 to the download host
(dl.growwise.ir/englishos/mXX/...), resumable, skipping files that are already there.

Usage: FTP_PASS=... python3 scripts/upload-media.py [M07 M08 ...] [--dry]
No mission codes = every document/M* folder. The password is in
document/download-host.local.md (git-ignored).

A file with the same name but different content is NOT detected (same size is
skipped; a smaller file on the host is resumed, which corrupts it). Give changed
media a new name, or delete the old file on the host first.
"""
import ftplib
import os
import sys
from pathlib import Path

HOST = "3264450084.cloudydl.com"
USER = "pz25416"
DOCUMENT = Path(__file__).resolve().parent.parent / "document"
REMOTE_BASE = "englishos"

args = [a for a in sys.argv[1:] if not a.startswith("--")] or sorted(p.name for p in DOCUMENT.glob("M[0-9][0-9]"))
dry = "--dry" in sys.argv


def plan(code: str):
    folder = DOCUMENT / code
    for f in sorted(folder.iterdir()):
        if f.is_file() and f.suffix.lower() in (".mp3", ".mp4"):
            yield f, f"{REMOTE_BASE}/{code.lower()}/{f.name}"
    picks = folder / "picks"
    if picks.is_dir():
        for f in sorted(picks.glob("*.mp3")):
            yield f, f"{REMOTE_BASE}/{code.lower()}/picks/{f.name}"


files = [item for code in args for item in plan(code)]
total = sum(f.stat().st_size for f, _ in files)
print(f"{len(files)} files, {total / 1e6:.0f} MB")
if dry:
    for f, r in files:
        print(f"  {r}")
    sys.exit(0)

try:
    ftp = ftplib.FTP_TLS(HOST, timeout=60)
    ftp.login(USER, os.environ["FTP_PASS"])
    ftp.prot_c()
except ftplib.all_errors:
    ftp = ftplib.FTP(HOST, timeout=60)
    ftp.login(USER, os.environ["FTP_PASS"])

root = ""
for candidate in ("", "domains/pz25416.parspack.net/public_html"):
    try:
        ftp.cwd("/" + candidate)
        names = ftp.nlst()
        if "domains" not in names:
            root = candidate
            break
    except ftplib.all_errors:
        continue
print("remote root:", "/" + root, "->", ftp.pwd())


def ensure_dir(path: str):
    ftp.cwd("/" + root)
    for part in path.split("/"):
        try:
            ftp.cwd(part)
        except ftplib.error_perm:
            ftp.mkd(part)
            ftp.cwd(part)


for f, remote in files:
    directory, name = remote.rsplit("/", 1)
    ensure_dir(directory)
    size = f.stat().st_size
    try:
        ftp.voidcmd("TYPE I")
        remote_size = ftp.size(name) or 0
    except ftplib.all_errors:
        remote_size = 0
    if remote_size == size:
        print("skip ", remote)
        continue
    print("send ", remote, f"({size / 1e6:.1f} MB)", flush=True)
    with open(f, "rb") as fh:
        if 0 < remote_size < size:
            fh.seek(remote_size)
            ftp.storbinary(f"STOR {name}", fh, rest=remote_size)
        else:
            ftp.storbinary(f"STOR {name}", fh)

ftp.quit()
print("done")
