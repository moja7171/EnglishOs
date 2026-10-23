#!/usr/bin/env python3
"""Builds the offline data behind the tap-a-word sheet from the English
Wiktionary (via kaikki.org):

- resources/data/persian-glosses.jsonl, the English -> Persian dictionary,
  one line per (word or phrase, part of speech):
  {"w": "sleep", "pos": "noun", "fa": ["خواب"]}
- resources/data/phrase-forms.jsonl, the English phrasal verbs and fixed
  phrases of 2-4 words with the ways each one is written:
  {"p": "give up", "f": ["gives up", "giving up", "gave up", "given up"]}

A ONE-TIME, offline step (not run on the site). From the full dump (~3 GB):

    curl -L -o raw-wiktextract-data.jsonl.gz https://kaikki.org/dictionary/raw-wiktextract-data.jsonl.gz
    python3 scripts/persian-glosses/build.py resources/data/persian-glosses.jsonl resources/data/phrase-forms.jsonl raw-wiktextract-data.jsonl.gz

Or, adding the phrases to the dictionary already built, from the much
smaller English files per part of speech (what was done on 2026-10-23, the
full dump being too slow to download from here) — --add keeps every line
the glosses file already has and only adds (word, pos) pairs it lacks:

    for pos in verb phrase prep_phrase adv prep conj intj; do
        curl -L -o en-$pos.jsonl.gz https://kaikki.org/dictionary/English/pos-$pos/kaikki.org-dictionary-English-by-pos-$pos.jsonl.gz
    done
    python3 scripts/persian-glosses/build.py --add resources/data/persian-glosses.jsonl resources/data/phrase-forms.jsonl en-*.jsonl.gz

It streams the sources (flat memory), keeps the Persian translations (lang
codes fa and fa-ira; not Dari, Tajik, Middle or Old Persian) of English
entries of one word or a phrase of up to four words.

Cleaning: vowel marks are removed (they make words hard to read and to
compare), translations tagged archaic/obsolete/dated/historical/poetic/rare are
dropped, parts of speech are renamed to the ones the sheet knows, and
translations with no Persian letters (a transliteration) are skipped. A
phrase only goes in phrase-forms.jsonl when it is in everyday use: at least
one of its senses is not archaic, rare, vulgar and so on, it is translated
into at least MIN_LANGUAGES languages (the thousands of rare phrasal verbs
are not), it is not just a form of another phrase ("marched past"), and it
has no placeholder a text never contains ("mark one's words"). MISLEADING
lists the phrases (and forms) that, checked against every mission text on
2026-10-23, mostly matched ordinary grammar instead of the phrase — "going
to" is the future, not "go to"; "do in" means kill, but the texts say "what
do you do in your free time".

Data: Wiktionary, via kaikki.org (Tatu Ylonen, Wiktextract), CC BY-SA.
"""
import gzip
import json
import re
import sys
import time

ADD = '--add' in sys.argv
ARGS = [arg for arg in sys.argv[1:] if arg != '--add']
OUT, OUT_PHRASES, SOURCES = ARGS[0], ARGS[1], ARGS[2:]

KEEP_CODES = ('fa', 'fa-ira')
DROP_TAGS = {'archaic', 'obsolete', 'dated', 'historical', 'poetic', 'rare', 'dialectal', 'slang', 'vulgar', 'derogatory'}
PHRASE_DROP_TAGS = DROP_TAGS | {'offensive', 'humorous', 'nonstandard', 'misspelling', 'proscribed', 'euphemistic'}
POS = {
    'noun': 'noun', 'verb': 'verb', 'adj': 'adjective', 'adv': 'adverb', 'pron': 'pronoun',
    'prep': 'preposition', 'conj': 'conjunction', 'intj': 'interjection', 'det': 'determiner', 'num': 'numeral',
    'phrase': 'phrase', 'prep_phrase': 'phrase',
}
PHRASE_POS = {'verb', 'phrase', 'prep_phrase', 'adv', 'prep', 'conj', 'intj'}
MAX_WORDS = 5
MAX_LENGTH = 40
MARKS = re.compile('[ـً-ٰٟۖ-ۭ]')  # tatweel, harakat, Quranic marks (not the ZWNJ)
PERSIAN = re.compile('[؀-ۿ]')
HEADWORD = re.compile(r"^[A-Za-z][A-Za-z'-]*(?: [A-Za-z][A-Za-z'-]*){0,3}$")
PHRASE = re.compile(r"^[a-z][a-z'-]*(?: [a-z][a-z'-]*){1,3}$")
PHRASE_HINT = re.compile(r'"word": "[a-z][a-z\'-]* [a-z]')
PLACEHOLDER = re.compile(r"\b(?:one's|oneself|someone|someone's|something|somebody|somebody's|somewhere|sb|sth)\b")
MIN_LANGUAGES = 3
MISLEADING = {'do in', 'do for', 'do it', 'there be', 'that is', 'and how', 'be like'}
MISLEADING_FORMS = {'going to'}
FORM_TAGS = {'present', 'third-person', 'singular', 'participle', 'past'}


