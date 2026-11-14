#!/usr/bin/env python3
"""Adds a Persian line ("fa") to every sentence of the synced texts.

The sentence groups are the ones the player draws (WebVttParser::sentences:
a sentence ends at a chunk whose text ends with . ? ! …, and a new speaker
always starts a new group). The Persian line is stored on the FIRST chunk of
each group; the player shows it under the group.

Files it reads and rewrites in place (same JSON layout as before):
  document/Mxx/shadow_timestamps.json   listening_turns, listening_segments, video_shadowing_segments
  document/Mxx/picks/*.turns.json       segments

Usage (key inline, never written to disk):
  GROQ_API_KEY=... python3 scripts/translate-fa.py [M03 M04 ...]

Safe to run again: sentences already in the cache file are not asked twice,
and a group that already has "fa" is left alone. Use --redo to translate
everything again.
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
CACHE = os.path.join(ROOT, "storage", "app", "translate-fa-cache.json")
# gpt-oss-120b: 200k tokens a day. qwen: small output per minute, so small batches.
MODELS = [os.environ.get("FA_MODEL", "openai/gpt-oss-120b")]
BATCH = int(os.environ.get("FA_BATCH", "25"))
CONTEXT = 3
END = re.compile(r'[.?!…]["”’)]*$')

PROMPT = """You translate spoken English from an English lesson for Persian learners (level B1) into Persian.
Rules:
- Natural, simple, everyday spoken Persian (محاوره‌ای روان و ساده، نه کتابی و رسمی). Keep the exact meaning; do not add or drop information.
- Write English names, places and brands in Persian script (مایک، آکسفورد).
- Keep the speaker's tone (questions stay questions). Keep it short like the English.
- Lines marked CONTEXT are only for understanding; do not translate them.
- Return ONLY a JSON array of strings, one per numbered line to translate, same order and same count. No other text."""


def key():
    value = os.environ.get("GROQ_API_KEY", "").strip()
    if not value:
        sys.exit("Set GROQ_API_KEY inline.")
    return value


def groups_of(segments):
    """Indexes of the chunks of each sentence group, in order."""
    groups, current, last_speaker = [], [], None
    for index, segment in enumerate(segments):
        speaker = segment.get("speaker")
        if current and speaker != last_speaker:
            groups.append(current)
            current = []
        current.append(index)
        last_speaker = speaker
        if END.search(segment["text"].strip()):
            groups.append(current)
            current = []
    if current:
        groups.append(current)
    return groups


def call(model, system, user):
    body = {
        "model": model,
        "messages": [{"role": "system", "content": system}, {"role": "user", "content": user}],
        "temperature": 0.2,
    }
    if "gpt-oss" in model:
        body["reasoning_effort"] = "low"
    else:
        body["max_tokens"] = 700
    request = urllib.request.Request(
        "https://api.groq.com/openai/v1/chat/completions",
        json.dumps(body).encode(),
        {"Authorization": "Bearer " + key(), "Content-Type": "application/json", "User-Agent": "curl/8"},
    )
    for attempt in range(8):
        try:
            # A hard stop: a connection the server closed can leave a read hanging past its timeout.
            signal.signal(signal.SIGALRM, lambda *_: (_ for _ in ()).throw(TimeoutError()))
            signal.alarm(75)
            with urllib.request.urlopen(request, timeout=60) as response:
                return json.load(response)["choices"][0]["message"]["content"]
        except urllib.error.HTTPError as error:
            print('HTTP', error.code, error.headers.get('retry-after'), flush=True)
            if error.code in (429, 500, 502, 503):
                wait = float(error.headers.get("retry-after") or 0) or 5 * (attempt + 1)
                time.sleep(min(wait + 1, 60))
                continue
            raise
        except (urllib.error.URLError, TimeoutError, OSError, http.client.HTTPException) as error:
            print('NET', type(error).__name__, error, flush=True)
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


def translate(batch, context):
    """batch: list of sentences, context: list of sentences before it."""
    lines = [f"CONTEXT: {c}" for c in context] + [f"{i + 1}. {s}" for i, s in enumerate(batch)]
    for model in MODELS:
        for _ in range(2):
            result = parse(call(model, PROMPT, "\n".join(lines)), len(batch))
            if result:
                return result
    if len(batch) > 1:
        half = len(batch) // 2
        return translate(batch[:half], context) + translate(batch[half:], context + batch[:half][-CONTEXT:])
    raise RuntimeError("Could not translate: " + batch[0])


def dump(path, data, original):
    """Write back in the file's own layout."""
    compact = "\n" not in original[:200]
    text = json.dumps(data, ensure_ascii=False, **({"separators": (",", ":")} if compact else {"indent": 4}))
    with open(path, "w", encoding="utf-8") as handle:
        handle.write(text + ("\n" if original.endswith("\n") else ""))


def main():
    redo = "--redo" in sys.argv
    missions = [a for a in sys.argv[1:] if re.fullmatch(r"M\d{2}", a)]
    cache = json.load(open(CACHE, encoding="utf-8")) if os.path.exists(CACHE) else {}

    files = []
    for folder in sorted(glob.glob(os.path.join(ROOT, "document", "M??"))):
        if missions and os.path.basename(folder) not in missions:
            continue
        files.append((os.path.join(folder, "shadow_timestamps.json"), ["listening_turns", "listening_segments", "video_shadowing_segments"]))
        files += [(p, ["segments"]) for p in sorted(glob.glob(os.path.join(folder, "picks", "*.turns.json")))]

    for path, keys in files:
        if not os.path.exists(path):
            continue
        original = open(path, encoding="utf-8").read()
        data = json.loads(original)
        todo = []
        for name in keys:
            segments = data.get(name) or []
            for group in groups_of(segments):
                if redo or "fa" not in segments[group[0]]:
                    todo.append((segments, group))

        sentences = [" ".join(s[i]["text"].strip() for i in g) for s, g in todo]
        missing = [s for s in dict.fromkeys(sentences) if s not in cache]
        for start in range(0, len(missing), BATCH):
            chunk = missing[start:start + BATCH]
            context = missing[max(0, start - CONTEXT):start]
            for sentence, fa in zip(chunk, translate(chunk, context)):
                cache[sentence] = fa
            json.dump(cache, open(CACHE, "w", encoding="utf-8"), ensure_ascii=False)

        for (segments, group), sentence in zip(todo, sentences):
            segments[group[0]]["fa"] = cache[sentence]
        if todo:
            dump(path, data, original)
        print(os.path.relpath(path, ROOT), "translated", len(missing), "of", len(todo), flush=True)


main()
