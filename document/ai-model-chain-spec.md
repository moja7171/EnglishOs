# Spec: ordered model chains for Gemini and Groq (free-tier aware)

Audience: a coding agent starting a fresh session in this repo (English OS, Laravel). Everything here was learned in a long debugging session on 2026-10-07, so you do not have to rediscover it. **Nothing secret is in this document**; ask the user for keys and tokens when you need them.

Read first, in this order: this file, `document/ai-relay-vps.fa.md` (the relay VPS; Persian, but the commands and facts are plain), `CLAUDE.md` / `AGENTS.md` (project rules).

## 1. Goal

The app calls Gemini (all text) and Groq (Whisper speech-to-text) on **free tiers**. Free quotas are small and **counted per model**, so a single "primary + one fallback" setup runs dry. The user wants:

- A **ranked list of free models** per task, best first. The app uses the best one until its quota is exhausted, then moves to the next. The same for Groq.
- A way to **see** the state of this (which model is live, which is exhausted until when), because today failures are invisible.
- No change in what learners see, except fewer outages.

Non-goals (decided with the user): do not build a billing/cost system; do not add a second LLM provider for text *yet* (see decision 2: this depends on one open question); and **do not build key/project/account rotation**. Gemini quota is counted **per Google Cloud project and per model**, not per key: extra keys inside one project add nothing, and creating extra projects or Google accounts to multiply the free quota is what Google's terms discourage (and an account or key suspension would take the live site down). The supported way to get more free capacity is more *models* in one project, which is exactly this chain. The app stays on **one project and one key**.

Context for priorities: the user is in Iran. Enabling paid billing on Google Cloud from Iran is normally not possible, so "upgrade to a paid tier" may not be an option for them; ask (section 10). Also note that on the free tier Google may use prompt content to improve its products; learners' free-text answers go to Gemini, so mention this to the user once. The user is told the real fix for high volume is a paid Gemini tier; this chain is insurance, not a substitute.

## 2. Current state (verified by reading the code)

### Choke points
- **All Gemini text calls go through one method:** `App\Services\GeminiClient::chat(array $messages, ?string $systemPrompt = null, ?int $maxOutputTokens = null): string` (`app/Services/GeminiClient.php`). It tries `services.gemini.model`, then once `services.gemini.fallback_model`; one attempt per model (`->retry(1, 500, throw: false)`), `timeout(20)`; logs the real cause at error level only when every model failed (`logFailure()`). Callers get text back and throw on failure.
- **All Groq transcription goes through** `App\Services\GroqClient` (`transcribe()`, `transcribeWithDuration()`, `transcribeWithConfidence()` → private `request()` / `attempt()`), same primary → fallback shape with `services.groq.whisper_model` / `fallback_model`. It also logs on total failure (added today).
- Both use `App\Services\Concerns\UsesOutboundProxy`: every call goes through the relay (`https://relay.growwise.ir`), see section 6.
- `config/services.php` holds the model names (`gemini.model`, `gemini.fallback_model`, `groq.whisper_model`, `groq.fallback_model`).
- `App\Http\Controllers\AiDiagnosticController` (`/_diag/ai?token=…`, gated by `DEPLOY_TOKEN`) constructs `new GeminiClient(null, $model, '')` to test each configured model separately. Keep that working or update it.

### Gemini call sites (all via `chat()`)
Sage (`resources/views/components/missions/⚡ask-instructor.blade.php`, the only caller using `maxOutputTokens: 220`), `SentenceChecker`, `SpokenAnswerChecker`, `AiFeedbackCard`, `ai-conversation1/2`, `error-log`, `mission-result`, `friends/⚡conversation`, and the `ai:check` command. A natural split (verify by reading each call before relying on it): **chat-like** = Sage, conversation follow-ups, friends conversation (short, high volume, cheap model is fine); **judge-like** = SentenceChecker, SpokenAnswerChecker, AiFeedbackCard, error-log, mission-result (quality matters, lower volume).

