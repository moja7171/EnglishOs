<?php

namespace App\Services;

/**
 * The daily listening picks: for every program day (24 missions × 4 days),
 * three episodes of rising difficulty that the learner can listen to
 * outside the app — "at least one, all three if you like". The picks
 * themselves live in resources/data/listening-picks.json, not in code, so
 * they can be edited without touching any logic.
 *
 * Every pick was checked against its source (official podcast feeds, the
 * site's own index, or an HTTP check on the final link). `tx` says where
 * the transcript is: "page" = on the episode's own page, "link" = a
 * separate transcript page at `txUrl`, absent = none promised (TED-Ed
 * videos, and Apple Podcasts links where the BBC page link was missing).
 *
 * @phpstan-type Pick array{src: string, title: string, by: ?string, url: string, min: ?int, tx: ?string, txUrl: ?string, apple: bool, grammar: bool}
 */
class ListeningPicks
{
    /** @var array<string, list<list<array<string, mixed>>>>|null */
    private static ?array $picks = null;

    /**
     * The three picks for one program day, easiest first — or null for a
     * day that doesn't exist (unknown mission code, day outside 1-4).
     *
     * @return list<Pick>|null
     */
    public function forDay(string $missionCode, int $dayNumber): ?array
    {
        $trio = $this->all()[$missionCode][$dayNumber - 1] ?? null;

        if ($trio === null) {
            return null;
        }

        return array_map(fn (array $pick) => $pick + [
            'by' => null, 'min' => null, 'tx' => null, 'txUrl' => null, 'apple' => false, 'grammar' => false,
        ], $trio);
    }

    /**
     * Which program day "today" is for listening purposes, read off the
     * planner's own "today" block (see ProgramPlanner::plan()): the open
     * mission's current day, or day 1 of the next mission between
     * missions. Null once all 24 missions are done.
     *
     * @param  array<string, mixed>  $today  ProgramPlanner::plan()['today']
     * @return array{missionCode: string, dayNumber: int}|null
     */
    public function dayFor(array $today): ?array
    {
        $missionCode = match ($today['kind']) {
            'mission_day' => $today['mission']->code,
            'checkpoint', 'start_next' => $today['nextMissionCode'],
            default => null,
        };

        if ($missionCode === null) {
            return null;
        }

        return ['missionCode' => $missionCode, 'dayNumber' => (int) ($today['dayNumber'] ?? 1)];
    }

    /**
     * Position of a day in the 96-day program, 0-based — used to compare
     * days ("is this one past today?"), nothing else.
     */
    public function indexOf(string $missionCode, int $dayNumber): int
    {
        return ((int) substr($missionCode, 1) - 1) * 4 + ($dayNumber - 1);
    }

    /**
     * @return array<string, list<list<array<string, mixed>>>>
     */
    private function all(): array
    {
        return self::$picks ??= json_decode(
            (string) file_get_contents(resource_path('data/listening-picks.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }
}
