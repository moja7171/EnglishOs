"""Step 1 of 5 - find each listening pick's audio / transcript PDF.

Reads resources/data/listening-picks.json for the given missions (default M01-M04), opens every
pick's page and writes storage/app/listening-picks/manifest.json plus document/{code}/picks/index.json
(which card is which file). Then, in order:

  python scripts/listening-picks/download.py           # step 2: audio + transcript PDFs
  python scripts/listening-picks/whisper.py            # step 3: timed text (GROQ_API_KEY in .env)
  python scripts/listening-picks/parse_transcripts.py  # step 4: PDFs -> speaker turns
  php artisan listening:build-picks                    # step 5: align speakers, write {slug}.turns.json

Set LISTENING_PROXY=http://host:port if the BBC / VOA sites are blocked from this machine. The
results stay in document/{code}/picks/, which is not in git - copy that folder to the server.
Needs: pip install requests pypdf
"""
import json, os, re, sys, time, urllib.request, urllib.parse, pathlib

ROOT = pathlib.Path(__file__).resolve().parents[2]
SCR = ROOT / 'storage' / 'app' / 'listening-picks'  # scratch: fetched pages, manifest
SCR.mkdir(parents=True, exist_ok=True)
PAGES = SCR / 'pages'
PAGES.mkdir(exist_ok=True)
PROXY = os.environ.get('LISTENING_PROXY')  # e.g. http://127.0.0.1:10808 when bbc.co.uk / voanews.com need a VPN
opener = urllib.request.build_opener(urllib.request.ProxyHandler({'http': PROXY, 'https': PROXY} if PROXY else {}))
opener.addheaders = [('User-Agent', 'Mozilla/5.0')]


def fetch(url, tries=3):
    for i in range(tries):
        try:
            with opener.open(url, timeout=40) as r:
                return r.read().decode('utf-8', 'ignore')
        except Exception as e:
            err = e
            time.sleep(1.5)
    raise err


def slug(url):
    return re.sub(r'[^a-z0-9]+', '-', urllib.parse.unquote(url.rstrip('/').split('/')[-1]).lower()).strip('-')[:70]


def resolve_apple(p):
    """Apple Podcasts episode pages name the BBC episode page they mirror."""
    t = fetch(p['url'])
    m = re.search(r'https://www\.bbc\.co\.uk/learningenglish/(?:english/)?features/6-minute-english_\d+/ep-\d+', t)
    if m:
        return m.group(0).replace('/learningenglish/features/', '/learningenglish/english/features/')
    # Older episodes: the BBC page address is simply the publication date.
    date = re.search(r'"datePublished":"(\d{4})-(\d{2})-(\d{2})"', t)
    if not date:
        raise RuntimeError('no BBC link or date on the Apple page')
    year, month, day = date.groups()
    return f'https://www.bbc.co.uk/learningenglish/english/features/6-minute-english_{year}/ep-{year[2:]}{month}{day}'


def discover(p):
    if p.get('apple'):
        p = {**p, 'url': resolve_apple(p)}
    html = fetch(p['url'])
    out = {'mp3': None, 'pdf': None, 'mp4': None, 'page': p['url']}
    if p['src'] in ('lee', 'sme'):
        mp3s = re.findall(r'(https://downloads\.bbc\.co\.uk/[^"\s<>]+?\.mp3)', html)
        pdfs = re.findall(r'(https://downloads\.bbc\.co\.uk/[^"\s<>]+?\.pdf)', html)
        out['mp3'] = next(iter(mp3s), None)
        out['pdf'] = next((x for x in pdfs if 'worksheet' not in x.lower()), None)
    else:
        mp3s = re.findall(r'(https://voa-audio\.voanews\.eu/[^"\s<>&;\\]+?\.mp3)(?!\?)', html)
        hq = [x for x in mp3s if x.endswith('_hq.mp3')]
        out['mp3'] = (hq or mp3s or [None])[0]
        mp4s = re.findall(r'(https://[^"\s<>&;\\]+?\.mp4)', html)
        out['mp4'] = next(iter(mp4s), None)
    return html, out


def main(missions):
    picks = json.load(open(ROOT / 'resources/data/listening-picks.json', encoding='utf-8'))
    manifest = {}
    for code in missions:
        manifest[code] = []
        for d, day in enumerate(picks[code], 1):
            for p in day:
                html, found = '', {}
                try:
                    html, found = discover(p)
                except Exception as e:
                    found = {'error': str(e), 'page': p['url']}
                page = found.get('page', p['url'])
                s = f"{p['src']}-{slug(page)}"
                if html:
                    (PAGES / f'{code}-{s}.html').write_text(html, encoding='utf-8')
                row = {'day': d, 'src': p['src'], 'title': p['title'], 'url': page, 'slug': s, **found}
                manifest[code].append(row)
                ok = found.get('mp3') or found.get('mp4')
                print(code, d, p['src'], 'OK ' if ok else 'MISSING', s, '| pdf' if found.get('pdf') else '')
    (SCR / 'manifest.json').write_text(json.dumps(manifest, indent=1), encoding='utf-8')
    for code, rows in manifest.items():
        days = [[{'slug': r['slug'], 'title': r['title'], 'src': r['src'], 'sourceUrl': r['url']} for r in rows if r['day'] == d] for d in range(1, 5)]
        folder = ROOT / 'document' / code / 'picks'
        folder.mkdir(parents=True, exist_ok=True)
        (folder / 'index.json').write_text(json.dumps(days, ensure_ascii=False, indent=1), encoding='utf-8')


main(sys.argv[1:] or ['M01', 'M02', 'M03', 'M04'])