### Groq call sites
Sage voice question, Speaking steps (`ai-conversation1/2`, `story-sequence`, `picture-description`, `partner-speaking-session`, `partner-session`, friends conversation), `DailyListenStep`, `PlacementTest`, `ai:check`.

### Phase-1 change already made (uncommitted when this was written; check `git status`)
`gemini.fallback_model` default changed from `gemini-flash-latest` to **`gemini-3.1-flash-lite`** in `config/services.php`, `GeminiClient`, and `.env.example`. Production's `.env` still has `GEMINI_FALLBACK_MODEL=gemini-3.6-flash` and **overrides the default**; the user must change it (or your new config must replace that variable).

## 3. Evidence gathered on 2026-10-07

### The key is on the **free tier**
A real 429 from Google for `gemini-3.6-flash`:

```
HTTP 429  status: RESOURCE_EXHAUSTED
message: "You exceeded your current quota ... Quota exceeded for metric:
  generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 20,
  model: gemini-3.6-flash. Please retry in 11h8m5s."
details:
  QuotaFailure.violations[0].quotaMetric = generativelanguage.googleapis.com/generate_content_free_tier_requests
  QuotaFailure.violations[0].quotaId     = GenerateRequestsPerDayPerProjectPerModel-FreeTier
  QuotaFailure.violations[0].quotaDimensions = {location: global, model: gemini-3.6-flash}
  RetryInfo.retryDelay = "40085s"
  Help.links = [https://ai.google.dev/gemini-api/docs/rate-limits]
```

So: **20 requests/day per project per model** for `gemini-3.6-flash` on this key; quota is per model; the daily one resets in hours (`retryDelay` says when). The error text points to `https://ai.dev/rate-limit` for the live per-model limits table (the user can open it; you cannot). A per-minute variant of the error has not been observed; **assume** its `quotaId` contains `PerMinute` and `retryDelay` is seconds, and verify when you can (do not rely on this).

The application's own log truncates this message (`(truncated...)`), which is why the cause was hard to see. Parse the structured `details`, do not string-match the message.

### Gemini models visible to the key (generateContent-capable, text-relevant)
`gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`, `gemini-3-flash-preview`, `gemini-flash-latest`, `gemini-flash-lite-latest`, `gemini-pro-latest`, `gemini-3.1-pro-preview`, `gemma-4-31b-it`, `gemma-4-26b-a4b-it`, `gemini-2.5-flash`, `gemini-2.5-pro` (plus image/TTS/audio/research/robotics models you should ignore; `gemini-3.5-transcribe` is relevant to section 7). `gemini-2.5-flash-lite` returns **404 "no longer available to new users"**: models get retired, so a chain needs a "permanently gone" state.

### What was actually tested with a Sage-like request
(system prompt + "What is the difference between I have been working and I worked?", `maxOutputTokens: 220`)

| Model | Result |
|---|---|
| `gemini-3.5-flash-lite` | STOP, 38 answer tokens, 0 thinking, 0.7 s, good |
| `gemini-3.1-flash-lite` | STOP, 47 tokens, 0 thinking, 1.0 s, good (adds a "Hi!") |
| `gemini-flash-lite-latest` | works; a moving alias that may resolve to a model that shares the primary's quota |
| `gemini-3.7-flash` | **MAX_TOKENS**: 209 of 220 tokens spent on thinking, truncated reply, 35 s |
| `gemini-3.6-flash` | quota exhausted (429); the previous fallback, and a thinking model, so also a poor choice for Sage |
| `gemini-2.5-flash-lite` | 404, retired |

Lesson: **"thinking" flash models eat `maxOutputTokens`.** Sage caps at 220, so such a model answers with a cut-off sentence. Each chain entry needs its own options (for example a thinking budget of 0 if the API allows it for that model, or a bigger token cap), or it must be excluded from capped calls. Verify the exact request field against Google's current API docs before using it.

