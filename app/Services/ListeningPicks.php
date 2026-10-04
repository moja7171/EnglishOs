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
 * A pick can also exist inside the app (`local`): its audio and its synced,
 * speaker-labelled text live in document/{code}/picks/ (see
 * listening:build-picks), listed in that folder's index.json by day and
 * level. Such a pick plays on the /listening page itself, with the text
 * advancing as it plays, like the missions' own listenings.
 *
 * @phpstan-type Pick array{src: string, title: string, by: ?string, url: string, min: ?int, tx: ?string, txUrl: ?string, apple: bool, grammar: bool, local: ?array{slug: string, audioUrl: string}}
 */
class ListeningPicks
{
    /**
     * How each pick's `src` is shown to the learner.
     *
     * @var array<string, string>
     */
    public const SOURCES = [
        'lee' => 'BBC · Real Easy English',
        'sme' => 'BBC · 6 Minute English',
        'voa' => 'VOA Learning English',
        'teded' => 'TED-Ed',
        'talk' => 'TED Talk',
        'lk' => 'NPR · Life Kit',
    ];

    /**
     * The three picks of a day, in the order they appear (easiest first).
     *
     * @var list<string>
     */
    public const LEVELS = ['Lighter', 'Medium', 'Challenging'];

    /** @var array<string, list<list<array<string, mixed>>>>|null */
    private static ?array $picks = null;

    /** @var array<string, list<list<array{slug: string}>>> */
    private array $localIndexes = [];

    /**
     * @param  string|null  $documentPath  Where the missions' source files live (document/); a test points it at a temporary folder.
     */
    public function __construct(private readonly ?string $documentPath = null) {}

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

        return array_map(fn (array $pick, int $level) => $pick + [
            'by' => null, 'min' => null, 'tx' => null, 'txUrl' => null, 'apple' => false, 'grammar' => false,
            'local' => $this->localPick($missionCode, $dayNumber, $level),
        ], $trio, array_keys($trio));
    }

    /**
     * The file of an in-app pick's audio, or null if that mission has no
     * such pick or its file isn't there. Only a slug listed in the
     * mission's index.json is ever turned into a path.
     */
    public function audioPath(string $missionCode, string $slug): ?string
    {
        $path = $this->slugIsListed($missionCode, $slug)
            ? "{$this->picksFolder($missionCode)}/{$slug}.mp3"
            : null;

        return $path !== null && is_file($path) ? $path : null;
    }

    /**
     * The synced text of an in-app pick — chunks of what's being said with
     * their start/end seconds, tagged with the speaker when the pick's
     * transcript had speakers. [] if there is none.
     *
     * @return list<array{text: string, start: float, end: float, speaker?: string}>
     */
    public function segmentsFor(string $missionCode, string $slug): array
    {
        $path = $this->slugIsListed($missionCode, $slug) ? "{$this->picksFolder($missionCode)}/{$slug}.turns.json" : null;

        if ($path === null || ! is_file($path)) {
            return [];
        }

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['segments'] ?? [];
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
     * @return array{slug: string, audioUrl: string}|null
     */
    private function localPick(string $missionCode, int $dayNumber, int $level): ?array
    {
        $slug = $this->localIndex($missionCode)[$dayNumber - 1][$level]['slug'] ?? null;

        if ($slug === null || $this->audioPath($missionCode, $slug) === null) {
            return null;
        }

        return ['slug' => $slug, 'audioUrl' => route('listening.audio', [$missionCode, $slug])];
    }

    private function slugIsListed(string $missionCode, string $slug): bool
    {
        foreach ($this->localIndex($missionCode) as $day) {
            if (in_array($slug, array_column($day, 'slug'), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<array{slug: string}>>
     */
    private function localIndex(string $missionCode): array
    {
        if (preg_match('/^M\d{2}$/', $missionCode) !== 1) {
            return [];
        }

        $path = "{$this->picksFolder($missionCode)}/index.json";

        return $this->localIndexes[$missionCode] ??= is_file($path)
            ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)
            : [];
    }

    private function picksFolder(string $missionCode): string
    {
        return ($this->documentPath ?? base_path('document'))."/{$missionCode}/picks";
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
