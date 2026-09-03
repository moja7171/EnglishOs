<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    public string $cefr_level = 'A2+';

    public string $target_band = '';

    public string $gender = 'unspecified';

    public function register(): void
    {
        $data = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'cefr_level' => 'required|in:A1,A2,A2+,B1,B2,C1',
            'target_band' => 'nullable|string|max:10',
            'gender' => 'required|in:male,female,unspecified',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'cefr_level' => $data['cefr_level'],
            'target_band' => $data['target_band'] ?: null,
            'gender' => $data['gender'],
            'avatar_style' => User::defaultAvatarStyleForGender($data['gender']),
        ]);

        Auth::login($user);

        if (request()->hasSession()) {
            request()->session()->regenerate();
        }

        $this->redirect('/');
    }
};
?>

<div class="mx-auto max-w-sm space-y-6 p-6">
    <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Create your account</h1>

    <div class="space-y-3">
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
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Email</label>
            <input
                type="email"
                wire:model="email"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Password</label>
            <input
                type="password"
                wire:model="password"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Confirm password</label>
            <input
                type="password"
                wire:model="password_confirmation"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
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
            <p class="mt-1 text-xs text-ink-faint dark:text-ink-faint-dark">Just your best guess — this helps the AI Instructor pitch things at the right level. You can always change it later.</p>
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
            <label class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Gender <span class="normal-case text-ink-faint dark:text-ink-faint-dark">(optional — just picks a starting avatar style, you can change it later)</span></label>
            <select
                wire:model="gender"
                class="mt-1 w-full rounded-lg border border-line bg-transparent px-2 py-1 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
            >
                @foreach (User::genderOptions() as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <button
        wire:click="register"
        class="w-full cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
    >
        Create account
    </button>

    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Already have an account? <a href="/login" class="underline">Sign in</a></p>
</div>
