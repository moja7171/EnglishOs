<?php

namespace App\Livewire\Concerns;

use App\Models\VocabularyWord;
use App\Services\SentenceChecker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * The deeper, AI-checked written-review path for a brand-new (or just
 * reset) VocabularyWord — see HasSpacedRepetition::needsWrittenReview().
 * Originally only My Words ran this; pulled out so every review surface
 * that can encounter a fresh word (My Words, Daily Review) runs the exact
 * same SentenceChecker judgment and SM-2 grading, instead of Daily Review
 * falling back to a shallower reveal-then-tap check a learner could clear
 * in one click without ever writing anything (see EOS-009 UX audit,
 * Daily Review task 1).
 */
trait ChecksVocabularyWordSentences
{
    /**
     * Runs the shared judgment + grading for $word and returns the
     * outcome as data rather than writing to any fixed property — the two
     * call sites (My Words' $feedback/$checkError, Daily Review's own
     * $wordFeedback/$wordCheckError) are different properties on
     * different components, so each caller stores the result onto
     * whichever properties its own view expects.
     *
     * @return array{feedback: array{severity: string, hint: string}|null, error: string|null}
     */
    protected function runWordSentenceCheck(VocabularyWord $word, string $text): array
    {
        try {
            $data = app(SentenceChecker::class)->check(
                judgment: 'Judge whether the learner used the target word correctly, naturally, and as a '
                    .'genuine sentence (not just repeating the dictionary definition).',
                majorCriteria: 'the word is missing or used with the wrong meaning, the sentence just repeats '
                    .'the definition',
                context: "a sentence using the word \"{$word->word}\"",
                text: $text,
            );

            $word->review(match ($data['severity']) {
                'major' => 1,
                'minor' => 4,
                default => 5,
            });

            return ['feedback' => $data, 'error' => null];
        } catch (ConnectionException|RequestException) {
            return ['feedback' => null, 'error' => "Couldn't reach the AI service — please try again."];
        } catch (Throwable $e) {
            return ['feedback' => null, 'error' => "Couldn't check this one: {$e->getMessage()}"];
        }
    }

    /**
     * A one-card meaning-match <x-quick-round> warm-up for $word — see My
     * Words' original diagnosticCard() for the reference shape. Shared so
     * every written-review path offers the identical ungraded warm-up
     * rather than a re-derived one. Distractor meanings come from the
     * learner's OTHER words, so with fewer than 3 words total there's
     * nothing plausible to build them from and this returns null (the
     * written review shows immediately instead).
     *
     * @return array{prompt: string, options: list<string>, correct: int}|null
     */
    protected function wordDiagnosticCard(VocabularyWord $word): ?array
    {
        $distractors = auth()->user()->vocabularyWords()
            ->where('id', '!=', $word->id)
            ->inRandomOrder()
            ->limit(2)
            ->pluck('meaning')
            ->filter();

        if ($distractors->count() < 2) {
            return null;
        }

        $options = collect([$word->meaning, ...$distractors])->shuffle()->values();

        return ['prompt' => $word->word, 'options' => $options->all(), 'correct' => $options->search($word->meaning)];
    }
}
