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

@php
    $field = 'mt-1 w-full rounded-2xl border-2 border-line bg-surface px-4 py-3 text-base font-semibold text-ink focus:border-accent focus:outline-none dark:border-line-dark dark:bg-surface-dark dark:text-ink-dark dark:focus:border-accent-dark';
    $label = 'text-xs font-extrabold tracking-wider text-ink-faint uppercase dark:text-ink-faint-dark';
@endphp

<form wire:submit="login" class="mx-auto flex min-h-[calc(100dvh-8rem)] max-w-sm flex-col gap-5 p-6">
    <a href="{{ route('welcome') }}" wire:navigate aria-label="Back" class="-ms-2 inline-flex h-9 w-9 items-center justify-center rounded-full text-ink-soft transition-colors hover:bg-surface-sunken dark:text-ink-soft-dark dark:hover:bg-surface-sunken-dark">@svg('heroicon-o-chevron-left', 'h-6 w-6')</a>
    <h1 class="font-display text-3xl font-extrabold text-ink dark:text-ink-dark">Welcome back</h1>

    <div class="space-y-4">
        <div>
            <label class="{{ $label }}">Email</label>
            <input type="email" wire:model="email" autocomplete="email" class="{{ $field }}">
            @error('email')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label class="{{ $label }}">Password</label>
            <x-password-input bare wire-model="password" autocomplete="current-password" class="{{ $field }} pr-11" />
            @error('password')
                <p class="mt-1 text-sm text-danger-ink">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <div class="flex-1"></div>

    <div class="space-y-3 pb-2">
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="login"
            class="block w-full cursor-pointer rounded-2xl bg-accent py-3.5 text-base font-extrabold text-white shadow-[0_4px_0_color-mix(in_srgb,var(--color-accent)_60%,black)] transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
        >
            <span wire:loading.remove wire:target="login">Sign in</span>
            <span wire:loading wire:target="login">Signing in…</span>
        </button>
        <p class="text-center text-sm font-semibold text-ink-faint dark:text-ink-faint-dark">New here? <a href="{{ route('register') }}" wire:navigate class="font-extrabold text-accent-ink dark:text-accent-ink-dark">Get started</a></p>
    </div>
</form>
