# Grammar lesson format (Grammar in Context)

Every mission's `grammar_in_context` step is seeded in `database/seeders/MissionSeeder.php`
and rendered by `resources/views/components/missions/steps/partials/grammar-lesson-section.blade.php`.
Any lesson added later MUST follow this format. M01, M02, M04 and the modals lesson are the
reference examples.

## Principles

- **One idea per section.** A short heading, at most one plain sentence of `body`, then examples.
  Never a wall of text.
- **Simple English first.** Short sentences, everyday words, one point per sentence.
- **Examples carry the lesson.** Short sentences, one point each, the key word highlighted.
- **Persian is optional help, never the main text.** It lives in a folded-away `fa` line.

## Section shape

```php
[
    'heading' => 'A · He / she / it needs an -s',   // "<Letter> · <Title>" — the letter becomes a badge
    'body'    => 'With <strong>I / we / you / they</strong> nothing changes.', // optional, ONE sentence
    'blocks'  => [ ... ],
]
```

- 3 sections per lesson (A, B, C) is the norm. `intro` is one sentence: "Three small things: …".
- A section that has `blocks` MUST open with a `rule` block (enforced by
  `GrammarInContextStepTest::test_every_seeded_grammar_lesson_follows_the_one_idea_per_section_shape`).
- Keep the order inside a section: **rule → formula → ✅/❌ or mistake → examples**.
- `body` and `rule.text` are trusted seed content; `<strong>`, `<em>` and `<br>` are allowed.

## Block types

| type | Use it for | Keys |
| --- | --- | --- |
| `rule` | The one-line takeaway. Required first block. | `text`, `fa` (Persian, optional but expected) |
| `formula` | A sentence pattern as coloured parts: `She + wakes + up early`. | `label`, `parts[]` of `text`, `caption`, optional `tone` (`accent`/`success`/`neutral`) |
| `do_dont` | Wrong vs right, always visible. | `items[]` of `wrong`, `right`, optional `highlight`, `note` |
| `mistake_fix` | A character says something wrong; tap to see the fix. | `character`, `wrong`, `right`, `explanation` |
| `pairs` | Transformations, right side tap-to-reveal. | `pairs[]` of `left`, `right` |
| `examples` | Plain example sentences, optionally grouped. | `groups[]` of `label`, `items[]` |
| `chips` | A scale or set of words. | `groups[]` of `label`, `words[]` |
| `rule_examples` | A rule with a tap-to-reveal example. | `items[]` of `rule`, `example`, `highlight` |

## Writing the `fa` line

- One or two short sentences that restate the rule in plain Persian.
- Keep English grammar terms and example words in English (`do / does`, `Present Simple`, `rice`).
- Don't translate the examples; the line explains the rule only.

## What the rest of the step needs from the same content

- `frequency_starters`: the sentence starters. The learner types only the continuation; the
  starter is shown inside the answer box, and the AI always receives starter + continuation.
- `quick_check`: at least 3 cards (`wrong`, `options`, `correct`, `difficulty`). **The Daily
  Review quizzes the learner with these**, so a lesson without them falls back to the old
  self-graded reminder.
- `word_order`, `grammar_judgment`, `grammar_major_criteria`, `grammar_context`: as in the
  existing missions.
- The rules shown in the review card are every `rule` block of the lesson (with their `fa`),
  so write each `rule` so it still makes sense on its own, outside the lesson.

## Checklist before adding a lesson

1. Each section opens with a `rule` (with `fa`).
2. No section has more than one sentence of `body`.
3. At least one ✅/❌ (`do_dont` or `mistake_fix`) in the lesson.
4. `quick_check` has 3+ cards that match the rules taught.
5. Run `tests/Feature/GrammarInContextStepTest.php` and `tests/Feature/DailyReviewTest.php`.
