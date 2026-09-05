<?php

namespace App\Services;

use Closure;
use RuntimeException;

/**
 * Shared plumbing behind every "3-part feedback card" AI call — Writing,
 * Story Sequence, and Picture Description all ask Gemini for a flat
 * {strength, expression, correction} JSON shape, and AI Feedback #1 asks
 * for a richer Persian variant with a `severity` field and a NESTED
 * `correction` object ({original, corrected, why, suggestion}). What was
 * genuinely identical across all four hand-rolled copies — send the
 * chat() request, json_decode the trimmed result, validate the required
 * keys are present, throw a clear exception on malformed output — lives
 * here now. What is genuinely different per step — the systemPrompt TEXT
 * itself (judgment criteria, level-description interpolation, Persian vs
 * English, per-step instructions) — stays authored in each step component;
 * this service only dispatches it.
 *
 * One shared generate() method covers both shapes: the required-keys list
 * is just parameterized per caller (dot-notation for AI Feedback #1's
 * nested correction.* fields), so there was no honest reason for a second
 * method — the divergence is in prompt content and required-keys, not in
 * how the response is fetched/parsed/validated.
 */
class AiFeedbackCard
{
    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @param  array<int, array{role: string, text: string}>  $messages  Same shape GeminiClient::chat() takes.
     * @param  list<string>  $requiredKeys  Keys the parsed response must contain and that must not be null —
     *                                      dot-notation for a nested key, e.g. 'correction.original'.
     * @param  Closure|null  $onCallSucceeded  Fired right after chat() returns successfully, BEFORE JSON
     *                                         parsing/validation — this is where a caller hooks
     *                                         TracksAiUsage::recordGeminiCall(), which must fire whenever the
     *                                         real API call itself succeeded, independent of whether its
     *                                         response then turns out to be malformed.
     * @return array<string, mixed> the decoded response.
     *
     * @throws RuntimeException when the response isn't valid JSON, or is missing a required key.
     */
    public function generate(array $messages, string $systemPrompt, array $requiredKeys, ?Closure $onCallSucceeded = null): array
    {
        $raw = $this->gemini->chat($messages, systemPrompt: $systemPrompt);

        if ($onCallSucceeded) {
            $onCallSucceeded();
        }

        $data = json_decode(trim($raw), true);

        if (! is_array($data) || ! $this->hasAllKeys($data, $requiredKeys)) {
            throw new RuntimeException('Unexpected AI response format.');
        }

        return $data;
    }

    /**
     * The vocabulary-grounding context sentence appended to a systemPrompt
     * so the AI can warmly notice the learner's own selected words if they
     * happened to use one — identical wording across every call site that
     * grounds in the learner's selected vocabulary (Writing, Picture
     * Description). Story Sequence grounds in different content
     * (sequencing words derived from picture captions, not vocabulary)
     * with genuinely different wording, so that one stays inline rather
     * than being forced through this helper.
     *
     * @param  list<string>  $vocabularyWords
     */
    public function vocabularyContext(array $vocabularyWords): string
    {
        return $vocabularyWords
            ? ' If any of these words appear naturally, you can mention that warmly: '
                .collect($vocabularyWords)->map(fn ($w) => "\"{$w}\"")->implode(', ').'.'
            : '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $requiredKeys
     */
    private function hasAllKeys(array $data, array $requiredKeys): bool
    {
        foreach ($requiredKeys as $key) {
            if (! $this->hasKey($data, $key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * isset()-equivalent nested lookup by dot-notation path — a present
     * but null value counts as missing, matching every hand-rolled
     * isset($data['a'], $data['b']['c']) check this replaces.
     */
    private function hasKey(array $data, string $dottedKey): bool
    {
        $cursor = $data;

        foreach (explode('.', $dottedKey) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor) || $cursor[$segment] === null) {
                return false;
            }

            $cursor = $cursor[$segment];
        }

        return true;
    }
}