def translations_of(entry):
    found = list(entry.get('translations') or [])
    for sense in entry.get('senses') or []:
        found += sense.get('translations') or []
    return found


def clean(word):
    word = MARKS.sub('', word).strip()
    return word if PERSIAN.search(word) and len(word) <= 40 else None


def everyday(entry):
    """Whether at least one sense of the entry is everyday English."""
    return any(not (PHRASE_DROP_TAGS & set(sense.get('tags') or [])) for sense in entry.get('senses') or [])


def form_only(entry):
    """Whether the entry is only a form of another one ("marched past")."""
    senses = entry.get('senses') or []
    return bool(senses) and all('form_of' in sense or 'form-of' in (sense.get('tags') or []) for sense in senses)


def forms_of(entry, phrase):
    """The inflected forms of a phrasal verb ("gave up"), as many words long as the phrase."""
    found = []
    for form in entry.get('forms') or []:
        text = (form.get('form') or '').strip()
        tags = set(form.get('tags') or [])
        if text != phrase and PHRASE.match(text) and text.count(' ') == phrase.count(' ') \
                and tags and tags <= FORM_TAGS and text not in found:
            found.append(text)
    return found


def open_source(path):
    return gzip.open(path, 'rt', encoding='utf-8') if path.endswith('.gz') else open(path, encoding='utf-8')


started = time.time()
lines = 0
glosses = {}  # (headword, pos) -> list of persian words, in source order
phrases = {}  # phrase -> its inflected forms
languages = {}  # phrase -> how many languages it is translated into (the most of any of its entries)

for source in SOURCES:
    with open_source(source) as src:
        for line in src:
            lines += 1
            if lines % 2000000 == 0:
                print(f'{lines} lines, {len(glosses)} word/pos so far, {int(time.time() - started)}s', flush=True)
            # A cheap test before parsing: only English entries matter, and
            # only ones with Persian or a space in the headword.
            if '"lang_code": "fa' not in line and not PHRASE_HINT.search(line):
                continue
            try:
                entry = json.loads(line)
            except ValueError:
                continue
            if entry.get('lang_code') != 'en':
                continue
            word = entry.get('word') or ''

            if entry.get('pos') in PHRASE_POS and PHRASE.match(word) and not PLACEHOLDER.search(word) \
                    and everyday(entry) and not form_only(entry):
                languages[word] = max(languages.get(word, 0), len({t.get('lang_code') for t in translations_of(entry)}))
                bucket = phrases.setdefault(word, [])
                for form in forms_of(entry, word):
                    if form not in bucket and form not in MISLEADING_FORMS:
                        bucket.append(form)

            pos = POS.get(entry.get('pos'))
            if pos is None or not HEADWORD.match(word) or len(word) > MAX_LENGTH or PLACEHOLDER.search(word):
                continue
            bucket = glosses.setdefault((word.lower(), pos), [])
            for t in translations_of(entry):
                if t.get('lang_code') not in KEEP_CODES or DROP_TAGS & set(t.get('tags') or []):
                    continue
                persian = clean(t.get('word') or '')
                if persian and persian not in bucket:
                    bucket.append(persian)

kept = {}
if ADD:
    with open(OUT, encoding='utf-8') as existing:
        for line in existing:
            item = json.loads(line)
            kept[(item['w'], item['pos'])] = item['fa']

added = 0
for key, words in glosses.items():
    if words and key not in kept:
        kept[key] = words[:MAX_WORDS]
        added += 1

with open(OUT, 'w', encoding='utf-8') as out:
    for (word, pos), words in sorted(kept.items()):
        out.write(json.dumps({'w': word, 'pos': pos, 'fa': words}, ensure_ascii=False) + '\n')

phrases = {phrase: forms for phrase, forms in phrases.items() if languages[phrase] >= MIN_LANGUAGES and phrase not in MISLEADING}

with open(OUT_PHRASES, 'w', encoding='utf-8') as out:
    for phrase, forms in sorted(phrases.items()):
        out.write(json.dumps({'p': phrase, 'f': forms} if forms else {'p': phrase}, ensure_ascii=False) + '\n')

print(f'done: {lines} lines read, {added} word/pos pairs added ({len(kept)} in all), '
      f'{len(phrases)} phrases written in {int(time.time() - started)}s', flush=True)
