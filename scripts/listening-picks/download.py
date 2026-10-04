"""Step 2 of 5 - download every audio file and transcript PDF listed in the manifest (see discover.py)."""
import json, os, pathlib, sys, time, urllib.request
from concurrent.futures import ThreadPoolExecutor

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCR = ROOT / 'storage' / 'app' / 'listening-picks'  # scratch: fetched pages, manifest
SCR.mkdir(parents=True, exist_ok=True)
PROXY = os.environ.get('LISTENING_PROXY')  # e.g. http://127.0.0.1:10808 when bbc.co.uk / voanews.com need a VPN
opener = urllib.request.build_opener(urllib.request.ProxyHandler({'http': PROXY, 'https': PROXY} if PROXY else {}))
opener.addheaders = [('User-Agent', 'Mozilla/5.0')]


def grab(url, dest, minimum):
    if dest.exists() and dest.stat().st_size >= minimum:
        return 'have'
    dest.parent.mkdir(parents=True, exist_ok=True)
    last = None
    for _ in range(4):
        try:
            with opener.open(url, timeout=90) as r, open(dest.with_suffix(dest.suffix + '.part'), 'wb') as f:
                while chunk := r.read(1 << 16):
                    f.write(chunk)
            part = dest.with_suffix(dest.suffix + '.part')
            if part.stat().st_size < minimum:
                raise RuntimeError(f'too small: {part.stat().st_size}')
            part.replace(dest)
            return 'ok'
        except Exception as e:
            last = e
            time.sleep(2)
    return f'FAILED {last}'


def main():
    manifest = json.load(open(SCR / 'manifest.json'))
    jobs = []
    for code, rows in manifest.items():
        for r in rows:
            base = ROOT / 'document' / code / 'picks' / r['slug']
            jobs.append((r['mp3'], base.with_suffix('.mp3'), 300_000, code, r['slug']))
            if r.get('pdf'):
                jobs.append((r['pdf'], base.with_suffix('.pdf'), 5_000, code, r['slug']))
    with ThreadPoolExecutor(4) as ex:
        results = list(ex.map(lambda j: (j, grab(j[0], j[1], j[2])), jobs))
    failed = [(j[3], j[4], res) for j, res in results if res.startswith('FAILED')]
    print(len(jobs), 'files;', sum(1 for _, r in results if r == 'ok'), 'new;', sum(1 for _, r in results if r == 'have'), 'already there;', len(failed), 'failed')
    for f in failed:
        print(f)


main()
