You are starting a fresh session in the English OS Laravel repo (`/home/dev/Desktop/EnglishOs`). The user speaks Persian: talk to them in Persian; keep code, comments, commit messages and documents in English.

## Task
Implement **ordered free-tier model chains** for the app's AI calls, in phases, stopping for the user's approval after each one:

- **Gemini** (all text, through `GeminiClient::chat()`): a ranked list of models per profile (a cheap *chat* chain and a quality-first *judge* chain). Use the first model until its quota is exhausted, then the next; remember which model is exhausted and until when, so later requests skip it without paying a failed request.
- **Groq** (Whisper speech-to-text, through `GroqClient`): the same idea over its two Whisper models.
- A read-only **admin status view**, a **diagnostics section**, and an **alert** when a chain is down to its last model, so failures stop being invisible.

## First, read (all of it)
1. `document/ai-model-chain-spec.md`: the full spec. It contains the evidence gathered in a long debugging session (a real Google 429 payload, which models were tested and how they behaved, the traps), the decisions already made with the user, the design, the phases, and the acceptance criteria. Do not rediscover these.
2. `document/ai-relay-vps.fa.md`: how provider calls reach Google/Groq through a relay VPS (Persian; commands and facts are plain).
3. `CLAUDE.md` / `AGENTS.md`: the project rules (conventions, tests, Pint, no new dependencies).

## Then
1. Run `git status` and `git log -8 --oneline`. A small uncommitted change may exist (the fallback default changed to `gemini-3.1-flash-lite` in `config/services.php`, `GeminiClient`, `.env.example`); the spec's section 2 explains it.
2. Tell the user, in Persian and in at most ten lines, what you understood the goal and the phases to be.
3. Ask the user the questions in section 10 of the spec, then start **Phase 1** and continue phase by phase.

## Ground rules
- Commit only when the user asks. Add the attribution line your environment specifies.
- **Never put secrets in files, commits or chat**: Gemini/Groq keys, `RELAY_SECRET`, `DEPLOY_TOKEN`, `ADMIN_PASSWORD`. Ask the user when you need a key or the diag token.
- Verify behavior on the **production host** through `/_diag/ai?token=…` after the user deploys; a laptop result is not proof (the host's network path caused several past "bugs").
- You cannot count on being allowed to run the deploy script or edit `public/deploy.php` (it is on the `deploy` branch and security-sensitive). If a permission is denied, tell the user what you wanted to do and the exact command; do not work around it.
- **One Google project, one key.** Quota is per project and per model; do not build or suggest key/project/account rotation to multiply it. More models in the chain is the supported way to get more free capacity. The user probably cannot pay for Gemini (Iran): ask early, and if so, propose the Groq text tail described in the spec.
- Free-tier quota on the live key is shared with the live site. Keep any test or comparison small, and say how many requests you used.
- Prefer small, reviewable steps and real tests (PHPUnit, `Http::fake`) over ad-hoc scripts.
