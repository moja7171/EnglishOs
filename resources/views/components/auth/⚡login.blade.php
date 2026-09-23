<?php

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public string $email = '';

    public string $password = '';

    public function login(): void
    {
        $credentials = $this->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if (! Auth::attempt($credentials)) {
            $this->addError('email', 'These credentials do not match our records.');

            return;
        }

        if (request()->hasSession()) {
            request()->session()->regenerate();
            request()->session()->put('auth_login_at', now()->timestamp);
        }

        $this->redirect('/');
    }
};
?>

<form wire:submit="login" class="mx-auto max-w-sm space-y-6 p-6">
    <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">Sign in</h1>

    <div class="space-y-3">
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
            <x-password-input wire-model="password" />
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <button
        type="submit"
        wire:loading.attr="disabled"
        wire:target="login"
        class="w-full cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 dark:bg-accent-dark"
    >
        <span wire:loading.remove wire:target="login">Sign in</span>
        <span wire:loading wire:target="login">Signing in…</span>
    </button>

    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">No account? <a href="/register" class="underline">Register</a></p>
</form>
