<?php

namespace App\Services;

use App\Models\ExtraEpisode;
use App\Models\User;

/**
 * The learner's courses: standalone studies that sit outside the main
 * M01-M24 path and can be opened any time (Vocab in Use first; Grammar in
 * Use and video courses are expected later). Each course is one card on
 * the Courses page and is counted on its own: nothing here feeds the
 * streak, the daily ring, the reminders or the Review tab. Vocab in Use
 * and Extra English exist so far; a later course adds its entry here.
 */
class Courses
{
    public function __construct(private VocabCourse $vocab) {}

    /**
     * A course without progress of its own (Extra English: every episode is
     * just open) has a null progress and only a label.
     *
     * @return list<array{key: string, name: string, blurb: string, icon: string, url: string, progress: int|null, progressLabel: string, dueCards: int}>
     */
    public function for(User $user): array
    {
        $total = max(1, $this->vocab->totalUnits());
        $opened = $this->vocab->openedUnits($user);

        return [
            [
                'key' => VocabCourse::KEY,
                'name' => VocabCourse::NAME,
                'blurb' => VocabCourse::BLURB,
                'icon' => 'heroicon-o-book-open',
                'url' => route('courses.vocab'),
                'progress' => (int) round($opened / $total * 100),
                'progressLabel' => "{$opened} of {$total} units opened",
                'dueCards' => $this->vocab->dueCards($user),
            ],
            [
                'key' => 'extra',
                'name' => 'Extra English',
                'blurb' => 'The sitcom, episode by episode, with English captions',
                'icon' => 'heroicon-o-film',
                'url' => route('courses.extra'),
                'progress' => null,
                'progressLabel' => ($episodes = ExtraEpisode::query()->count()).' '.str('episode')->plural($episodes),
                'dueCards' => 0,
            ],
        ];
    }

    /**
     * The one line under the mission banner on Home: where Courses is and,
     * when something is due in a course, how many cards.
     */
    public function homeLine(User $user): string
    {
        $courses = $this->for($user);
        $names = collect($courses)->pluck('name')->implode(' · ');
        $due = collect($courses)->sum('dueCards');

        return $due > 0 ? "{$names} · {$due} ".str('card')->plural($due).' due' : $names;
    }
}
