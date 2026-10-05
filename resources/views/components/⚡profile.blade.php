<?php

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
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
    <a href="{{ route('home') }}" class="inline-flex cursor-pointer items-center gap-1 rounded-full border border-line px-3 py-1.5 text-xs leading-none font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
        @svg('heroicon-o-chevron-left', 'h-3.5 w-3.5')
        All missions
    </a>

    <header class="flex items-center gap-3 card p-4">
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
            'basic-info' => 'Info',
            'privacy' => 'Privacy',
            'reminders' => 'Reminders',
            'password' => 'Password',
            ...(auth()->user()->is_admin ? ['ai-relay' => 'AI Relay'] : []),
        ]"
    />

    {{-- Avatar --}}
    <div x-show="activeTab === 'avatar'" x-cloak class="space-y-4 card p-4">
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

        <div
            class="border-t border-line pt-3 dark:border-line-dark"
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
            <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Custom photo</p>

            <div x-show="! cropping" class="mt-2 flex flex-wrap items-center gap-2">
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-line px-3 py-1.5 text-xs font-semibold text-ink-soft transition-colors hover:border-ink-faint hover:bg-surface-sunken dark:border-line-dark dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">
                    @svg('heroicon-o-photo', 'h-3.5 w-3.5')
                    <span>Choose a photo</span>
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
                <p class="text-xs text-ink-faint dark:text-ink-faint-dark">Drag to reposition, pinch or use the slider to zoom — this is exactly what other learners will see.</p>

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

    {{-- Basic info --}}
    <form wire:submit="updateBasicInfo" x-show="activeTab === 'basic-info'" x-cloak class="space-y-3 card p-4">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Basic info</p>

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

    {{-- Privacy --}}
    <div x-show="activeTab === 'privacy'" x-cloak class="space-y-3">
    <div class="flex items-center justify-between gap-3 card p-4">
        <div>
            <p class="text-sm font-semibold text-ink dark:text-ink-dark">Discoverable in Friends search</p>
            <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Turn this off and new people won't find you by name — anyone you're already connected with is unaffected.</p>
        </div>
        <button
            type="button"
            wire:click="toggleDiscoverable"
            wire:loading.attr="disabled"
            wire:target="toggleDiscoverable"
            role="switch"
            aria-checked="{{ auth()->user()->discoverable ? 'true' : 'false' }}"
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full transition-colors {{ auth()->user()->discoverable ? 'bg-accent dark:bg-accent-dark' : 'bg-surface-sunken dark:bg-surface-sunken-dark' }}"
        >
            <span class="inline-block h-4 w-4 transform rounded-full bg-white shadow-sm transition-transform {{ auth()->user()->discoverable ? 'translate-x-6' : 'translate-x-1' }}"></span>
        </button>
    </div>

    <div class="card p-4">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Blocked people</p>
        <p class="mt-0.5 text-xs text-ink-faint dark:text-ink-faint-dark">Unblocking doesn't reconnect you — you'd both need to send a new friend request.</p>

        <div class="mt-3 space-y-2">
            @forelse ($this->blockedUsers as $blocked)
                <div class="flex items-center gap-3">
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
    </div>

    {{-- Reminders --}}
    <div x-show="activeTab === 'reminders'" x-cloak>
        <livewire:notifications.review-reminder />
    </div>

    {{-- Password --}}
    <form wire:submit="updatePassword" x-show="activeTab === 'password'" x-cloak class="space-y-3 card p-4">
        <p class="text-sm font-semibold text-ink dark:text-ink-dark">Change password</p>

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

    {{-- AI Relay (admin-only) --}}
    @if (auth()->user()->is_admin)
        @php
            $relayTarget = config('services.ai_proxy.target');
            $relayConfigured = config('services.ai_proxy.configured');
        @endphp
        <div x-show="activeTab === 'ai-relay'" x-cloak class="space-y-3 card p-4">
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
    @endif
</div>
