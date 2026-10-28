<?php

use App\Models\User;
use App\Services\AiModelChain;
use App\Services\GeminiClient;
use App\Services\GroqClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public string $name = '';

    public string $cefr_level = 'A2+';

    public string $target_band = '';

    public string $gender = 'unspecified';

    public ?UploadedFile $newAvatar = null;

    public string $currentPassword = '';

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public bool $basicInfoSaved = false;

    public bool $passwordSaved = false;

    /**
     * What the learner typed into each provider's key box, keyed by
     * provider ('gemini' / 'groq') — cleared once saved.
     *
     * @var array{gemini: string, groq: string}
     */
    public array $aiKeyInput = ['gemini' => '', 'groq' => ''];

    /**
     * The last check's result per provider: 'ok', 'rejected', or
     * 'unknown' (the check itself couldn't reach the provider).
     *
     * @var array<string, string>
     */
    public array $aiKeyStatus = [];

    public function mount(): void
    {
        $user = auth()->user();

        $this->name = $user->name;
        $this->cefr_level = $user->cefr_level ?? 'A2+';
        $this->target_band = $user->target_band ?? '';
        $this->gender = $user->gender ?? 'unspecified';
    }

    public function updateBasicInfo(): void
    {
        $this->basicInfoSaved = false;

        $data = $this->validate([
            'name' => 'required|string|max:255',
            'cefr_level' => 'required|in:A1,A2,A2+,B1,B2,C1',
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

        $this->clearStoredAvatarPhoto();
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

    /** Reminders › Friends: news about friends' days (see FriendAlerts). */
    public function toggleFriendAlerts(): void
    {
        $user = auth()->user();

        $user->update(['friend_alerts' => ! $user->friend_alerts]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    #[Computed]
    public function blockedUsers()
    {
        return auth()->user()->blockedUsers();
    }

    /**
     * Unblocking only lifts the block — blocking removed the follows, so
     * they're back to being strangers and have to be re-added.
     */
    public function unblock(int $userId): void
    {
        auth()->user()->unblock(User::findOrFail($userId));

        unset($this->blockedUsers);
    }

    /**
     * Admin-only, read-only: every AI model chain with each model's place in
     * the order, whether the app is currently skipping it (and why/until
     * when) and today's success/failure counts — failures used to be
     * invisible. A model skipped as "retired" means the configured list is
     * stale. Shown in the "AI Relay" tab.
     *
     * @return array<string, array{rows: list<array<string, mixed>>, available: int, todayCalls: int}>
     */
    #[Computed]
    public function aiChains(): array
    {
        abort_unless(auth()->user()->is_admin, 403);

        $chains = new AiModelChain;
        $gemini = new GeminiClient;

        $rowsByTitle = [
            'Gemini — chat (Sage, conversations)' => $chains->status('gemini', $gemini->configuredChain(GeminiClient::PROFILE_CHAT)),
            'Gemini — judge (grading the learner)' => $chains->status('gemini', $gemini->configuredChain(GeminiClient::PROFILE_JUDGE)),
            'Groq — Whisper (speech to text)' => $chains->status('groq', (new GroqClient)->configuredChain()),
        ];

        return array_map(fn (array $rows) => [
            'rows' => $rows,
            'available' => count(array_filter($rows, fn (array $row) => $row['available'])),
            'todayCalls' => array_sum(array_map(fn (array $row) => $row['today']['ok'], $rows)),
        ], $rowsByTitle);
    }

    /**
     * Admin-only (see the "AI Relay" tab, hidden for everyone else).
     * Switches config('services.ai_proxy.target') by rewriting
     * AI_PROXY_TARGET in .env — see App\Console\Commands\AiRelayUse. A
     * full-page redirect, not a Livewire one, so the next request re-reads
     * .env fresh instead of showing the target this request already booted
     * with.
     */
    public function switchAiRelay(string $target): void
    {
        abort_unless(auth()->user()->is_admin, 403);

        if (! in_array($target, ['vps', 'local'], true)) {
            return;
        }

        Artisan::call('ai:relay-use', ['target' => $target]);

        $this->redirect(route('profile'), navigate: false);
    }

    /**
     * Checks the typed key with the provider before saving it — a key the
     * provider rejects is never saved. When the check itself can't reach
     * the provider, the key is saved anyway (a wrong one only costs a
     * silent fallback to the app's key).
     */
    public function saveAiKey(string $provider): void
    {
        $column = $this->aiKeyColumn($provider);
        $key = trim($this->aiKeyInput[$provider] ?? '');

        $this->resetErrorBag("aiKeyInput.{$provider}");

        if ($key === '' || strlen($key) > 200 || preg_match('/\s/', $key)) {
            $this->addError("aiKeyInput.{$provider}", 'Paste the whole key, with no spaces.');

            return;
        }

        $works = $this->checkAiKey($provider, $key);

        if ($works === false) {
            $this->aiKeyStatus[$provider] = 'rejected';
            $this->addError("aiKeyInput.{$provider}", "This key didn't work. Check it and try again.");

            return;
        }

        auth()->user()->forceFill([$column => $key])->save();

        $this->aiKeyInput[$provider] = '';
        $this->aiKeyStatus[$provider] = $works ? 'ok' : 'unknown';
    }

    public function testAiKey(string $provider): void
    {
        $key = $this->savedAiKey($provider);

        if ($key === null) {
            return;
        }

        $works = $this->checkAiKey($provider, $key);

        $this->aiKeyStatus[$provider] = match ($works) {
            true => 'ok',
            false => 'rejected',
            null => 'unknown',
        };
    }

    public function removeAiKey(string $provider): void
    {
        auth()->user()->forceFill([$this->aiKeyColumn($provider) => null])->save();

        unset($this->aiKeyStatus[$provider]);
    }

    /**
     * The saved key's last four characters, for "•••• abcd" — null when no
     * key is saved.
     */
    public function savedAiKeyEnding(string $provider): ?string
    {
        $key = $this->savedAiKey($provider);

        return $key === null ? null : substr($key, -4);
    }

    private function savedAiKey(string $provider): ?string
    {
        $key = rescue(fn () => auth()->user()->{$this->aiKeyColumn($provider)}, report: false);

        return filled($key) ? $key : null;
    }

    private function checkAiKey(string $provider, string $key): ?bool
    {
        return $provider === 'gemini' ? GeminiClient::keyWorks($key) : GroqClient::keyWorks($key);
    }

    private function aiKeyColumn(string $provider): string
    {
        return match ($provider) {
            'gemini' => 'gemini_api_key',
            'groq' => 'groq_api_key',
            default => abort(404),
        };
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
     * Deletes the learner's current photo file — called before a new
     * upload is saved (each upload has its own file name, see
     * processAvatarUpload()) and on removal, so no orphaned file is ever
     * left behind on the public disk.
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
     * wallpaper). A fresh file name every upload, so the new photo gets a
     * new URL and no browser keeps showing the cached old one; the caller
     * deletes the previous file.
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

        $path = 'avatars/'.auth()->id().'-'.Str::lower(Str::random(8)).'.jpg';
        Storage::disk('public')->put($path, $data);

        return $path;
    }
};
?>

@php
    $user = auth()->user();
    $row = 'flex w-full cursor-pointer items-center gap-3 py-3 text-start text-sm font-bold text-ink dark:text-ink-dark';
    $rowIcon = 'inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-surface-sunken text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark';
    $group = 'card divide-y divide-line rounded-2xl px-4 py-0 dark:divide-line-dark';
    $groupTitle = 'px-4 pt-2 text-[11px] font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark';
    $hint = 'text-xs font-semibold text-ink-faint dark:text-ink-faint-dark';
    $chevron = 'heroicon-o-chevron-right';
    $studyTime = $user->study_reminder_time_1 ? substr($user->study_reminder_time_1, 0, 5) : null;
    $tabs = ['basic-info' => 'info', 'password' => 'password', 'reminders' => 'reminders', 'privacy' => 'blocked', 'ai-keys' => 'ai-keys', 'ai-relay' => 'ai-relay'];
@endphp

{{-- Like a phone's settings: the avatar (tap = avatar sheet), then short
     groups of rows. Switches work in place; every › row opens its own small
     screen with ‹ back. ?tab= still opens a screen directly. --}}
<div
    class="mx-auto max-w-2xl p-4 sm:p-6"
    x-data="{ screen: @js($tabs)[new URLSearchParams(window.location.search).get('tab')] ?? '', avatarSheet: false }"
    x-back-close="{ open: screen !== '', close: () => screen = '' }"
>
    {{-- Main list --}}
    <div x-show="screen === ''" class="stagger-children space-y-3">
        <header class="flex items-center gap-2">
            <a href="{{ route('home') }}" wire:navigate aria-label="Back" class="-ms-2 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
            <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Profile</h1>
        </header>

        <div class="flex flex-col items-center gap-1.5 pb-2">
            <button type="button" x-on:click="avatarSheet = true" aria-label="Change your avatar" class="relative cursor-pointer">
                <x-user-avatar :user="$user" class="h-20 w-20 text-2xl" />
                <span class="absolute -end-0.5 -bottom-0.5 inline-flex h-7 w-7 items-center justify-center rounded-full bg-surface text-ink-soft shadow dark:bg-surface-dark dark:text-ink-soft-dark">@svg('heroicon-s-pencil', 'h-3.5 w-3.5')</span>
            </button>
            <p class="text-lg font-extrabold text-ink dark:text-ink-dark">{{ $user->name }}</p>
            <span class="rounded-full bg-accent-soft px-2.5 py-0.5 text-xs font-bold text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">{{ $user->cefr_level ?? 'A2+' }}@if ($user->target_band) · IELTS {{ $user->target_band }}@endif</span>
        </div>

        @php
            $plan = app(\App\Services\ProgramPlanner::class)->plan($user);
            $programPercent = round(100 * $plan['programDay'] / $plan['totalDays']);
        @endphp
        <a href="{{ route('progress.index') }}" wire:navigate class="card flex items-center gap-3 p-3">
            <span class="relative inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full" style="background: conic-gradient(var(--color-accent) {{ $programPercent }}%, var(--color-line) 0)">
                <span class="absolute inset-1 rounded-full bg-surface dark:bg-surface-dark"></span>
                @svg('heroicon-o-chart-bar', 'relative h-5 w-5 text-ink-soft dark:text-ink-soft-dark')
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-extrabold text-ink dark:text-ink-dark">Progress</span>
                <span class="block text-xs font-semibold text-ink-faint dark:text-ink-faint-dark">Day {{ $plan['programDay'] }} of {{ $plan['totalDays'] }}</span>
            </span>
            @svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')
        </a>

        <p class="{{ $groupTitle }}">Account</p>
        <div class="{{ $group }}">
            <button type="button" x-on:click="screen = 'info'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-user', 'h-4 w-4')</span><span class="flex-1">Name &amp; level</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
            <button type="button" x-on:click="screen = 'password'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-key', 'h-4 w-4')</span><span class="flex-1">Password</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
        </div>

        {{-- The theme and sound live in the browser (window.eosTheme,
             localStorage), not on the account, so these are Alpine-only. --}}
        <p class="{{ $groupTitle }}">App</p>
        <div
            class="{{ $group }}"
            x-data="{ dark: window.eosTheme.dark, soundEnabled: true }"
            x-init="soundEnabled = (() => { try { return localStorage.getItem('eosSoundEnabled') !== 'false' } catch (e) { return true } })()"
        >
            <button type="button" x-on:click="screen = 'reminders'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-bell', 'h-4 w-4')</span><span class="flex-1">Reminders</span>@if ($studyTime)<span class="{{ $hint }}">{{ $studyTime }}</span>@endif @svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
            <div class="{{ $row }} cursor-default">
                <span class="{{ $rowIcon }}">@svg('heroicon-o-moon', 'h-4 w-4')</span>
                <span class="flex-1">Dark mode</span>
                <button
                    type="button"
                    role="switch"
                    aria-label="Dark mode"
                    x-bind:aria-checked="dark ? 'true' : 'false'"
                    x-on:click="dark = ! dark; window.eosTheme.set(dark)"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors"
                    x-bind:class="dark ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark'"
                ><span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform" x-bind:class="dark ? 'translate-x-6' : 'translate-x-1'"></span></button>
            </div>
            <div class="{{ $row }} cursor-default">
                <span class="{{ $rowIcon }}">@svg('heroicon-o-speaker-wave', 'h-4 w-4')</span>
                <span class="flex-1">Sound</span>
                <button
                    type="button"
                    role="switch"
                    aria-label="Sound effects"
                    x-bind:aria-checked="soundEnabled ? 'true' : 'false'"
                    x-on:click="soundEnabled = ! soundEnabled; try { localStorage.setItem('eosSoundEnabled', soundEnabled) } catch (e) {}"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors"
                    x-bind:class="soundEnabled ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark'"
                ><span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform" x-bind:class="soundEnabled ? 'translate-x-6' : 'translate-x-1'"></span></button>
            </div>
        </div>

        <p class="{{ $groupTitle }}">Friends</p>
        <div class="{{ $group }}">
            <div class="{{ $row }} cursor-default">
                <span class="{{ $rowIcon }}">@svg('heroicon-o-magnifying-glass', 'h-4 w-4')</span>
                <span class="flex-1">Show me in search</span>
                <button
                    type="button"
                    wire:click="toggleDiscoverable"
                    wire:loading.attr="disabled"
                    wire:target="toggleDiscoverable"
                    role="switch"
                    aria-label="Show me in search"
                    aria-checked="{{ $user->discoverable ? 'true' : 'false' }}"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors {{ $user->discoverable ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark' }}"
                ><span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform {{ $user->discoverable ? 'translate-x-6' : 'translate-x-1' }}"></span></button>
            </div>
            <button type="button" x-on:click="screen = 'blocked'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-no-symbol', 'h-4 w-4')</span><span class="flex-1">Blocked people</span><span class="{{ $hint }}">{{ $this->blockedUsers->count() }}</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
        </div>

        <p class="{{ $groupTitle }}">AI</p>
        <div class="{{ $group }}">
            <button type="button" x-on:click="screen = 'ai-keys'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-sparkles', 'h-4 w-4')</span><span class="flex-1">My AI keys</span><span class="{{ $hint }}">Optional</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
            @if ($user->is_admin)
                <button type="button" x-on:click="screen = 'ai-relay'" class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-server-stack', 'h-4 w-4')</span><span class="flex-1">AI relay</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</button>
            @endif
            @if ($user->is_admin)
                <a href="{{ route('admin.index') }}" wire:navigate class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-wrench-screwdriver', 'h-4 w-4')</span><span class="flex-1">Admin</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</a>
            @endif
        </div>

        <p class="{{ $groupTitle }}">Help</p>
        <div class="{{ $group }}">
            <a href="{{ route('learner.guide') }}" wire:navigate class="{{ $row }}"><span class="{{ $rowIcon }}">@svg('heroicon-o-book-open', 'h-4 w-4')</span><span class="flex-1">Guide</span>@svg($chevron, 'h-4 w-4 text-ink-faint dark:text-ink-faint-dark')</a>
        </div>

        <form method="POST" action="{{ route('logout') }}" class="pt-2">
            @csrf
            <button type="submit" class="card w-full cursor-pointer rounded-2xl py-3 text-center text-sm font-extrabold text-danger-ink transition-colors hover:bg-danger-soft">Sign out</button>
        </form>
    </div>

    {{-- One small screen per › row, each with ‹ back to the list. --}}
    @foreach (['info' => 'Name & level', 'password' => 'Password', 'reminders' => 'Reminders', 'blocked' => 'Blocked people', 'ai-keys' => 'My AI keys', 'ai-relay' => 'AI relay'] as $key => $title)
        @continue($key === 'ai-relay' && ! $user->is_admin)
        <header wire:key="screen-head-{{ $key }}" x-show="screen === '{{ $key }}'" x-cloak class="mb-3 flex items-center gap-2">
            <button type="button" x-on:click="screen = ''" aria-label="Back" class="-ms-2 inline-flex h-9 w-9 shrink-0 cursor-pointer items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</button>
            <h1 class="font-display text-xl font-extrabold text-ink dark:text-ink-dark">{{ $title }}</h1>
        </header>
    @endforeach

    {{-- Basic info --}}
    <form wire:submit="updateBasicInfo" x-show="screen === 'info'" x-cloak class="space-y-3 card p-4">

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Name</label>
            <input
                type="text"
                wire:model="name"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('name')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Level</label>
            <select
                wire:model="cefr_level"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
                @foreach (User::levelOptions() as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
            @error('cefr_level')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
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
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Gender <span class="normal-case text-ink-faint dark:text-ink-faint-dark">(optional)</span></label>
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
                wire:loading.attr="disabled"
                wire:target="updateBasicInfo"
                class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="updateBasicInfo">Save</span>
                <span wire:loading wire:target="updateBasicInfo">Saving…</span>
            </button>
            @if ($basicInfoSaved)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Saved
                </span>
            @endif
        </div>
    </form>

    {{-- Blocked people --}}
    <div x-show="screen === 'blocked'" x-cloak class="card p-4">
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Unblocking doesn't reconnect you.</p>

        <div class="mt-3 space-y-2">
            @forelse ($this->blockedUsers as $blocked)
                <div wire:key="blocked-{{ $blocked->id }}" class="flex items-center gap-3">
                    <x-user-avatar :user="$blocked" class="h-8 w-8 text-xs" />
                    <span class="flex-1 truncate text-sm font-semibold text-ink dark:text-ink-dark">{{ $blocked->name }}</span>
                    <button
                        type="button"
                        wire:click="unblock({{ $blocked->id }})"
                        wire:loading.attr="disabled"
                        wire:target="unblock({{ $blocked->id }})"
                        class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >Unblock</button>
                </div>
            @empty
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">You haven't blocked anyone.</p>
            @endforelse
        </div>
    </div>

    {{-- Reminders --}}
    <div x-show="screen === 'reminders'" x-cloak class="space-y-3">
        <div
            x-data="{ push: window.eosPush ?? {} }"
            x-on:eos-push-state.window="push = $event.detail"
            class="card flex items-center gap-3 p-4"
        >
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Phone alerts</p>
                <p
                    class="text-xs text-ink-faint dark:text-ink-faint-dark"
                    x-text="! push.ready ? '…' : (push.subscribed ? 'On' : (push.denied ? 'Blocked in your browser settings' : (! push.supported ? (push.iosNeedsInstall ? 'Add the app to your Home Screen first' : 'Not supported here') : 'Off')))"
                ></p>
            </div>
            <button
                type="button"
                x-on:click="$dispatch(push.subscribed ? 'eos-push-disable' : 'eos-push-enable')"
                x-bind:disabled="! push.ready || ! push.supported || push.denied || push.busy"
                role="switch"
                aria-label="Phone alerts"
                x-bind:aria-checked="push.subscribed ? 'true' : 'false'"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                x-bind:class="push.subscribed ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark'"
            ><span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform" x-bind:class="push.subscribed ? 'translate-x-6' : 'translate-x-1'"></span></button>
        </div>
        <livewire:notifications.study-reminders />
        <livewire:notifications.review-reminder />
        <div class="card flex items-center gap-3 p-4">
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">Friends</p>
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Studying now · nudges</p>
            </div>
            <button
                type="button"
                wire:click="toggleFriendAlerts"
                wire:loading.attr="disabled"
                wire:target="toggleFriendAlerts"
                role="switch"
                aria-label="Friends alerts"
                aria-checked="{{ $user->friend_alerts ? 'true' : 'false' }}"
                class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors {{ $user->friend_alerts ? 'bg-accent dark:bg-accent-dark' : 'bg-line dark:bg-line-dark' }}"
            ><span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform {{ $user->friend_alerts ? 'translate-x-6' : 'translate-x-1' }}"></span></button>
        </div>
    </div>

    {{-- Password --}}
    <form wire:submit="updatePassword" x-show="screen === 'password'" x-cloak class="space-y-3 card p-4">

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Current password</label>
            <x-password-input wire-model="currentPassword" />
            @error('currentPassword')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">New password</label>
            <x-password-input wire-model="newPassword" />
            @error('newPassword')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Confirm new password</label>
            <x-password-input wire-model="newPassword_confirmation" />
        </div>

        <div class="flex items-center gap-3 pt-1">
            <button
                type="submit"
                wire:loading.attr="disabled"
                wire:target="updatePassword"
                class="cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
            >
                <span wire:loading.remove wire:target="updatePassword">Update password</span>
                <span wire:loading wire:target="updatePassword">Updating…</span>
            </button>
            @if ($passwordSaved)
                <span class="inline-flex items-center gap-1 text-xs font-semibold text-success dark:text-success-dark">
                    @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Updated
                </span>
            @endif
        </div>
    </form>

    {{-- AI keys (optional, the learner's own) --}}
    <div x-show="screen === 'ai-keys'" x-cloak class="space-y-4 card p-4">
        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Optional. Your key is used first; if it fails, the app's key takes over.</p>

        @foreach ([
            'gemini' => ['label' => 'Gemini', 'help' => 'aistudio.google.com/apikey'],
            'groq' => ['label' => 'Groq', 'help' => 'console.groq.com/keys'],
        ] as $provider => $info)
            @php
                $ending = $this->savedAiKeyEnding($provider);
                $status = $aiKeyStatus[$provider] ?? null;
            @endphp
            <div class="space-y-2 rounded-lg border border-line p-3 dark:border-line-dark" wire:key="ai-key-{{ $provider }}">
                <div class="flex items-center justify-between gap-2">
                    <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $info['label'] }}</p>
                    @if ($status === 'ok')
                        <span class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success-ink dark:bg-success-soft-dark dark:text-success-ink-dark">@svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Works</span>
                    @elseif ($status === 'rejected')
                        <span class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger-ink">@svg('heroicon-o-x-circle', 'h-3.5 w-3.5') Doesn't work</span>
                    @elseif ($status === 'unknown')
                        <span class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">Couldn't check now</span>
                    @endif
                </div>

                @if ($ending)
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-sm text-ink-soft dark:text-ink-soft-dark">•••• {{ $ending }}</span>
                        <button
                            type="button"
                            wire:click="testAiKey('{{ $provider }}')"
                            wire:loading.attr="disabled"
                            wire:target="testAiKey('{{ $provider }}')"
                            class="cursor-pointer rounded-full border border-line px-3 py-1 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                        >
                            <span wire:loading.remove wire:target="testAiKey('{{ $provider }}')">Test</span>
                            <span wire:loading wire:target="testAiKey('{{ $provider }}')">Testing…</span>
                        </button>
                        <button
                            type="button"
                            wire:click="removeAiKey('{{ $provider }}')"
                            wire:confirm="Remove your {{ $info['label'] }} key?"
                            class="cursor-pointer rounded-full px-3 py-1 text-xs font-semibold text-danger-ink transition-colors hover:bg-danger-soft"
                        >Remove</button>
                    </div>
                @else
                    <form wire:submit="saveAiKey('{{ $provider }}')" class="space-y-2">
                        <x-password-input wire-model="aiKeyInput.{{ $provider }}" autocomplete="off" placeholder="Paste your key" />
                        @error("aiKeyInput.{$provider}")
                            <p class="text-sm text-danger-ink">{{ $message }}</p>
                        @enderror
                        <div class="flex items-center justify-between gap-2">
                            <a href="https://{{ $info['help'] }}" target="_blank" rel="noopener" class="text-xs text-accent underline dark:text-accent-dark">Get a key: {{ $info['help'] }}</a>
                            <button
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="saveAiKey('{{ $provider }}')"
                                class="shrink-0 cursor-pointer rounded-full bg-accent px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
                            >
                                <span wire:loading.remove wire:target="saveAiKey('{{ $provider }}')">Save</span>
                                <span wire:loading wire:target="saveAiKey('{{ $provider }}')">Checking…</span>
                            </button>
                        </div>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    {{-- AI Relay (admin-only) --}}
    @if (auth()->user()->is_admin)
        @php
            $relayTarget = config('services.ai_proxy.target');
            $relayConfigured = config('services.ai_proxy.configured');
        @endphp
        <div x-show="screen === 'ai-relay'" x-cloak class="space-y-3 card p-4">
            <div>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">AI proxy relay</p>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Which relay Gemini/Groq/Pexels calls go through. Active: <span class="font-semibold text-ink dark:text-ink-dark">{{ $relayTarget }}</span>.</p>
            </div>

            <div class="space-y-2">
                @foreach (['vps' => 'VPS', 'local' => 'This laptop'] as $key => $label)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-line p-3 dark:border-line-dark">
                        <div>
                            <p class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $label }}</p>
                            <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">
                                {{ $relayConfigured[$key] ? 'Configured' : 'No URL set for this slot yet' }}
                            </p>
                        </div>

                        @if ($relayTarget === $key)
                            <span class="inline-flex shrink-0 items-center gap-1 rounded-full bg-success-soft px-3 py-1.5 text-xs font-semibold text-success-ink dark:bg-success-soft-dark dark:text-success-ink-dark">
                                @svg('heroicon-o-check-circle', 'h-3.5 w-3.5') Active
                            </span>
                        @else
                            <button
                                type="button"
                                wire:click="switchAiRelay('{{ $key }}')"
                                wire:loading.attr="disabled"
                                wire:target="switchAiRelay('{{ $key }}')"
                                wire:confirm="Switch the AI relay to {{ $label }}?"
                                class="shrink-0 cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                            >
                                <span wire:loading.remove wire:target="switchAiRelay('{{ $key }}')">Use this</span>
                                <span wire:loading wire:target="switchAiRelay('{{ $key }}')">Switching…</span>
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div x-show="screen === 'ai-relay'" x-cloak class="space-y-4 card p-4">
            <div>
                <p class="text-sm font-semibold text-ink dark:text-ink-dark">AI model chains</p>
                <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Best model first. A model that ran out of free quota, was retired or is failing is skipped until the time shown. Counts are for today.</p>
            </div>

            @foreach ($this->aiChains as $title => $chain)
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">{{ $title }} <span class="font-normal normal-case">· {{ $chain['todayCalls'] }} calls today</span></p>
                        @if ($chain['available'] === 0)
                            <span class="rounded-full bg-danger-soft px-2 py-0.5 text-xs font-semibold text-danger-ink">Every model is down</span>
                        @elseif ($chain['available'] === 1 && count($chain['rows']) > 1)
                            <span class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">Last model left</span>
                        @endif
                    </div>

                    @forelse ($chain['rows'] as $row)
                        <div class="rounded-lg border border-line p-3 dark:border-line-dark">
                            <div class="flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-ink dark:text-ink-dark">
                                    {{ $loop->iteration }}. {{ $row['model'] }}@if ($row['thinking_level']) <span class="font-normal text-ink-faint dark:text-ink-faint-dark">(thinking: {{ $row['thinking_level'] }})</span>@endif
                                </p>
                                @if ($row['available'])
                                    <span class="inline-flex shrink-0 items-center rounded-full bg-success-soft px-2 py-0.5 text-xs font-semibold text-success-ink dark:bg-success-soft-dark dark:text-success-ink-dark">In rotation</span>
                                @else
                                    <span class="inline-flex shrink-0 items-center rounded-full bg-warning-soft px-2 py-0.5 text-xs font-semibold text-warning-ink">Skipped until {{ \Illuminate\Support\Carbon::createFromTimestamp($row['until'])->timezone(config('app.timezone'))->format('H:i') }}</span>
                                @endif
                            </div>
                            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">
                                Today: {{ $row['today']['ok'] }} ok, {{ $row['today']['fail'] }} failed
                                @if ($row['today']['last_fail_reason']) — last failure: {{ str_replace('_', ' ', $row['today']['last_fail_reason']) }} @endif
                            </p>
                            @unless ($row['available'])
                                <p class="mt-1 text-xs text-warning-ink">
                                    {{ match ($row['reason']) {
                                        'daily_quota' => 'Daily free quota used up.',
                                        'rate_limit' => 'Rate limited for a moment.',
                                        'retired' => 'Google no longer serves this model — remove it from the list in .env.',
                                        default => 'Failing (timeouts or server errors).',
                                    } }}
                                </p>
                            @endunless
                        </div>
                    @empty
                        <p class="text-xs text-ink-faint dark:text-ink-faint-dark">No models configured.</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    @endif

    {{-- Avatar --}}
    <x-bottom-sheet show="avatarSheet" label="Your avatar">
        <div class="space-y-4">
        <p class="font-display text-lg font-bold text-ink dark:text-ink-dark">Your avatar</p>

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

        <div>
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

        <div
            x-data="{
                cropping: false,
                objectUrl: null,
                zoom: 0,
                saving: false,
                cropper: null,

                pickFile(event) {
                    const file = event.target.files[0];
                    event.target.value = '';
                    if (! file) return;

                    this.objectUrl = URL.createObjectURL(file);
                    this.zoom = 0;
                    this.cropping = true;
                    this.$nextTick(() => {
                        this.cropper = window.eosAvatarCropper.attach(this.$refs.cropViewport, this.$refs.cropImage);
                    });
                },

                onZoom() {
                    this.cropper?.setZoomFraction(Number(this.zoom));
                },

                cancelCrop() {
                    this.cropper?.cleanup();
                    this.cropper = null;
                    if (this.objectUrl) URL.revokeObjectURL(this.objectUrl);
                    this.objectUrl = null;
                    this.cropping = false;
                    this.saving = false;
                },

                async saveCrop() {
                    if (! this.cropper || this.saving) return;
                    this.saving = true;

                    try {
                        const blob = await this.cropper.crop(512);
                        const file = new File([blob], 'avatar.jpg', { type: 'image/jpeg' });
                        this.$wire.upload(
                            'newAvatar',
                            file,
                            () => this.$wire.call('saveAvatar').then(() => this.cancelCrop()),
                            () => { this.saving = false; },
                        );
                    } catch (e) {
                        this.saving = false;
                    }
                },
            }"
        >
            <div x-show="! cropping" class="flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    @svg('heroicon-o-photo', 'h-3.5 w-3.5')
                    <span>Use a photo</span>
                    <input type="file" x-on:change="pickFile" accept="image/png,image/jpeg,image/webp" class="hidden">
                </label>

                @if (auth()->user()->avatar_path)
                    <button
                        type="button"
                        wire:click="removeAvatar"
                        wire:loading.attr="disabled"
                        wire:target="removeAvatar"
                        wire:confirm="Remove your photo and go back to a color avatar?"
                        class="cursor-pointer text-xs text-ink-faint underline decoration-dotted underline-offset-2 hover:text-danger-ink dark:text-ink-faint-dark disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span wire:loading.remove wire:target="removeAvatar">Remove photo</span>
                        <span wire:loading wire:target="removeAvatar">Removing…</span>
                    </button>
                @endif
            </div>

            <div x-show="cropping" x-cloak class="mt-2 space-y-2.5">
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Drag and zoom.</p>

                <div x-ref="cropViewport" class="relative mx-auto h-64 w-64 touch-none overflow-hidden rounded-2xl bg-surface-sunken select-none dark:bg-surface-sunken-dark">
                    <img x-ref="cropImage" :src="objectUrl" draggable="false" class="absolute top-0 left-0 origin-top-left will-change-transform" style="max-width: none;">
                    <div class="pointer-events-none absolute inset-3 rounded-full" style="box-shadow: 0 0 0 9999px rgba(0,0,0,.45);"></div>
                </div>

                <div class="mx-auto flex max-w-64 items-center gap-2">
                    @svg('heroicon-o-magnifying-glass-minus', 'h-3.5 w-3.5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                    <input type="range" min="0" max="1" step="0.01" x-model="zoom" x-on:input="onZoom" class="w-full accent-accent dark:accent-accent-dark">
                    @svg('heroicon-o-magnifying-glass-plus', 'h-3.5 w-3.5 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                </div>

                <div class="flex items-center justify-center gap-2">
                    <button
                        type="button"
                        x-on:click="cancelCrop"
                        :disabled="saving"
                        class="cursor-pointer rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:bg-surface-sunken disabled:pointer-events-none disabled:opacity-50 dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark"
                    >Cancel</button>
                    <button
                        type="button"
                        x-on:click="saveCrop"
                        :disabled="saving"
                        class="cursor-pointer rounded-full bg-accent px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
                    >
                        <span x-show="! saving">Save photo</span>
                        <span x-show="saving" x-cloak>Saving…</span>
                    </button>
                </div>
            </div>

            @error('newAvatar')
                <p class="mt-1.5 text-xs text-danger-ink">{{ $message }}</p>
            @enderror
        </div>
    </div>
        <button type="button" x-on:click="avatarSheet = false" class="block w-full cursor-pointer rounded-2xl bg-accent py-3 text-base font-extrabold text-white dark:bg-accent-dark">Done</button>
    </x-bottom-sheet>

</div>
