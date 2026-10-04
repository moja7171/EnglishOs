"""BBC transcript PDFs -> {slug}.transcript.json = [{speaker, text}, ...].

The PDFs print each speaker's name on a line of its own, then their words.
A name is any short, capitalised line that appears at least twice.
"""
import collections, glob, json, logging, pathlib, re, sys

logging.disable(logging.CRITICAL)
from pypdf import PdfReader

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = re.compile(r"^[A-Z][a-z]+(?: [A-Z][a-z]+){0,2}$")
NOISE = re.compile(r"^(BBC LEARNING ENGLISH|bbclearningenglish\.com.*|Page \d+ of \d+|This is not a word-for-word transcript\.?)$", re.I)


def clean(text):
    text = re.sub(r"(?<=[A-Za-z])\ufffd(?=[A-Za-z])", "'", text)
    text = re.sub(r"\ufffd+", "...", text)
    return re.sub(r"\s+", " ", text).strip()


def parse(pdf):
    reader = PdfReader(str(pdf))
    lines = []
    for page in reader.pages:
        lines += [l.strip() for l in (page.extract_text() or '').splitlines() if l.strip()]
    lines = [l for l in lines if not NOISE.match(l)]
    if 'VOCABULARY' in lines:
        lines = lines[:lines.index('VOCABULARY')]
    counts = collections.Counter(l for l in lines if NAME.match(l))
    names = {n for n, c in counts.items() if c >= 2}
    turns = []
    for line in lines:
        if line in names:
            turns.append({'speaker': line, 'text': ''})
        elif turns:
            turns[-1]['text'] += ' ' + line
    out = [{'speaker': t['speaker'], 'text': clean(t['text'])} for t in turns if clean(t['text'])]
    return out, names


def main():
    report = []
    for pdf in sorted(glob.glob(str(ROOT / 'document/M0*/picks/*.pdf'))):
        pdf = pathlib.Path(pdf)
        turns, names = parse(pdf)
        words = sum(len(t['text'].split()) for t in turns)
        pdf.with_suffix('.transcript.json').write_text(json.dumps(turns, ensure_ascii=False, indent=1), encoding='utf-8')
        report.append((pdf.parent.parent.name, pdf.stem, len(turns), sorted(names), words))
    for r in report:
        flag = '' if r[2] >= 8 and len(r[3]) >= 2 and r[4] >= 400 else '   <-- CHECK'
        print(*r, flag)


main()
