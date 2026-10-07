# Task: SkillOS on the shared AI relay, with an ordered Gemini model chain

Audience: an AI coding agent working in the **SkillOS** repo (`/home/dev/Desktop/SkillOs`, Laravel 13, PHP 8.3+, GitHub `moja7171/SkillOs`, production `https://skillos.growwise.ir`).

The user speaks Persian: talk to them in Persian; keep code, comments, commit messages and documents in English.

This document is self-contained. It was written after the same work was finished and verified in the sister project **English OS** (`/home/dev/Desktop/EnglishOs`), whose code you can read and port. **Nothing secret is in this document**; ask the user for secrets (see "Inputs").

There are two parts, done in this order:

- **Part A — wiring.** Point SkillOS at the relay that actually runs today, and retire the leftover copy of an obsolete relay. Mostly configuration.
- **Part B — the model chain.** Replace SkillOS's single-model, no-retry Gemini call with an ordered list of free-tier models that fail over, remember which model is exhausted, never keep a learner waiting, and make failures visible.

Read all of it before editing, and read the SkillOS rules in `CLAUDE.md`, `AGENTS.md` and `docs/AUTHORING.md` / `docs/DECISIONS.md` first (SkillOS asks for a `docs/DECISIONS.md` entry per important change, ticked `docs/STORIES.md`, and one section per commit).

---

## 0. Ground rules

- Commit only when the user asks. End commit messages with the attribution line your environment specifies.
- **Never put secrets in files, tests, docs, commits, logs or chat**: the relay secret, Gemini keys, `OPS_TOKEN`, `DEPLOY_TOKEN`, admin passwords. Ask the user when you need one, use it for the one call that needs it, never store it.
- Do not change dependencies (`composer.json`) or add new base directories without approval. Put new classes under `app/Services/Ai/` next to `GeminiClient`.
- Work **phase by phase** (section "Phases") and stop for the user's approval after each one. Prefer small, reviewable steps and real PHPUnit tests (`Http::fake`) over ad-hoc scripts. Run the narrowest relevant tests, then `vendor/bin/pint --dirty --format agent`.
- Free-tier quota on the live key is **shared with the live site**. Keep any test or comparison small and say how many provider requests you used.
- Verify on the **production host**, not only from a laptop: the host's network path has caused several "bugs" that a laptop never shows.
- You may be refused permission to run the deploy script or edit anything security-sensitive. If a permission is denied, tell the user what you wanted to do and the exact command; do not work around it.
- Shell `sleep` may be blocked; use background commands or a monitor for waits.

## 1. What exists in SkillOS today (verified by reading the repo)

- `app/Services/Ai/Concerns/UsesOutboundProxy.php` — adds `X-Relay-Url` / `X-Relay-Auth` and swaps the URL when `services.ai_proxy.url` is set. **Correct as is. Do not rewrite it.**
- `config/services.php` — `'gemini' => ['api_key' => env('GEMINI_API_KEY'), 'model' => env('GEMINI_MODEL', 'gemini-2.5-flash')]` and `'ai_proxy' => ['url' => env('AI_PROXY_URL'), 'secret' => env('AI_PROXY_SECRET')]` (one slot only; keep that shape). Note the config default (`gemini-2.5-flash`) differs from `.env.example` (`gemini-3.6-flash`); ask what production really has.
- `app/Services/Ai/GeminiClient.php` — the **only** AI call, `generateJson(string $prompt, array $schema): array`, structured output (`responseMimeType: application/json` + `responseSchema`), `Http::timeout(60)`, **no retry, no fallback model, no logging of the real failure**, and on failure it throws `RuntimeException('Gemini request failed: '.$response->body())` (so the raw provider body ends up in exception messages).
- Callers of `generateJson()`:
  - `Services/Evaluation/Evaluator.php` — grades a learner's practice answer against a rubric (verdict `correct|partial|incorrect` + Persian feedback ≤ 80 words). **This is the judge-like, quality-critical call**, runs while the learner waits. Reached from `AttemptSession::submit()` ← `SessionController::submit()`.
  - `Services/Content/ReviewPracticeGenerator.php` — generates one extra practice for a review. Called from `Planner` inside `try { … } catch (Throwable $e) { report($e); }`, so a failure already degrades gracefully. Not time-critical.
  - the diagnostic `OpsController::aiCheck()` (`/_ops/ai-check?token=…`, gated by `OPS_TOKEN`): shows the active proxy URL and does a real round trip, printing `Gemini relay test SUCCESS`.