### Groq models visible to the key
Text: `openai/gpt-oss-120b`, `openai/gpt-oss-20b`, `openai/gpt-oss-safeguard-20b`, `qwen/qwen3.8-27b`. Speech-to-text: **only** `whisper-large-v3-turbo` and `whisper-large-v3`. (Plus Arabic/English TTS, guard models, `allam-2-7b`; ignore.) The user decided **not** to use Groq text models as a Gemini fallback for now.

## 4. Decisions already made with the user

1. **Two Gemini chains, not one**: a *chat* chain (cheap/lite models first; Sage and conversation) and a *judge* chain (better model first, then lite). More than two is unwanted complexity.
2. **No cross-provider text fallback for now, but revisit it early.** If the user *cannot* pay for Gemini (likely), Groq text models already visible to the key (`openai/gpt-oss-120b`, `openai/gpt-oss-20b`, `qwen/qwen3.8-27b`) become the only independent source of capacity when every Gemini model is exhausted; then add them as a last-resort tail of the chat chain behind a small adapter (Groq's API is OpenAI-shaped; keep Sage's persona prompt and the 220-token cap), as its own phase after the chain works. If the user *can* pay, skip it.
3. **Transcription**: keep the two Groq Whisper models as the chain; consider `gemini-3.5-transcribe` as a **third, independent** fallback only after testing it on real browser recordings (webm) and checking its quota. Optional, last phase.
4. **A status page for admins** ships together with the chain, not later.
5. **Quality ranking comes from a small real comparison**, not guesses; the user approves the final order (language-teaching quality is their call).
6. The list lives in **configuration** (env, comma-separated), not hard-coded, so it can change without a deploy.

## 5. Design to implement

### 5.1 Chain walk (inside `GeminiClient::chat()`, so call sites barely change)
- `chat()` gets a **profile** (for example an enum or string: `chat` default, `judge`); the existing signature stays valid and defaults to the chat profile. Call sites that are judge-like pass `judge`. Keep `maxOutputTokens` behavior.
- Walk the profile's ordered model list. For each model: skip it if marked unavailable; otherwise make **one** attempt (keep the current timeouts and `retry(1, 500, throw: false)` semantics); on success return; on failure classify and mark, then continue.
- **Depth cap**: at most about 3 real attempts per request, so a learner never waits long (each failed attempt can cost up to the 20 s timeout; a 429 is instant).
- When every candidate fails, throw the last error as today and `Log::error` **once** with each model, status, classified reason (never keys, never request bodies).

### 5.2 Classifying a failure (parse `error.details`, not the message)
| Signal | Meaning | Action |
|---|---|---|
| 429 with `QuotaFailure.quotaId` containing `PerDay` | daily quota gone | mark unavailable until `now + RetryInfo.retryDelay` (or next reset); log the switch |
| 429 with a per-minute `quotaId` (assumed) or small `retryDelay` | rate limit | mark unavailable for about that many seconds |
| 404 / "no longer available" | model retired | mark unavailable for a long time (for example a day) and surface it on the status page as **stale config** |
| 5xx, timeouts, connection errors | transient | mark unavailable for a few minutes |
| 400 (bad request) | our bug, not the model | **do not** walk the chain; throw |

