<?php

namespace App\Models;

use App\Models\Concerns\HasSpacedRepetition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['learner_id', 'source_mission_run_id', 'mission_code', 'focus', 'example_sentence', 'rule_reminder', 'ease_factor', 'interval_days', 'repetitions', 'next_review_at', 'last_reviewed_at'])]
class GrammarPoint extends Model
{
    use HasFactory;
    use HasSpacedRepetition;

    protected $attributes = [
        'ease_factor' => 2.5,
        'interval_days' => 0,
        'repetitions' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ease_factor' => 'float',
            'next_review_at' => 'datetime',
            'last_reviewed_at' => 'datetime',
        ];
    }

    public function learner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'learner_id');
    }

    public function sourceMissionRun(): BelongsTo
    {
        return $this->belongsTo(MissionRun::class, 'source_mission_run_id');
    }

    /**
     * What the Daily Review needs to quiz this point: one "fix this
     * sentence" card and the lesson's one-line rules, both read from the
     * mission's seeded grammar_in_context content (nothing is copied onto
     * the row). The card rotates with the number of past reviews, so
     * coming back to the same point asks a different question. `card` is
     * null when the mission or its quick_check is gone — the review then
     * falls back to the plain self-graded reminder.
     *
     * @return array{card: array{wrong: string, options: list<string>, correct: int}|null, rules: list<array{text: string, fa: string|null}>}
     */
    public function reviewContent(): array
    {
        $grammar = Mission::where('code', $this->mission_code)->first()?->stepContent('grammar_in_context') ?? [];

        $cards = array_values($grammar['quick_check'] ?? []);
        $card = $cards === [] ? null : $cards[((int) $this->repetitions) % count($cards)];

        $rules = collect($grammar['lesson']['sections'] ?? [])
            ->flatMap(fn (array $section) => $section['blocks'] ?? [])
            ->where('type', 'rule')
            ->map(fn (array $block) => ['text' => $block['text'], 'fa' => $block['fa'] ?? null])
            ->values()
            ->all();

        return [
            'card' => $card === null ? null : ['wrong' => $card['wrong'], 'options' => $card['options'], 'correct' => $card['correct']],
            'rules' => $rules,
        ];
    }
}