- `SessionController::submit()` catches **`RuntimeException` only**, reports it, and redirects back with the Persian message «الان نتونستم پاسخت رو ارزیابی کنم…» and the input preserved. **See Pitfall P1: this contract must survive Part B.**
- `tools/ai-relay.py`, `tools/vps-relay-setup.sh`, `tools/vps-relay-README.fa.md` — a copy of the relay and the instructions for a **separate, SkillOS-only relay VPS** (`/etc/skillos-relay.env`). That design is superseded (Part A, task A4).
- Gemini only. No Groq, no Pexels, no other provider. No web-push/notification infrastructure (no `app/Notifications`, no notifications table). Admin = `users.is_admin`.
- Tests: PHPUnit, SQLite `:memory:`, `CACHE_STORE=array` (see `phpunit.xml`). Existing tests mock `GeminiClient::generateJson` in `SessionTest`, `PlannerTest`, `MasteryServiceTest`; `tests/Feature/UsesOutboundProxyTest.php` covers the proxy trait.
- Production: shared cPanel hosting in Iran, no SSH, `proc_open()` disabled, `CACHE_STORE=database` (shared across requests, so cache-based state works), config usually cached (`.env` edits are ignored until the cache is cleared with `/_ops/optimize?token=…`).

## 2. The relay that actually runs today (shared with English OS)

- URL: `https://relay.growwise.ir` (Cloudflare in front, a VPS in Germany behind). Always this domain: never a raw IP, never an `lhr.life` URL, never `relay2.growwise.ir` (an old VPS, unused, `64.226.95.102`).
- Protocol: send the request to the relay URL with headers `X-Relay-Auth: <secret>` and `X-Relay-Url: <real https URL>`; method, other headers (including `x-goog-api-key`) and body are forwarded as-is; the provider's real response comes back untouched. Wrong or missing secret: `401 bad auth`. Destination not allowed: `403`.
- Allowed destinations include `generativelanguage.googleapis.com`, which is all SkillOS needs.
- **The relay waits at most 30 s for the provider**; slower answers come back as `502 relay fetch failed`. A client timeout above 30 s is meaningless.
- The relay passes compressed responses through untouched (fixed 2026-10-07). If a provider response looks like garbage or `text` is empty while `curl` works, retest with `Accept-Encoding: br`.
- One secret is shared by English OS and SkillOS: the same value as English OS's `AI_PROXY_SECRET_VPS`.
- Full relay documentation (Persian, commands are plain): `/home/dev/Desktop/EnglishOs/document/ai-relay-vps.fa.md`.

## 3. Inputs you need (ask the user, one at a time, do not guess)

