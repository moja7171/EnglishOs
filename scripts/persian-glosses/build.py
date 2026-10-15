#!/usr/bin/env python3
"""Builds resources/data/persian-glosses.jsonl, the offline English -> Persian
dictionary behind the tap-a-word sheet, from the English Wiktionary dump.

A ONE-TIME, offline step (the dump is ~3 GB, so it is not run on the site):

    curl -L -o raw-wiktextract-data.jsonl.gz https://kaikki.org/dictionary/raw-wiktextract-data.jsonl.gz
    python3 scripts/persian-glosses/build.py raw-wiktextract-data.jsonl.gz resources/data/persian-glosses.jsonl

It streams the dump (flat memory, about a minute and a half), keeps the
Persian translations (lang codes fa and fa-ira; not Dari, Tajik, Middle or
Old Persian) of English single-word entries, and writes one line per
(word, part of speech): {"w": "sleep", "pos": "noun", "fa": ["خواب"]}.

Cleaning: vowel marks are removed (they make words hard to read and to
compare), translations tagged archaic/obsolete/dated/historical/poetic/rare are
dropped, parts of speech are renamed to the ones the sheet knows, and
translations with no Persian letters (a transliteration) are skipped.

Data: Wiktionary, via kaikki.org (Tatu Ylonen, Wiktextract), CC BY-SA.
"""
import gzip
import json
import re
import sys
import time

SRC, OUT = sys.argv[1], sys.argv[2]

MARK = '"lang_code": "fa'
KEEP_CODES = ('fa', 'fa-ira')
DROP_TAGS = {'archaic', 'obsolete', 'dated', 'historical', 'poetic', 'rare', 'dialectal', 'slang', 'vulgar', 'derogatory'}
POS = {
    'noun': 'noun', 'verb': 'verb', 'adj': 'adjective', 'adv': 'adverb', 'pron': 'pronoun',
    'prep': 'preposition', 'conj': 'conjunction', 'intj': 'interjection', 'det': 'determiner', 'num': 'numeral',
}
MAX_WORDS = 5
MARKS = re.compile('[ـً-ٰٟۖ-ۭ]')  # tatweel, harakat, Quranic marks (not the ZWNJ)
PERSIAN = re.compile('[؀-ۿ]')
HEADWORD = re.compile(r"^[A-Za-z][A-Za-z'-]*$")


def translations_of(entry):
    found = list(entry.get('translations') or [])
    for sense in entry.get('senses') or []:
        found += sense.get('translations') or []
    return found


def clean(word):
    word = MARKS.sub('', word).strip()
    return word if PERSIAN.search(word) and len(word) <= 40 else None


started = time.time()
lines = 0
glosses = {}  # (headword, pos) -> list of persian words, in source order

with gzip.open(SRC, 'rt', encoding='utf-8') as src:
    for line in src:
        lines += 1
        if lines % 2000000 == 0:
            print(f'{lines} lines, {len(glosses)} word/pos so far, {int(time.time() - started)}s', flush=True)
        if MARK not in line:
            continue
        try:
            entry = json.loads(line)
        except ValueError:
            continue
        pos = POS.get(entry.get('pos'))
        word = entry.get('word') or ''
        if entry.get('lang_code') != 'en' or pos is None or not HEADWORD.match(word):
            continue
        bucket = glosses.setdefault((word.lower(), pos), [])
        for t in translations_of(entry):
            if t.get('lang_code') not in KEEP_CODES or DROP_TAGS & set(t.get('tags') or []):
                continue
            persian = clean(t.get('word') or '')
            if persian and persian not in bucket:
                bucket.append(persian)

kept = 0
with open(OUT, 'w', encoding='utf-8') as out:
    for (word, pos), words in sorted(glosses.items()):
        if words:
            out.write(json.dumps({'w': word, 'pos': pos, 'fa': words[:MAX_WORDS]}, ensure_ascii=False) + '\n')
            kept += 1

print(f'done: {lines} lines read, {kept} word/pos pairs written in {int(time.time() - started)}s', flush=True)