### 5.3 "Unavailable until" memory
Store per model in the cache: `ai-chain:{provider}:{model}` → `{until, reason, since}`. Production uses `CACHE_STORE=database` (shared across requests); tests use `array`. Skipping a known-exhausted model avoids paying a failed request on every call. Self-heals when `until` passes. Also keep simple per-model counters (today's successes/failures) for the status page; the existing `MissionRun.gemini_calls` / `groq_calls` columns only count successes per run and have no UI.

### 5.3b Per-model options
A chain entry is a model id plus options (for example output-token cap multiplier or thinking budget). Keep this small and explicit; a thinking model without handling for capped calls must not appear in the chat chain.

### 5.4 Configuration
Ordered env lists, for example `GEMINI_CHAT_MODELS=gemini-3.5-flash-lite,gemini-3.1-flash-lite` and `GEMINI_JUDGE_MODELS=…`, read in `config/services.php` into arrays (config is cached in production: **never call `env()` outside config**). Keep backward compatibility: if the new variables are absent, fall back to the existing `GEMINI_MODEL` + `GEMINI_FALLBACK_MODEL`. The same for Groq (`GROQ_WHISPER_MODELS`). Update `.env.example` and the diagnostics.

### 5.5 Observability
- **Admin status page** (the profile page already has an admin-only "AI Relay" tab, `resources/views/components/⚡profile.blade.php`; follow that pattern): per model: profile(s), last success, last failure and reason, unavailable until, today's counts; for Groq the same. Read-only.
- **Diagnostics** (`/_diag/ai`): a section that lists each configured model, whether it still exists (call the models list endpoint through the relay), and its current chain state. Retired models must show up here.
- **Alert** when the last model of a chain is reached or all are exhausted, via the existing push-alert service-health mechanism (`ServiceHealthController`, `/_diag/health`); look at how it works before adding to it.
- Log each switch once (not every request) at **error** level: production runs at `LOG_LEVEL=error`, which silently drops warnings (that hid problems today).

### 5.6 Groq chain
Same structure inside `GroqClient::request()`: ordered `whisper-large-v3-turbo`, `whisper-large-v3`. Groq's limits are probably expressed per time period (audio seconds and/or requests); classify its 429s from its own error shape (inspect a real one; do not assume Google's). `GroqClient::transcribe*` public API stays unchanged.

## 6. Environment facts you need

- **Every provider call goes through the relay.** Relay URL `https://relay.growwise.ir` (Cloudflare → Caddy → `ai-relay.py` on a VPS in Germany); a backup path through a localhost.run tunnel exists and the app switches between the two automatically (`ai:relay-failover`, `ai:relay-sync-local-url`). The relay caps a provider request at **30 s**, so per-attempt timeouts above that are meaningless. See `document/ai-relay-vps.fa.md`.
- The relay once corrupted compressed responses for clients advertising brotli (fixed 2026-10-07). If a provider response looks like garbage or `text` is empty while `curl` works, test with `Accept-Encoding: br`.
- Production is **shared cPanel hosting in Iran, no SSH**, PHP 8.4, `proc_open()` disabled (scheduled tasks run in-process as closures in `routes/console.php`), `CACHE_STORE=database`, config usually cached (`.env` edits are ignored until `config:clear`; the token-gated `/_diag/ai-relay-use` route clears it), `LOG_LEVEL=error`.
- The Iranian host's network path has been the real cause of several "bugs". **Always verify on the production host** (via `/_diag/ai?token=…`), not only from a laptop.
- VPS access for ad-hoc provider tests: `ssh -i ~/.ssh/englishos_vps root@82.115.21.133` (key-only). Run provider calls from there with keys passed through stdin or a config file, never on a visible command line in shared logs. **Ask the user for the Gemini/Groq keys and the diag token.**
- Deploy flow: commit on `main`; `scripts/deploy-push.sh` (with `DEPLOY_WORKTREE=/home/dev/englishos-deploy-worktree`) pushes the `deploy` branch; the user then clicks "Update from Remote" in cPanel. The tool-permission system may refuse to let you run the deploy script: if it does, tell the user the command instead of working around it. `deploy.php` runs migrations/seeders on deploy and is security-sensitive; do not edit it without the user's explicit permission.

## 7. Phases (stop and show the user after each)

