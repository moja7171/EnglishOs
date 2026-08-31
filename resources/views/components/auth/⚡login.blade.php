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
        }

        $this->redirect('/');
    }
};
?>

<div class="mx-auto max-w-sm space-y-6 p-6">
    <h1 class="text-2xl font-extrabold">Sign in</h1>

    <div class="space-y-3">
        <div>
            <label class="text-xs font-semibold uppercase text-neutral-500">Email</label>
            <input
                type="email"
                wire:model="email"
                class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
            @error('email')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
        <div>
            <label class="text-xs font-semibold uppercase text-neutral-500">Password</label>
            <input
                type="password"
                wire:model="password"
                class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
            @error('password')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
    </div>

    <button
        wire:click="login"
        class="w-full rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
    >
        Sign in
    </button>

    <p class="text-sm text-neutral-500">No account? <a href="/register" class="underline">Register</a></p>
</div>
