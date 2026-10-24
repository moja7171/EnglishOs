<?php

use App\Services\FeedbackNotebook;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Feedback asked again on the same text used to add a twin line (the
     * fix worded a little differently, or another category), so practice
     * showed the same sentence twice. One line per sentence of the
     * learner's per notebook now (see FeedbackNotebook): the most repeated
     * twin stays, still active or starred if any twin was, and a tip whose
     * sentence is already a mistake goes.
     */
    public function up(): void
    {
        $entries = DB::table('notebook_entries')->whereNotNull('original')->orderBy('id')->get();
        $doomed = [];

        $entries->groupBy(fn (object $entry) => $entry->learner_id.'|'.$entry->kind.'|'.FeedbackNotebook::sentenceKey($entry->original))
            ->filter(fn (Collection $twins) => $twins->count() > 1)
            ->each(function (Collection $twins) use (&$doomed) {
                $kept = $twins->sortByDesc(fn (object $entry) => [$entry->times_seen, $entry->last_seen_at])->first();

                DB::table('notebook_entries')->where('id', $kept->id)->update([
                    'learned_at' => $twins->contains(fn (object $entry) => $entry->learned_at === null) ? null : $kept->learned_at,
                    'starred_at' => $kept->starred_at ?? $twins->pluck('starred_at')->filter()->first(),
                ]);

                array_push($doomed, ...$twins->where('id', '!=', $kept->id)->pluck('id')->all());
            });

        $mistakeSentences = $entries->where('kind', 'mistake')
            ->map(fn (object $entry) => $entry->learner_id.'|'.FeedbackNotebook::sentenceKey($entry->original))
            ->flip();

        array_push($doomed, ...$entries->where('kind', 'tip')
            ->filter(fn (object $entry) => $mistakeSentences->has($entry->learner_id.'|'.FeedbackNotebook::sentenceKey($entry->original)))
            ->pluck('id')
            ->all());

        DB::table('notebook_entries')->whereIn('id', array_unique($doomed))->delete();
    }

    public function down(): void
    {
        // The twins are gone for good; nothing to put back.
    }
};
