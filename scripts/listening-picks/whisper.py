"""Whisper (Groq) segments for every downloaded pick -> document/{code}/picks/{slug}.segments.json.

Cached per file, so it can be stopped and resumed. Honours Groq's rate limit:
a 429 sleeps for the retry-after Groq asks for, then tries again.
"""
import json, os, pathlib, re, sys, time
import requests

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCR = ROOT / 'storage' / 'app' / 'listening-picks'  # scratch: fetched pages, manifest
SCR.mkdir(parents=True, exist_ok=True)
PROXY = os.environ.get('LISTENING_PROXY')
PROXIES = {'http': PROXY, 'https': PROXY} if PROXY else None
env = dict(re.findall(r'^([A-Z_]+)=(.*)$', (ROOT / '.env').read_text(encoding='utf-8'), re.M))
KEY = env['GROQ_API_KEY'].strip('"\' ')
MODEL = env.get('GROQ_WHISPER_MODEL', 'whisper-large-v3-turbo').strip('"\' ')
URL = 'https://api.groq.com/openai/v1/audio/transcriptions'


def transcribe(path):
    for attempt in range(20):
        try:
            with open(path, 'rb') as f:
                r = requests.post(
                    URL, proxies=PROXIES, timeout=300,
                    headers={'Authorization': f'Bearer {KEY}'},
                    data={'model': MODEL, 'response_format': 'verbose_json', 'timestamp_granularities[]': 'segment', 'language': 'en', 'temperature': '0'},
                    files={'file': (path.name, f, 'audio/mpeg')},
                )
        except requests.RequestException as e:
            print('  network error, retrying in 15s:', type(e).__name__, flush=True)
            time.sleep(15)
            continue
        rl = {k: v for k, v in r.headers.items() if k.lower().startswith(('x-ratelimit', 'retry-after'))}
        if r.status_code == 200:
            return r.json(), rl
        if r.status_code == 429:
            wait = float(r.headers.get('retry-after', 60)) + 2
            print(f'  429 rate limited; sleeping {wait:.0f}s', rl, flush=True)
            time.sleep(wait)
            continue
        print('  error', r.status_code, r.text[:200], flush=True)
        time.sleep(5)
    raise RuntimeError('gave up')


def main(only=None, limit=None):
    manifest = json.load(open(SCR / 'manifest.json'))
    done = 0
    for code, rows in manifest.items():
        for row in rows:
            out = ROOT / 'document' / code / 'picks' / f"{row['slug']}.segments.json"
            mp3 = out.with_name(row['slug'] + '.mp3')
            if out.exists() or (only and row['slug'] not in only):
                continue
            if limit is not None and done >= limit:
                return
            data, rl = transcribe(mp3)
            segs = [
                {'text': s['text'].strip(), 'start': round(s['start'], 2), 'end': round(s['end'], 2)}
                for s in data['segments'] if s['text'].strip()
            ]
            out.write_text(json.dumps({'duration': data.get('duration'), 'segments': segs}, ensure_ascii=False), encoding='utf-8')
            done += 1
            print(code, row['slug'], len(segs), 'segments', rl.get('x-ratelimit-remaining-audio-seconds', ''), flush=True)


main(limit=int(sys.argv[1]) if len(sys.argv) > 1 else None)
