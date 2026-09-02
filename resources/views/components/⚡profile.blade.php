<?php

use App\Models\ErrorLogItem;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $cefr_level = 'B1';

    public string $target_band = '';

    public string $gender = 'unspecified';

    /** '' means "no goal" — wire:model on a <select> needs a string, not null. */
    public string $weeklyGoalDays = '';

    public bool $weeklyGoalSaved = false;

    public ?UploadedFile $newAvatar = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public bool $basicInfoSaved = false;

    public bool $passwordSaved = false;

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->cefr_level = $user->cefr_level ?? 'B1';
        $this->target_band = $user->target_band ?? '';
        $this->gender = $user->gender ?? 'unspecified';
        $this->weeklyGoalDays = $user->weekly_goal_days ? (string) $user->weekly_goal_days : '';
    }

    /**
     * Everything here is already computed elsewhere in the app
     * (streak/missions on Friends' list, top recurring error on Active
     * Recall and Mission Result) but never shown to the learner
     * themselves in one place — this tab is purely a mirror onto data
     * that already exists, no new tracking added.
     *
     * @return array{currentStreak: int, longestStreak: int, missionsCompleted: int, vocabularyCount: int, topError: ?ErrorLogItem, calendar: list<array{date: string, label: string, active: bool, future: bool}>, activeDaysThisWeek: int}
     */
    #[Computed]
    public function progressStats(): array
    {
        $user = auth()->user();

        return [
            'currentStreak' => $user->currentStreak(),
            'longestStreak' => $user->longestStreak(),
            'missionsCompleted' => $user->missionsCompletedCount(),
            'vocabularyCount' => $user->vocabularyWordsSelected()->count(),
            'topError' => $user->topRecurringError(),
            'calendar' => $user->activityCalendar(),
            'activeDaysThisWeek' => $user->activeDaysThisWeek(),
        ];
    }

    /**
     * A plain manual check, not $this->validate() — the <select> only ever
     * offers "no goal" (empty string) or 1-7, and Laravel's "nullable"
     * rule only skips other rules for a genuine null, not an empty
     * string, so "nullable|in:1..7" would reject the very value the UI's
     * own empty option submits.
     */
    public function updateWeeklyGoal(): void
    {
        $this->weeklyGoalSaved = false;

        if ($this->weeklyGoalDays !== '' && ! in_array($this->weeklyGoalDays, ['1', '2', '3', '4', '5', '6', '7'], true)) {
            return;
        }

        auth()->user()->update(['weekly_goal_days' => $this->weeklyGoalDays !== '' ? (int) $this->weeklyGoalDays : null]);

        unset($this->progressStats);
        $this->weeklyGoalSaved = true;
    }

    public function updateBasicInfo(): void
    {
        $this->basicInfoSaved = false;

        $data = $this->validate([
            'name' => 'required|string|max:255',
            'cefr_level' => 'required|in:A1,A2,B1,B2,C1',
            'target_band' => 'nullable|string|max:10',
            'gender' => 'required|in:male,female,unspecified',
        ]);

        $user = auth()->user();
        $updates = [
            'name' => $data['name'],
            'cefr_level' => $data['cefr_level'],
            'target_band' => $data['target_band'] ?: null,
            'gender' => $data['gender'],
        ];

        // A starting suggestion, applied only the very first time a real
        // gender is set on an avatar nobody has customized yet (still on
        // the plain "initial" style, no photo) — never overwrites a style
        // or photo the learner already deliberately chose.
        if ($data['gender'] !== $user->gender && $user->avatar_style === 'initial' && ! $user->avatar_path) {
            $updates['avatar_style'] = User::defaultAvatarStyleForGender($data['gender']);
        }

        $user->update($updates);

        $this->basicInfoSaved = true;
    }

    /**
     * Picking a color retints whichever avatar mode (illustrated style or
     * plain initial) is already active — it never changes which one that
     * is. It does clear any uploaded photo, since a color choice only
     * ever applies to the non-photo modes.
     */
    public function selectColor(string $color): void
    {
        if (! array_key_exists($color, User::avatarColorPalette())) {
            return;
        }

        $this->clearStoredAvatarPhoto();

        auth()->user()->update(['avatar_color' => $color, 'avatar_path' => null]);
    }

    /**
     * Same idea as selectColor(), the other way round: picking a style
     * keeps whatever color is already chosen and only clears an uploaded
     * photo, since style and photo are the two mutually exclusive "what
     * shape is the avatar" choices.
     */
    public function selectAvatarStyle(string $style): void
    {
        if (! array_key_exists($style, User::avatarStyleOptions())) {
            return;
        }

        $this->clearStoredAvatarPhoto();

        auth()->user()->update(['avatar_style' => $style, 'avatar_path' => null]);
    }

    public function saveAvatar(): void
    {
        $this->validate([
            'newAvatar' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
        ]);

        try {
            $path = $this->processAvatarUpload($this->newAvatar);
        } catch (Throwable) {
            $this->addError('newAvatar', "That image couldn't be processed — please try a different photo.");

            return;
        }

        auth()->user()->update(['avatar_path' => $path]);
        $this->newAvatar = null;
    }

    public function removeAvatar(): void
    {
        $this->clearStoredAvatarPhoto();

        auth()->user()->update(['avatar_path' => null]);
    }

    public function toggleDiscoverable(): void
    {
        $user = auth()->user();

        $user->update(['discoverable' => ! $user->discoverable]);
    }

    public function updatePassword(): void
    {
        $this->passwordSaved = false;

        $data = $this->validate([
            'currentPassword' => ['required', 'current_password'],
            'newPassword' => ['required', 'string', 'min:8', 'confirmed'],
        ], [], [
            'currentPassword' => 'current password',
            'newPassword' => 'new password',
        ]);

        auth()->user()->update(['password' => $data['newPassword']]);

        $this->currentPassword = '';
        $this->newPassword = '';
        $this->newPassword_confirmation = '';
        $this->passwordSaved = true;
    }

    /**
     * Always the same fixed filename per learner (see the migration and
     * processAvatarUpload()) — an upload overwrites, a removal deletes,
     * neither ever leaves an orphaned file behind on the public disk.
     */
    private function clearStoredAvatarPhoto(): void
    {
        $path = auth()->user()->avatar_path;

        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * Center-crops to a square and resizes down to a fixed 512px JPEG via
     * plain GD (already a PHP extension here — no Composer package, same
     * "no new dependency for something the platform already gives us"
     * instinct as the curated emoji list and the self-hosted dot-grid
     * wallpaper). Always the same path per learner, so re-uploading is a
     * plain overwrite.
     */
    private function processAvatarUpload(UploadedFile $file): string
    {
        $source = match ($file->getMimeType()) {
            'image/jpeg' => imagecreatefromjpeg($file->getRealPath()),
            'image/png' => imagecreatefrompng($file->getRealPath()),
            'image/webp' => imagecreatefromwebp($file->getRealPath()),
            default => throw new RuntimeException('Unsupported image type.'),
        };

        if ($source === false) {
            throw new RuntimeException('Could not read the uploaded image.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $edge = min($width, $height);

        $target = 512;
        $square = imagecreatetruecolor($target, $target);
        imagecopyresampled(
            $square, $source,
            0, 0, intdiv($width - $edge, 2), intdiv($height - $edge, 2),
            $target, $target, $edge, $edge,
        );
        imagedestroy($source);

        ob_start();
        imagejpeg($square, null, 85);
        $data = ob_get_clean();
        imagedestroy($square);

        $path = 'avatars/'.auth()->id().'.jpg';
        Storage::disk('public')->put($path, $data);

        return $path;
    }
};
?>

<div class="mx-auto max-w-2xl space-y-6 p-4 sm:p-6" x-data="{ activeTab: 'avatar' }">
    <a href="{{ route('home') }}" class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint transition-colors hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
            @svg('heroicon-o-user-circle', 'h-5 w-5')
        </span>
        <div>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Profile &amp; settings</h1>
            <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">Your photo, your level, and who can find you.</p>
        </div>
    </header>

    <x-tab-nav
        tab-var="activeTab"
        :tabs="[
            'avatar' => 'Avatar',
            'basic-info' => 'Basic info',
            'progress' => 'My progress',
            'privacy' => 'Privacy',
            'password' => 'Password',
        ]"
    />

    {{-- Avatar --}}
    <div x-show="activeTab === 'avatar'" x-cloak class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <div class="flex items-center gap-4">
            <x-user-avatar :user="auth()->user()" class="h-16 w-16 text-xl" />
            <div>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Avatar</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Pick a style and a color, or upload your own photo.</p>
            </div>
        </div>

        <div>
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Style</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach (User::avatarStyleOptions() as $key => $label)
                    @php $selected = ! auth()->user()->avatar_path && auth()->user()->avatar_style === $key; @endphp
                    <button
                        type="button"
                        wire:click="selectAvatarStyle('{{ $key }}')"
                        title="{{ $label }}"
                        class="rounded-full p-0.5 transition-colors {{ $selected ? 'ring-2 ring-accent dark:ring-accent-dark' : 'ring-2 ring-transparent' }}"
                    >
                        @if ($key === 'initial')
                            <x-avatar-initial :name="auth()->user()->name" :color="auth()->user()->avatar_color" class="h-9 w-9 cursor-pointer text-xs" />
                        @else
                            <x-illustrated-avatar :style="$key" :color="auth()->user()->avatar_color" class="h-9 w-9 cursor-pointer" />
                        @endif
                    </button>
                @endforeach
            </div>
        </div>

        <div class="border-t border-line pt-3 dark:border-line-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Colors</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach (User::avatarColorPalette() as $key => $classes)
                    @php $selected = ! auth()->user()->avatar_path && auth()->user()->avatar_color === $key; @endphp
                    <button
                        type="button"
                        wire:click="selectColor('{{ $key }}')"
                        title="{{ ucfirst($key) }}"
                        class="rounded-full p-0.5 transition-colors {{ $selected ? 'ring-2 ring-accent dark:ring-accent-dark' : 'ring-2 ring-transparent' }}"
                    >
                        <x-avatar-initial :name="auth()->user()->name" :color="$key" class="h-9 w-9 text-xs cursor-pointer" />
                    </button>
                @endforeach
            </div>
        </div>

        <div class="border-t border-line pt-3 dark:border-line-dark">
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Custom photo</p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    @svg('heroicon-o-photo', 'h-3.5 w-3.5')
                    <span wire:loading.remove wire:target="newAvatar">Choose a photo</span>
                    <span wire:loading wire:target="newAvatar">Uploading…</span>
                    <input type="file" wire:model="newAvatar" accept="image/png,image/jpeg,image/webp" class="hidden">
                </label>

                @if ($newAvatar)
                    <button
                        type="button"
                        wire:click="saveAvatar"
                        wire:loading.attr="disabled"
                        class="cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >Save photo</button>
                @endif

                @if (auth()->user()->avatar_path)
                    <button
                        type="button"
                        wire:click="removeAvatar"
                        wire:confirm="Remove your photo and go back to a color avatar?"
                        class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-red-600 dark:text-ink-faint-dark"
                    >Remove photo</button>
                @endif
            </div>
            @error('newAvatar')
                <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    {{-- Basic info --}}
    <form wire:submit="updateBasicInfo" x-show="activeTab === 'basic-info'" x-cloak class="space-y-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Basic info</p>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Name</label>
            <input
                type="text"
                wire:model="name"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">How's your English right now?</label>
            <select
                wire:model="cefr_level"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
                @foreach (User::levelOptions() as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('cefr_level')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Target IELTS band <span class="normal-case text-ink-faint dark:text-ink-faint-dark">(optional)</span></label>
            <select
                wire:model="target_band"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
                <option value="">Not sure yet</option>
                @foreach (User::targetBandOptions() as $band)
                    <option value="{{ $band }}">{{ $band }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Gender <span class="normal-case text-ink-faint dark:text-ink-faint-dark">(optional — only used to suggest a starting avatar style)</span></label>
            <select
                wire:model="gender"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
                @foreach (User::genderOptions() as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button
                type="submit"
                class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >Save</button>
            @if ($basicInfoSaved)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                </span>
            @endif
        </div>
    </form>

    {{-- My progress --}}
    <div x-show="activeTab === 'progress'" x-cloak class="space-y-4 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">My progress</p>

        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                    @svg('heroicon-s-fire', 'h-3.5 w-3.5') Current streak
                </p>
                <p class="mt-1 text-2xl font-extrabold text-accent-ink dark:text-accent-ink-dark">{{ $this->progressStats['currentStreak'] }}</p>
            </div>
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                    @svg('heroicon-o-trophy', 'h-3.5 w-3.5') Longest streak
                </p>
                <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['longestStreak'] }}</p>
            </div>
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                    @svg('heroicon-o-check-badge', 'h-3.5 w-3.5') Missions completed
                </p>
                <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['missionsCompleted'] }}</p>
            </div>
            <div class="rounded-xl border border-line p-3 dark:border-line-dark">
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">
                    @svg('heroicon-o-book-open', 'h-3.5 w-3.5') Words learned
                </p>
                <p class="mt-1 text-2xl font-extrabold text-ink dark:text-ink-dark">{{ $this->progressStats['vocabularyCount'] }}</p>
            </div>
        </div>

        <div class="rounded-xl border border-line p-3 dark:border-line-dark">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">Weekly goal</p>
                    @if (auth()->user()->weekly_goal_days)
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">{{ $this->progressStats['activeDaysThisWeek'] }} of {{ auth()->user()->weekly_goal_days }} days this week</p>
                    @else
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Set a goal to track your week here.</p>
                    @endif
                </div>
                <form wire:submit="updateWeeklyGoal" class="flex items-center gap-2">
                    <select
                        wire:model="weeklyGoalDays"
                        class="rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
                    >
                        <option value="">No goal</option>
                        @foreach (range(1, 7) as $n)
                            <option value="{{ $n }}">{{ $n }} {{ Str::plural('day', $n) }}/week</option>
                        @endforeach
                    </select>
                    <button
                        type="submit"
                        class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >Save</button>
                </form>
            </div>

            @if (auth()->user()->weekly_goal_days)
                <div class="mt-2">
                    <x-progress-bar>
                        <div
                            class="h-full rounded-full transition-all duration-300 {{ $this->progressStats['activeDaysThisWeek'] >= auth()->user()->weekly_goal_days ? 'bg-success dark:bg-success-dark' : 'bg-accent dark:bg-accent-dark' }}"
                            style="width: {{ min($this->progressStats['activeDaysThisWeek'] / auth()->user()->weekly_goal_days, 1) * 100 }}%"
                        ></div>
                    </x-progress-bar>
                </div>
            @endif

            @if ($weeklyGoalSaved)
                <p class="mt-1.5 inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                </p>
            @endif
        </div>

        <div class="rounded-xl border border-line p-3 dark:border-line-dark">
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Activity</p>
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Last 12 weeks</p>
            <div class="mt-2 overflow-x-auto">
                <div class="grid w-fit grid-flow-col grid-rows-7 gap-1">
                    @foreach ($this->progressStats['calendar'] as $day)
                        <span
                            title="{{ $day['label'] }}{{ $day['active'] ? ' — practiced' : '' }}"
                            class="h-3 w-3 rounded-sm {{ $day['future']
                                ? 'bg-transparent'
                                : ($day['active']
                                    ? 'bg-accent dark:bg-accent-dark'
                                    : 'bg-surface-sunken dark:bg-surface-sunken-dark') }}"
                        ></span>
                    @endforeach
                </div>
            </div>
            <div class="mt-2 flex items-center gap-1.5 text-[11px] text-ink-faint dark:text-ink-faint-dark">
                <span class="h-2.5 w-2.5 shrink-0 rounded-sm bg-surface-sunken dark:bg-surface-sunken-dark"></span>
                No activity
                <span class="ml-2 h-2.5 w-2.5 shrink-0 rounded-sm bg-accent dark:bg-accent-dark"></span>
                Practiced
            </div>
        </div>

        @if ($topError = $this->progressStats['topError'])
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
                <p class="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 uppercase dark:text-amber-400">
                    @svg('heroicon-o-arrow-path', 'h-3.5 w-3.5') Your most recurring mistake
                </p>
                <p class="mt-1 text-sm text-ink dark:text-ink-dark">
                    <span class="text-red-600 line-through decoration-red-500">{{ $topError->error }}</span>
                    <span class="text-success dark:text-success-dark">{{ $topError->correction }}</span>
                </p>
                <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">This has come up across more than one mission — Active Recall keeps bringing it back for extra practice.</p>
            </div>
        @else
            <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Complete 2+ missions and any pattern in your mistakes will show up here.</p>
        @endif
    </div>

    {{-- Privacy --}}
    <div x-show="activeTab === 'privacy'" x-cloak class="flex items-center justify-between gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <div>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Discoverable in Friends search</p>
            <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Turn this off and new people won't find you by name — anyone you're already connected with is unaffected.</p>
        </div>
        <button
            type="button"
            wire:click="toggleDiscoverable"
            role="switch"
            aria-checked="{{ auth()->user()->discoverable ? 'true' : 'false' }}"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors {{ auth()->user()->discoverable ? 'bg-accent dark:bg-accent-dark' : 'bg-surface-sunken dark:bg-surface-sunken-dark' }}"
        >
            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform {{ auth()->user()->discoverable ? 'translate-x-6' : 'translate-x-1' }}"></span>
        </button>
    </div>

    {{-- Password --}}
    <form wire:submit="updatePassword" x-show="activeTab === 'password'" x-cloak class="space-y-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Change password</p>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Current password</label>
            <input
                type="password"
                wire:model="currentPassword"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('currentPassword')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">New password</label>
            <input
                type="password"
                wire:model="newPassword"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('newPassword')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Confirm new password</label>
            <input
                type="password"
                wire:model="newPassword_confirmation"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button
                type="submit"
                class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
            >Update password</button>
            @if ($passwordSaved)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Updated
                </span>
            @endif
        </div>
    </form>
</div>