1. **Housekeeping (small).** Review and commit the Phase-1 fallback change if still uncommitted. Tell the user to set `GEMINI_FALLBACK_MODEL=gemini-3.1-flash-lite` in production `.env` (or make the new config supersede it) and clear config cache.
2. **Quality comparison.** About 8–10 realistic prompts: a few real Sage-style questions (A2–B1 learners, 2–3 sentence replies, 220-token cap) and one or two judge-style tasks copied from the real prompts in `SentenceChecker`/`SpokenAnswerChecker`/`mission-result`, run against 4–5 candidates from the lists above (skip models with a known tiny daily quota, and stay well inside each model's allowance: this is free-tier quota the live site needs). Record: answer text, finish reason, thinking tokens, latency, whether JSON/format instructions were followed. Present side by side; **the user decides the order** (consider asking the `esl-pedagogy-specialist` agent for a review of teaching quality, if available).
3. **The chain.** Section 5 in full for Gemini (both profiles), the Groq chain, config, diagnostics, status page, alert, tests. Include the migration of call sites to the right profile.
4. **Optional.** `gemini-3.5-transcribe` as a third transcription fallback after testing it with a real browser recording (the app records `audio/webm` via `MediaRecorder`; the server sniffs it as `video/webm`) and checking its quota.

## 8. Acceptance criteria
- Chains are configured by env lists; defaults reproduce today's behavior; no `env()` outside config.
- A daily-quota 429 on the first model makes later requests skip it with **no extra failed request**, and it is retried after `retryDelay`; a per-minute-style 429 and transient errors have their own short timeouts; a 400 does not walk the chain.
- A retired model (404) is detected and visible on the status page and in diagnostics.
- Depth cap respected; worst-case latency stays bounded and is stated.
- Sage keeps working with its 220-token cap (no truncated replies from thinking models).
- The status page shows per-model state; an alert fires when a chain is down to its last model.
- Tests (PHPUnit, `Http::fake`, `Cache` array store) cover: success on first model, daily exhaustion → next model → memory skip → expiry, per-minute rate limit, 404 retired, 5xx transient, 400 not walking, all models failing (one error log, no secrets), depth cap, config fallback to the old variables, and the Groq equivalents. Existing `GeminiClientTest`, `GroqClientTest`, `AiDiagnosticTest`, `AskInstructorTest` stay green.
- Verified on the production host through `/_diag/ai` after the user deploys.

## 9. Working notes and pitfalls
- Follow `CLAUDE.md`: look at sibling files for conventions, create files with `php artisan make:…`, no new dependencies, run `vendor/bin/pint --dirty --format agent`, add/run narrow PHPUnit tests, read the `testing-best-practices` and `laravel-best-practices` skills. The Laravel Boost MCP server may be down; use the shell.
- Running tests locally: this laptop had no `vendor/` (run `composer install`; it does not change `composer.json`), no MySQL driver, and no `.env`. Tests that need a database work with `cp .env.example .env && php artisan key:generate`, then `DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --compact <file>`. Delete the temporary `.env` afterwards.
- `Illuminate\Console\Command` already has a public `fail()` method; do not name a private helper `fail`.
- Shell `sleep` is blocked in this environment; use background commands or Monitor for waits.
- Commit only when the user asks; end commit messages with the attribution line from the environment. The user may have set `user.name` / `user.email` for this repo locally; if `git commit` complains about identity, ask rather than inventing one.
- Do not print or store secrets (Gemini/Groq keys, `RELAY_SECRET`, `DEPLOY_TOKEN`, `ADMIN_PASSWORD`). The user pasted a production `.env` into an earlier session, so those values exist in that conversation; do not copy them into files.
- The user communicates in **Persian**; reply to them in Persian and keep code, comments, commit messages and docs in English.

## 10. Questions to ask the user early
1. Confirm the two profiles and which call sites are chat-like vs judge-like (propose your reading of the code).
2. Has `GEMINI_FALLBACK_MODEL` in production `.env` been changed? **Can they pay for Gemini at all** (billing from Iran is usually blocked), or will they stay on the free tier? If they cannot pay, propose the Groq text tail (decision 2) and let them decide. Do not offer or build multi-key / multi-project / multi-account rotation.
3. Do they want the quality comparison to include a language other than English prompts (the app teaches English to Persian speakers)?
4. May you run the deploy script, or will they?
