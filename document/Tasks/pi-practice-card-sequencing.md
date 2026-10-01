# Story: Move the Pi practice card to after the matching in-app content, not before it

## Status
Findings 1 and 2 implemented and committed (45e1ed4). Finding 3 implemented
in this pass.

## Background
`<x-pi-practice-card>` (resources\views\components\pi-practice-card.blade.php) is an
optional, non-blocking card that suggests a ready-to-paste prompt for one of the
learner's 3 persistent Pi chats (Teacher / Language Partner / Pronunciation Coach).
It renders unconditionally and immediately whenever its `$task` prop is non-null —
it has no awareness of where it sits in the surrounding step's own flow.

Three steps placed this card at the very top of the step, before the on-screen
content the card's own prompt depends on had been experienced by the learner.
Findings 1 and 2 were confirmed by a formal UI/UX review (ui-ux-product-designer
agent) on 2026-10-01. Finding 3 (Video Shadowing) was spotted by inspection
once the same pattern was suspected there too — not yet run through a separate
formal review, but structurally identical to Finding 1.

## Finding 1 — Listen Again (`daily-listen-view.blade.php`) — severe — FIXED

- File: `resources\views\components\missions\steps\partials\daily-listen-view.blade.php`
- Was: hero image → hook → **Pi practice card (Pronunciation Coach)** → audio
  player → shadow-lines block (gated behind `hasListened` + `x-cloak`) → Continue.
- `App\Services\PiPrompts::coachTask()` quoted the exact shadow-line text verbatim
  in the card's prompt — lines still hidden when the card rendered.
- **Fix applied:** card moved to inside the shadow-lines block, after the
  `@foreach` of lines, still under `@unless ($readOnly)`. It now renders only
  once `hasListened` has revealed that block.

## Finding 2 — Grammar Time (`⚡grammar-in-context.blade.php`) — milder, same anti-pattern — FIXED

- File: `resources\views\components\missions\steps\⚡grammar-in-context.blade.php`
- Was: hook → focus label → **Pi practice card (Teacher)** → `phase === 'lesson'`
  block (unopened) → `phase === 'practice'` block ("Make it personal").
- `PiPrompts::teacherTask()` only needs the `focus` label (already visible), so
  the prompt itself was coherent — the defect was inviting the learner to
  produce the grammar structure before the in-app lesson had taught it.
- **Fix applied:** card moved into the `phase === 'practice'` block, right
  before "Make it personal" (after Quick Check / Build-the-sentence).

## Finding 3 — Video Shadowing (`⚡video-shadowing.blade.php`) — severe, same shape as Finding 1 — FIXED

- File: `resources\views\components\missions\steps\⚡video-shadowing.blade.php`
- Was: hook → **Pi practice card (Pronunciation Coach)** (old lines 200-202) →
  video source label + video player → substep 0 (watch checkbox + Quick Check
  + expressions) → substep 1 (the actual shadow-line recorders, `x-show
  ="activeSubstep === 1"`).
- `piTask()` resolves through the same `PiPrompts::coachTask($this->run,
  'video_shadowing')` as Finding 1 — it quotes the exact shadow-line text in
  the card's prompt. Those lines live entirely on substep 1, not substep 0
  (the default/landing substep), so the card referenced content that wasn't
  just below-the-fold but on an entirely different pager page.
- Notably `<x-substep-nav index-var="activeSubstep" :total="$totalSubsteps" />`
  here is called with no `next-disabled` prop (it defaults to `'false'`), so
  there isn't even a watch-first gate stopping a learner from clicking to
  substep 1 early — but the *default* landing view (substep 0) still put the
  card above the video, before anything had been watched or any line seen.
- **Fix applied:** card moved inside the substep-1 block
  (`x-show="activeSubstep === 1"`), after the `@foreach` of shadow lines and
  its `@error`, inside the same `@if (count($shadowLines))` wrapper, still
  under `@unless ($readOnly)`. It now renders alongside the actual lines it
  quotes, on the substep where they're visible.

## Fix pattern (for reference)

All three fixes reused state the component already had — no new gating logic
needed, and no change to `PiPrompts::coachTask()` / `teacherTask()` content;
this was a placement-only change in every case.

## Acceptance criteria

- [x] Listen Again: Pi practice card is not visible until `hasListened` is true;
      it appears alongside/after the shadow-lines list, not above the player.
- [x] Grammar Time: Pi practice card is not visible during the `lesson` phase;
      it appears once `phase === 'practice'`, near "Make it personal".
- [x] Video Shadowing: Pi practice card is not visible on substep 0; it appears
      on substep 1, alongside the shadow-line recorders it quotes.
- [x] No change to `PiPrompts::coachTask()` / `teacherTask()` content — all
      three fixes are placement-only.
- [x] Existing tests for all three steps (`DailyListenStepTest`,
      `GrammarInContextStepTest`, `VideoShadowingStepTest`) still pass.
- [x] Read-only review mode (`$readOnly`) is unaffected — the card already
      never renders there (`@unless ($readOnly)`).

## Notes

- Tests were run against an in-memory sqlite database
  (`DB_CONNECTION=sqlite DB_DATABASE=:memory:`) rather than the mysql
  connection `phpunit.xml` normally points at, because no local mysql test
  server was reachable in this environment. PHPUnit's `<env>` entries don't
  carry `force="true"`, so a real OS environment variable of the same name
  takes precedence without needing to edit `phpunit.xml`.
- `⚡video-shadowing.blade.php` had already drifted from the version reviewed
  earlier in this story (e.g. the "watch again without captions" bonus
  checkbox is gone, a `shadow-active` prop was added to `<x-video-player>`,
  and the hint copy changed) — those changes came from other work on the
  branch, not from this story, and are unrelated to this placement fix.