1. **The relay secret.** Goes only into SkillOS's production `.env`.
2. **What SkillOS's production `.env` currently has** for `AI_PROXY_URL`, `GEMINI_MODEL` and whether `GEMINI_FALLBACK_MODEL` exists. If `AI_PROXY_URL` points at `relay2.growwise.ir`, a `*.sslip.io` address, an `lhr.life` tunnel, or a raw IP, that is the old setup and must be replaced.
3. **The Gemini key for SkillOS.** A separate key (and Google project) from English OS is better: quotas are separate and the key travels through the relay. Decision already made for English OS and valid here: **one Google project and one API key per app. Do not create extra projects/keys or rotate keys to multiply quota** (against Google's terms). More models in the chain is how you get more free capacity.
4. **`OPS_TOKEN`** (only for the one call that needs it; if unset, the ops route is disabled — do not weaken the gate).
5. **Whether the user will run the deploy** (SkillOS has `tools/deploy-push.sh`, `tools/build-release.sh`, `deploy/`; read the README "Deploying to shared hosting"). Default: the user deploys, you tell them the command.

---

# Part A — wiring

### A1. Point production at the shared relay (configuration only)

In SkillOS's production `.env`:

```
AI_PROXY_URL=https://relay.growwise.ir
AI_PROXY_SECRET=<ask the user>
```

After editing `.env`, clear the config cache with `/_ops/optimize?token=…`. Then run `/_ops/ai-check`: it must print `Gemini relay test SUCCESS`.

### A2. Verify with a realistic request, on the host

A one-line "ping" proves little: on a previous host node, POST bodies above about 1 KB through Cloudflare silently timed out while tiny ones passed. Evaluator and ReviewPracticeGenerator prompts embed lesson content and learner answers, so measure how big a real prompt is. Run SkillOS's real evaluation (or a diagnostic with a 4–8 KB prompt) **on the host** and report the size verified. Confirm a deliberately wrong secret gives a handled error, then restore the right one.

### A3. Fix the `.env.example` relay comment

It currently mentions `tools/ai-relay.py` and "a tunnel to wherever it's running". Replace with: the shared relay URL, that the secret is the shared `RELAY_SECRET`, and that blank means direct calls.

### A4. Retire the obsolete relay copy

`tools/vps-relay-setup.sh`, `tools/vps-relay-README.fa.md` and `tools/ai-relay.py` describe a dedicated SkillOS relay VPS that is not used. Do not deploy the old copy anywhere: it has two bugs the shared one fixed (an unread request body breaks the next request on the same keep-alive connection, and a client that advertises brotli, like PHP's cURL, gets its response silently corrupted). Delete these three files or replace them with a short note pointing to the shared relay; ask the user which. `docs/DECISIONS.md` §61 describes the old design — add a new entry (next number) saying it is superseded, rather than rewriting history.

---

# Part B — the model chain

## B1. Why

Free-tier Gemini quotas are small and **counted per model**, so "one model, no retry" breaks when that model is exhausted, overloaded or retired. Evidence from English OS on 2026-10-07:

- A real Google 429 for `gemini-3.6-flash` on a free-tier key:

```
HTTP 429  status: RESOURCE_EXHAUSTED
message: "You exceeded your current quota ... Quota exceeded for metric:
  generativelanguage.googleapis.com/generate_content_free_tier_requests, limit: 20,
  model: gemini-3.6-flash. Please retry in 11h8m5s."
details:
  QuotaFailure.violations[0].quotaId = GenerateRequestsPerDayPerProjectPerModel-FreeTier
  QuotaFailure.violations[0].quotaDimensions = {location: global, model: gemini-3.6-flash}
  RetryInfo.retryDelay = "40085s"
```

  So: **20 requests/day per project per model** on the free tier, the quota is per model, and `retryDelay` says when it resets. Parse the structured `details`; the human message is truncated in logs. A per-minute variant (`quotaId` containing `PerMinute`) is *assumed*, not observed.
- Models are retired: `gemini-2.5-flash-lite` returns **404 "no longer available to new users"**. A chain needs a "permanently gone" state. (SkillOS's config default `gemini-2.5-flash` may be next; check it.)
- Google's lite and flash models were **flaky that day**: `503 "This model is currently experiencing high demand"`, and calls that hung until the client timeout, for the same key within minutes of working calls. Failed 503/timeouts may also count against quota (a model hit 429 after many 503s with zero successes): do not hammer a failing model.
- A model's behavior differs per request shape. Always test the exact production prompt and schema on every model you put in the chain.

Goal: an ordered list of free models; use the first until its quota is exhausted (or it fails), then the next; remember which model is down and until when so later requests skip it **without paying a failed request**; a learner is never left waiting; failures stop being invisible. Non-goals: a second LLM provider as fallback, extra Google projects/keys, a billing system. The real fix for high volume is a paid Gemini tier; the chain is insurance, not a substitute.

## B2. Reference implementation (English OS, committed and verified in production)

Read these before writing code; port rather than reinvent. Commits in `/home/dev/Desktop/EnglishOs`: `09bf4e3` (chain core), `0263293` (judge routing, never block), `4fa2b54` (status and alerts). The Groq commit `8196fd7` is not relevant to SkillOS.

- `app/Services/AiModelChain.php` — **the part to port almost as is** (provider-agnostic): `classify()` (failure table below), `markUnavailable()` / `unavailableState()` (cache memory), `recordSuccess()` / `recordFailure()` / `todayStats()` (per-day counters), `status()` (rows for a status view), `parseChain()` (`"model"` or `"model:thinkingLevel"` entries), and `walk()` (the chain walk: skip marked models, one attempt per model, depth cap, total time budget, one error log per exhausted request, one error log per model leaving the rotation). Two things in it are English OS-specific and must be adapted or dropped for SkillOS: the `alertIfDegraded()` method (uses `App\Models\User` admins and `App\Notifications\AiChainDegraded`, which SkillOS does not have — see B6), and the Groq-oriented comments.
- `app/Services/GeminiClient.php` — how a client uses the chain (`configuredChain()` with legacy fallback, pinned chains for diagnostics, `modelExists()`).
- `config/services.php` (the `gemini` block) and `.env.example` — config names.
- `tests/Feature/GeminiClientTest.php` (18 tests), `tests/Feature/AiChainAlertTest.php` — the test matrix to port.
- Background: `document/ai-model-chain-spec.md` in the English OS repo (longer, English OS-specific).

### Failure classification (parse `error.details`, not the message)

| Signal | Meaning | Action |
|---|---|---|
| 429, `QuotaFailure.quotaId` contains `PerDay` (or `retryDelay` ≥ 3600 s) | daily quota gone | skip until `now + retryDelay` (clamped 60 s … 24 h; default 6 h if absent) |
| 429 otherwise | rate limit | skip about `retryDelay` / `Retry-After` / "retry in 4m5s" in the message (clamped 5 s … 1 h; default 60 s) |
| 404 | model retired | skip for a day; surfaces as **stale config** |
| 5xx, 408, timeouts, connection errors | transient | skip about 3 minutes |
| 400, 401, 403, anything else | our bug / config, not the model | **do not walk the chain; throw** |

Consequence of the last row: a chain entry that is *deterministically* rejected with 400 (for example an unsupported thinking level — see B5) breaks every request that reaches it. Test every entry after changing the lists.

## B3. Design for SkillOS

Keep `GeminiClient::generateJson(string $prompt, array $schema): array` **with the same signature and return shape**; callers (`Evaluator`, `ReviewPracticeGenerator`, `OpsController::aiCheck`, and all the `generateJson` mocks in tests) must not change except where B3.4 says.

1. **Chain walk inside `generateJson()`**, using a ported `AiModelChain::walk()`. Per model: skip it if marked unavailable; otherwise make **one** attempt (no in-attempt retry: the next model is the retry), classify and mark on failure, continue. If every candidate is marked, probe the one that heals soonest (so a stale mark never lengthens an outage).
2. **Two profiles, because SkillOS has two kinds of call.** `judge` for `Evaluator` (quality first, learner is waiting) and `generate` for `ReviewPracticeGenerator` (not time-critical, may use a cheaper model so it does not drain the judge's quota). Add an optional `string $profile = 'judge'` parameter to `generateJson()` (default keeps old callers valid) and pass `generate` from `ReviewPracticeGenerator`. **Quota is per model, so keep the first entries of the two lists different**: generation traffic must not eat the grading allowance.
3. **Never keep the learner waiting.** Defaults used in English OS and verified: attempt timeout **10 s**, at most **3 attempts** per request, **20 s** total budget. Each failed attempt can cost up to the attempt timeout; a 429 is instant; a model marked down costs nothing on later requests. The relay hard-caps a provider call at 30 s, so never configure an attempt timeout above that. **Measure first**: SkillOS responses are structured JSON with up to ~80 words of Persian feedback, which can be slower than English OS's short replies. If healthy models routinely take more than ~8 s on a real Evaluator prompt, raise `attempt_timeout` (and say so); do not guess.
4. **Keep the `RuntimeException` contract (Pitfall P1).** `SessionController::submit()` catches `RuntimeException` only. The chain walk rethrows the provider exceptions (`Illuminate\Http\Client\RequestException` / `ConnectionException`, both extend `HttpClientException extends Exception`, **not** `RuntimeException`), which would turn a Gemini outage into a 500 page. In `generateJson()`, catch `Throwable` from the walk and throw `new RuntimeException('Gemini request failed on every model.', previous: $e)` — a message with no key and no provider body. Also keep throwing `RuntimeException` for "no key", "no content" and "invalid JSON"; **do not include the response body or model output in exception messages** (today's code does; they end up in reports and logs). Add a test that a total outage still lands in the controller's friendly-error branch (not a 500).
5. **What happens after a total failure (decision for the user; ask, do not assume).** Grading feeds mastery, so failing open is *not* free here the way it was in English OS:
   - *Default, recommended:* keep today's behavior — friendly Persian error, the learner's answer preserved, retry later (they can also `giveUp()` or `cancel()`). With a working chain this should be rare.
   - *Alternative:* record the attempt as "pending evaluation" and let the learner continue. Needs a schema change and rules for mastery; propose it, do not build it unasked.
   **Decided by the user (2026-10-07): keep today's behavior** (friendly error, answer preserved, retry later). Do not build the alternative unasked.

> **Status (2026-10-07), so you do not redo it:** the code side of Part B, task A3, the clean-up A4 and the security sweep were done in `/home/dev/Desktop/SkillOs` in three local commits (`5590748` chain + ops + tests + docs, `2ffdcf7` old relay copy removed, `d2a5450` seeder emptied; nothing pushed). The model comparison (B4) is **done** and the order approved: `GEMINI_JUDGE_MODELS=gemini-3.5-flash-lite,gemini-3.5-flash:minimal,gemini-3.1-flash-lite`, `GEMINI_GENERATE_MODELS=gemini-3.1-flash-lite,gemini-3.5-flash:minimal` (recorded in SkillOS `docs/DECISIONS.md` §67; note `gemini-2.5-flash`, the old config default, answers 404 for the new account's key). Still open: production `.env` (the user sets it) + deploy + `/_ops/optimize`, and the production verification (B8, A1, A2). The sweep found no committed secrets (current tree and full git history); the only finding fixed was the seeded `test@example.com` / `password` account.
6. **Configuration**, read only in `config/services.php` (never `env()` elsewhere; config is cached in production):

```php
'gemini' => [
    'api_key' => env('GEMINI_API_KEY'),
    'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),          // legacy primary; keep
    'fallback_model' => env('GEMINI_FALLBACK_MODEL', ''),         // legacy secondary; keep
    'judge_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_JUDGE_MODELS', ''))))),
    'generate_models' => array_values(array_filter(array_map('trim', explode(',', (string) env('GEMINI_GENERATE_MODELS', ''))))),
    'attempt_timeout' => (int) env('GEMINI_ATTEMPT_TIMEOUT', 10),
    'max_attempts' => (int) env('GEMINI_MAX_ATTEMPTS', 3),
    'total_budget' => (int) env('GEMINI_TOTAL_BUDGET', 20),
],
```

   **Backward compatible:** when a list is empty, the chain is `[model, fallback_model]` (skipping an empty fallback or one equal to the primary), which is today's behavior plus one fallback. Keep the existing `api_key` config name (English OS calls it `key`). Update `.env.example` (and note it).
7. **Per-model options.** An entry is `model` or `model:thinkingLevel` (see B5). Keep this small and explicit.
8. **State in the cache**, key `ai-chain:gemini:{model}` → `{until, reason, since}` with TTL equal to the skip time; counters `ai-chain-stats:gemini:{model}:{Y-m-d}`. All cache access is best-effort (`try/catch`): a broken cache must never take an AI call down.
9. **Logging.** `Log::error` once when a model leaves the rotation (fresh mark only) and once when a request exhausts its chain (models tried, classified kind, truncated message ≤ 300 chars, the relay URL, never keys, headers or bodies). Production runs at `LOG_LEVEL=error`, which silently drops warnings — that is why failures were invisible. Use error level.

## B4. Choosing the models (do a small real comparison first)

Do **not** copy English OS's order. English OS grades short English sentences; SkillOS grades technical practice answers with a rubric and writes Persian feedback. Compare on SkillOS's own prompts, then **the user decides the order**.

- Candidates visible to English OS's free key on 2026-10-07 (text, `generateContent`): `gemini-3.8-flash`, `gemini-3.7-flash`, `gemini-3.6-flash`, `gemini-3.5-flash`, `gemini-3.5-flash-lite`, `gemini-3.1-flash-lite`, `gemini-3-flash-preview`, `gemini-flash-latest` / `gemini-flash-lite-latest` (moving aliases that may share the primary's quota — avoid), `gemini-pro-latest`, `gemini-3.1-pro-preview`, `gemma-4-31b-it`, `gemma-4-26b-a4b-it`, `gemini-2.5-flash`, `gemini-2.5-pro`. Check what *SkillOS's* key sees (the models list endpoint, through the relay). `gemini-2.5-flash-lite` is retired (404).
- Prompts: 8–10 real `Evaluator` prompts (build them from real lesson rubrics in `content/`, include: a clearly correct answer, a partial one, a wrong one, a wrong-but-confident one, a very short one, one with code, one in mixed Persian/English, one trying to make the model reveal the reference answer) plus 1–2 `ReviewPracticeGenerator` prompts. Use the **real response schema**.
- Record per model: verdict correctness against your expectation, whether the Persian feedback follows the rules (no answer reveal, ≤ 80 words, no scores), JSON validity, finish reason, thinking tokens, latency. Present side by side.
- Budget: stay well inside each model's 20/day (a run of 8–10 prompts × 4–5 models is about 10 requests per model). Say how many requests you used. Run from a laptop with direct access if the host cannot reach Google, or through the relay.
- What English OS found (for calibration, not as a ranking for SkillOS): `gemini-3.5-flash-lite` followed a strict JSON-plus-short-hint rubric on 9/9 prompts and was usually 1–2 s but had 10–17 s spikes; `gemini-3.1-flash-lite` often hung or returned 503; `gemini-3.5-flash` with `thinkingLevel: minimal` was good but timed out on 3 of 9; `gemini-3.7-flash` / `3.8-flash` returned 503/timeouts and a 429 and could not be ranked. Treat every ranking as perishable.

## B5. Thinking models

"Thinking" Flash models spend output tokens on thinking. SkillOS sets no `maxOutputTokens`, so truncation is less of a risk than in English OS, but thinking adds latency (tens of seconds in English OS tests), which is exactly what the learner must not wait for. Per entry you may set `thinkingConfig.thinkingLevel`; facts from testing:

- `gemini-3.5-flash` accepts `minimal`. `gemini-3.7-flash` **rejects `minimal` with 400** ("Thinking level MINIMAL is not supported for this model") but accepts `low`. Do not send `thinkingBudget` and `thinkingLevel` together (400).
- Whether `thinkingConfig` is compatible with `responseSchema` structured output on each model is **untested** — test it in B4.
- Because a 400 does not walk the chain (B2), a bad level on the first entry breaks all grading. Run the chain probe (B7) after any change to the lists.

## B6. Visibility and alerts

SkillOS has no push or notification infrastructure and the production host has no terminal, so follow the existing pattern: token-gated plain-text ops reports.

- Extend `OpsController` with an action (for example `ai-chains`, add to `ACTIONS`, keep the `OPS_TOKEN` gate) that prints, per chain: each model's position, whether Google still serves it (`GET /v1beta/models/{model}` through the relay — costs no generation quota; 200 = exists, 404 = retired = stale config, other = unknown), whether it is in rotation or skipped (reason and until when), and today's success/failure counts. Also extend `aiCheck()` to print the configured chains. A real generate probe should default to the first model of each chain only (`?probe=all` for every model, one request each).
- Optional admin-only page (`users.is_admin`, like `FriendsController`) showing the same read-only data. Ask the user whether they want it.
- **Alert on a degraded chain.** Port the idea, not the notification classes: when a model leaves the rotation and only one model (or none) of the chain remains, `Log::error` once per chain per level (`last` / `none`), throttled with `Cache::add($key, true, now()->addHours(6))`. If the user wants a push/bell like English OS, that is a separate feature (SkillOS has no notifications table); propose it, do not build it unasked.

## B7. Tests

Port and adapt English OS's `GeminiClientTest` using `Http::fake`, `Http::preventStrayRequests()`, the array cache store and `$this->travel(...)`. Cover at least: first model succeeds; daily-quota 429 → next model → later request skips it with **no extra HTTP request** → retried after `retryDelay`; per-minute 429 skipped only briefly; 404 retired model skipped for a long time; 5xx and connection failure move on; **400 is thrown without walking**; every model failing → one error log, no secrets, last error surfaced as `RuntimeException`; depth cap; all-marked chain still probes one model; two profiles use their own lists; config falls back to the legacy `model` + `fallback_model`; `thinkingLevel` is sent only for entries that carry one; **a total outage reaches `SessionController::submit()`'s friendly-error branch, not a 500** (P1); structured-output payload (`responseMimeType`, `responseSchema`) is unchanged; no key or response body in any exception message or log. Existing mocks of `generateJson` keep working. Test names state the behavior (`test_daily_quota_429_skips_the_model_on_later_requests…`).

## B8. Verify on the production host

After the user deploys and sets the `.env` lists and clears the config cache: run `/_ops/ai-check` and the new chain report; every configured model must show "exists" and "in rotation" (any "GONE" is stale config). Run a real evaluation of a realistic answer. The user, not you, sets production `.env`.

---

# Phases (stop after each for the user's approval)

1. **A1–A3 (wiring).** Shared relay, `.env.example`, verify `Gemini relay test SUCCESS`. Ask for the inputs one at a time.
2. **B4 (comparison).** Measure real prompt sizes and latencies; run the small model comparison; present results; the user picks the order and the profile split.
3. **B3 + B7 (the chain).** Port `AiModelChain`, rewrite `generateJson()`, config, `RuntimeException` contract, tests. Then ask the B3.5 question.
4. **B6 + B8 (visibility and verification).** Ops report, degraded-chain log alert, production verification, the large realistic request from A2.
5. **A4 (clean-up) and security sweep (below).**

## Security sweep of SkillOS (the repo is public)

English OS had a fixed admin password and a throwaway admin account committed to its public deploy script, and both worked on production. Check SkillOS for the same class of problem and **report** (do not silently change production data):

- Fallback or default passwords, seeded admin accounts, demo credentials in `database/seeders`, `deploy/`, `tools/`, `README.md`, `docs/`, tests that run against production, or `.env.production.example`. Note `database/migrations/2026_09_16_035745_add_is_admin_to_users_table.php` promotes a hard-coded email (`moja@skillos.local`): check that this cannot be exploited on production (who can register that address?).
- `OPS_TOKEN` and any other maintenance route: disabled when the token is unset, not default or guessable, not committed.
- If a committed credential is live on production, the fix is a new secret set in `.env` and the old one treated as burned (it stays in git history).

## Pitfalls we already hit

- **P1 — exception types.** The chain walk throws Laravel HTTP-client exceptions that are not `RuntimeException`s; `SessionController::submit()` only catches `RuntimeException`. Convert at the `generateJson()` boundary (B3.4) and test it.
- **Test on the host.** A laptop can succeed where the host fails.
- **Instant connection failures (a few ms, cURL error 7)** usually mean the host's IP or the destination is filtered: not a code bug; tell the user so they can contact the hosting company.
- **Failures only for large requests** point at the network path; report the size where it starts.
- The shared host disables `proc_open()`: scheduled commands must run in-process (closures), not as shell tasks.
- With config cached, `.env` changes are ignored until the cache is cleared.
- Do not point anything at `64.226.95.102` / `relay2.growwise.ir`, the VPS's raw IP, or an `lhr.life` tunnel URL.
- Frontend builds on the developer machine sometimes time out fetching fonts under a VPN or on IPv6; `NODE_OPTIONS=--dns-result-order=ipv4first` fixed it for English OS.
- Running the test suite locally may need `composer install`, a temporary `.env` with an app key (`cp .env.example .env && php artisan key:generate`, delete it afterwards), and `npm run build` for tests that render the layout (a missing Vite manifest fails ~50 tests in English OS unrelated to this work). Run the narrow tests that cover your change.
- `Illuminate\Console\Command` already has a public `fail()`; do not name a private helper `fail`.

## Privacy and key handling

The relay and the networks in front of it can see request contents, including the Gemini key and learner answers. Prefer a key with a quota cap and a separate key for SkillOS. Never log request bodies or headers that contain keys. Tell the user that learners' free-text answers go to Gemini.

## Optional (only if the user asks): automatic backup relay path

English OS keeps a second path (a free localhost.run tunnel on the same VPS) and flips between the two automatically. For SkillOS this means two config slots instead of one, and copying `AiRelayUse`, `AiRelaySyncLocalUrl`, `AiRelayFailover` and their scheduler entries from `/home/dev/Desktop/EnglishOs/app/Console/Commands/` and `routes/console.php`. Both sites share the same VPS and the same filtering, so it protects against Cloudflare trouble but not against the VPS being down. Ask first; it is a lot of moving parts.

## Definition of done

- [ ] Production `AI_PROXY_URL` is `https://relay.growwise.ir` with the shared secret; `/_ops/ai-check` prints `Gemini relay test SUCCESS`.
- [ ] `GeminiClient::generateJson()` walks an ordered, env-configured model chain per profile (`judge`, `generate`), remembers exhausted/retired/failing models and skips them without a request, never waits long (stated attempt timeout, depth cap, total budget), keeps its signature and return shape, and still throws only `RuntimeException`s with no key or response body in the message.
- [ ] A total outage reaches `SessionController`'s friendly-error branch (tested), and the user decided what a total failure should do for the learner.
- [ ] The model order was chosen from a real comparison on SkillOS prompts and approved by the user; the number of provider requests used is reported.
- [ ] An ops report shows each model's existence, rotation state and today's counts; a degraded chain logs one error per outage.
- [ ] Tests cover the matrix in B7, pass, and Pint is clean; existing tests that mock `generateJson` still pass.
- [ ] The obsolete `tools/` relay copy is removed or replaced with a pointer; `.env.example` is accurate; `docs/DECISIONS.md` has an entry and `docs/STORIES.md` is ticked.
- [ ] A realistic **large** request succeeded from the production host through the relay.
- [ ] The security sweep is reported (what was checked, what was found).
- [ ] You told the user: files changed, the largest request size verified, the timeout decision, the request count spent on the comparison, and anything you could not verify.
