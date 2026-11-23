#!/usr/bin/env python3
"""Adds Persian to the Vocab in Use course: a "fa" line on every sentence and a "phrase_fa" on every bold
word or phrase of document/vocab/units/unit_NNN.json (the files database/seeders/VocabSeeder.php reads).

Same method as scripts/translate-fa.py (Groq, key inline, never written to disk):
  GROQ_API_KEY=... python3 scripts/translate-vocab-fa.py [5 16 35 ...]

Safe to run again: whatever is already in the cache file (storage/app/translate-vocab-fa-cache.json) is not
asked twice, and a sentence/word that already has its Persian is left alone (--redo asks again).
Limits are the ones in the translate-fa memory: gpt-oss-120b gives about 200k tokens a day; after that run
with FA_MODEL=qwen/qwen3.8-27b FA_BATCH=10, or rerun the next day. 429 waits are honoured.
"""
import glob
import http.client
import json
import os
import re
import signal
import sys
import time
import urllib.error
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
CACHE = os.path.join(ROOT, "storage", "app", "translate-vocab-fa-cache.json")
MODELS = [os.environ.get("FA_MODEL", "openai/gpt-oss-120b")]
BATCH = int(os.environ.get("FA_BATCH", "25"))

SENTENCES = """You translate sentences of an English vocabulary book (Cambridge Vocabulary in Use) into Persian for learners (level B1).
Rules:
- Natural, simple, everyday Persian (محاوره‌ای روان و ساده، نه کتابی). Keep the exact meaning; do not add or drop information.
- A line that looks like "word – meaning – meaning" is a vocabulary row: translate it as a short row, keeping the " – " between the parts.
- Write English names, places and brands in Persian script.
- Return ONLY a JSON array of strings, one per numbered line, same order and same count. No other text."""

PHRASES = """For an English vocabulary course for Persian learners (level B1). Each numbered line is: WORD OR PHRASE | the book's English meaning | the sentence it is used in.
Give the Persian meaning of the word or phrase AS USED in that sentence: short (one to four words), natural, the form a learner would memorise (for a verb, the infinitive form like «بیدار شدن»; for a noun, the noun).
Return ONLY a JSON array of strings, one per numbered line, same order and same count. No other text."""


def key():
    value = os.environ.get("GROQ_API_KEY", "").strip()
    if not value:
        sys.exit("Set GROQ_API_KEY inline.")
    return value


def call(model, system, user):
    body = {
        "model": model,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
        "temperature": 0.2,
    }
    if "gpt-oss" in model:
        body["reasoning_effort"] = "low"
    else:
        body["max_tokens"] = 900
    request = urllib.request.Request(
        "https://api.groq.com/openai/v1/chat/completions",
        json.dumps(body).encode(),
        {"Authorization": "Bearer " + key(), "Content-Type": "application/json", "User-Agent": "curl/8"},
    )
    for attempt in range(8):
        try:
            signal.signal(signal.SIGALRM, lambda *_: (_ for _ in ()).throw(TimeoutError()))
            signal.alarm(75)
            with urllib.request.urlopen(request, timeout=60) as response:
                return json.load(response)["choices"][0]["message"]["content"]
        except urllib.error.HTTPError as error:
            print("HTTP", error.code, error.headers.get("retry-after"), flush=True)
            if error.code in (429, 500, 502, 503):
                wait = float(error.headers.get("retry-after") or 0) or 5 * (attempt + 1)
                time.sleep(min(wait + 1, 60))
                continue
            raise
        except (urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException) as error:
            print("NET", type(error).__name__, error, flush=True)
            time.sleep(5 * (attempt + 1))
        finally:
            signal.alarm(0)
    raise RuntimeError("Groq kept failing")


def parse(text, count):
    text = re.sub(r"<think>.*?</think>", "", text, flags=re.S)
    match = re.search(r"\[.*\]", text, flags=re.S)
    if not match:
        return None
    try:
        data = json.loads(match.group(0))
    except json.JSONDecodeError:
        return None
    if not isinstance(data, list) or len(data) != count or not all(isinstance(x, str) and x.strip() for x in data):
        return None
    return [x.strip() for x in data]


def translate(system, lines):
    numbered = "\n".join(f"{i + 1}. {line}" for i, line in enumerate(lines))
    for model in MODELS:
        for _ in range(2):
            result = parse(call(model, system, numbered), len(lines))
            if result:
                return result
    if len(lines) > 1:
        half = len(lines) // 2
        return translate(system, lines[:half]) + translate(system, lines[half:])
    raise RuntimeError("Could not translate: " + lines[0])


def fill(cache, system, wanted):
    """wanted: cache keys (the lines asked). Translates the ones not cached yet, in batches."""
    missing = [w for w in dict.fromkeys(wanted) if w not in cache]
    for start in range(0, len(missing), BATCH):
        chunk = missing[start:start + BATCH]
        for line, fa in zip(chunk, translate(system, chunk)):
            cache[line] = fa
        json.dump(cache, open(CACHE, "w", encoding="utf-8"), ensure_ascii=False)
    return len(missing)


def phrase_line(item, section):
    sentence = section["sentences"][item["sentence_index"]]["text"] if item["sentence_index"] < len(section["sentences"]) else ""
    return f"{item['phrase']} | {item.get('gloss') or '-'} | {sentence}"


def main():
    redo = "--redo" in sys.argv
    units = [int(a) for a in sys.argv[1:] if a.isdigit()]
    cache = json.load(open(CACHE, encoding="utf-8")) if os.path.exists(CACHE) else {}

    for path in sorted(glob.glob(os.path.join(ROOT, "document", "vocab", "units", "unit_*.json"))):
        number = int(re.search(r"unit_(\d+)", path).group(1))
        if units and number not in units:
            continue
        data = json.load(open(path, encoding="utf-8"))
        sentences = [s["text"] for sec in data["sections"] for s in sec["sentences"] if redo or "fa" not in s]
        phrases = [phrase_line(i, sec) for sec in data["sections"] for i in sec["items"] if redo or not i.get("phrase_fa")]
        asked = fill(cache, SENTENCES, sentences) + fill(cache, "PHRASES:" and PHRASES, phrases)
        for sec in data["sections"]:
            for s in sec["sentences"]:
                if s["text"] in cache and (redo or "fa" not in s):
                    s["fa"] = cache[s["text"]]
            for i in sec["items"]:
                line = phrase_line(i, sec)
                if line in cache and (redo or not i.get("phrase_fa")):
                    i["phrase_fa"] = cache[line]
        json.dump(data, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=1)
        print(os.path.relpath(path, ROOT), "asked", asked, flush=True)


main()
