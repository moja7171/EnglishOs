<?php

namespace App\Models;

use App\Services\MediaHost;
use Database\Factories\VocabTextFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One text (section A to E) of a Vocab in Use unit: the book's own words,
 * the sentences with where the book's mp3 says each one, and the bold
 * target words as VocabItem rows.
 */
#[Fillable(['vocab_unit_id', 'letter', 'title', 'kind', 'body', 'sentences', 'rows', 'notes', 'audio_file'])]
class VocabText extends Model
{
    /** @use HasFactory<VocabTextFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'sentences' => 'array',
            'rows' => 'array',
        ];
    }

    /**
     * @return BelongsTo<VocabUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VocabUnit::class, 'vocab_unit_id');
    }

    /**
     * @return HasMany<VocabItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(VocabItem::class)->orderBy('position');
    }

    /**
     * A text that is a table or a picture in the book has no sentences to read.
     */
    public function hasSentences(): bool
    {
        return $this->sentences !== [];
    }

    /**
     * A stretch of the body cut into plain pieces and the book's bold
     * words, in order, so a page can mark the words without touching the
     * text.
     *
     * @param  list<int>|null  $only  Only these items stand out (the saved ones on a card); null = all.
     * @return list<array{text: string, item: VocabItem|null}>
     */
    public function pieces(?int $from = null, ?int $to = null, ?array $only = null): array
    {
        $from ??= 0;
        $to ??= mb_strlen($this->body);
        $pieces = [];
        $cursor = $from;

        foreach ($this->items as $item) {
            if ($item->start < $cursor || $item->end > $to || ($only !== null && ! in_array($item->id, $only, true))) {
                continue;
            }

            if ($item->start > $cursor) {
                $pieces[] = ['text' => mb_substr($this->body, $cursor, $item->start - $cursor), 'item' => null];
            }

            $pieces[] = ['text' => mb_substr($this->body, $item->start, $item->end - $item->start), 'item' => $item];
            $cursor = $item->end;
        }

        if ($cursor < $to) {
            $pieces[] = ['text' => mb_substr($this->body, $cursor, $to - $cursor), 'item' => null];
        }

        return $pieces;
    }

    /**
     * Where the book's audio for this text is played from, or null while
     * no download host is set up (local development, tests).
     */
    public function audioUrl(): ?string
    {
        return $this->audio_file === null ? null : app(MediaHost::class)->vocabAudioUrl($this->audio_file);
    }

    /**
     * The sentences the synced-text panel of the audio player expects.
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    public function playerSegments(): array
    {
        return collect($this->sentences)
            ->filter(fn (array $sentence): bool => isset($sentence['audio_start'], $sentence['audio_end']))
            ->map(fn (array $sentence): array => [
                'text' => $sentence['text'],
                'start' => (float) $sentence['audio_start'],
                'end' => (float) $sentence['audio_end'],
            ])
            ->values()
            ->all();
    }
}
