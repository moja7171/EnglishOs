"""BBC transcript PDFs -> {slug}.transcript.json = [{speaker, text}, ...].

The PDFs print each speaker's name on a line of its own, then their words.
A name is any short, capitalised line that appears at least twice.
Reads the PDFs with pypdf, or with poppler's pdftotext where pypdf isn't installed.
"""
import collections, glob, json, logging, pathlib, re, subprocess, sys

logging.disable(logging.CRITICAL)
try:
    from pypdf import PdfReader
except ImportError:
    PdfReader = None

ROOT = pathlib.Path(__file__).resolve().parents[2]
NAME = re.compile(r"^[A-Z][a-z]+(?: [A-Z][a-z]+){0,2}$")
# pdftotext also keeps each page's running header and copyright footer as lines of their own.
NOISE = re.compile(r"^(BBC LEARNING ENGLISH|bbclearningenglish\.com.*|Page \d+ of \d+|This is not a word-for-word transcript\.?|Real Easy English|6 Minute English|©\s*British Broadcasting Corporation \d{4})$", re.I)


def clean(text):
    text = re.sub(r"(?<=[A-Za-z])\ufffd(?=[A-Za-z])", "'", text)
    text = re.sub(r"\ufffd+", "...", text)
    return re.sub(r"\s+", " ", text).strip()


def pdf_lines(pdf):
    if PdfReader is None:
        text = subprocess.run(['pdftotext', '-enc', 'UTF-8', str(pdf), '-'], capture_output=True, text=True, check=True).stdout
        return [l.strip() for l in text.splitlines() if l.strip()]
    lines = []
    for page in PdfReader(str(pdf)).pages:
        lines += [l.strip() for l in (page.extract_text() or '').splitlines() if l.strip()]
    return lines


def parse(pdf):
    lines = [l for l in pdf_lines(pdf) if not NOISE.match(l)]
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
    for pdf in sorted(glob.glob(str(ROOT / 'document/M[0-9][0-9]/picks/*.pdf'))):
        pdf = pathlib.Path(pdf)
        turns, names = parse(pdf)
        words = sum(len(t['text'].split()) for t in turns)
        pdf.with_suffix('.transcript.json').write_text(json.dumps(turns, ensure_ascii=False, indent=1), encoding='utf-8')
        report.append((pdf.parent.parent.name, pdf.stem, len(turns), sorted(names), words))
    for r in report:
        flag = '' if r[2] >= 8 and len(r[3]) >= 2 and r[4] >= 400 else '   <-- CHECK'
        print(*r, flag)


main()
