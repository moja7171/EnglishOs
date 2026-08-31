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

    public function register(): void
    {
        $data = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
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
    <h1 class="text-2xl font-extrabold">Create your account</h1>

    <div class="space-y-3">
        <div>
            <label class="text-xs font-semibold uppercase text-neutral-500">Name</label>
            <input
                type="text"
                wire:model="name"
                class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
            @error('name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>
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
        <div>
            <label class="text-xs font-semibold uppercase text-neutral-500">Confirm password</label>
            <input
                type="password"
                wire:model="password_confirmation"
                class="mt-1 w-full rounded border border-neutral-300 bg-transparent px-2 py-1 text-sm dark:border-neutral-700"
            >
        </div>
    </div>

    <button
        wire:click="register"
        class="w-full rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white dark:bg-white dark:text-neutral-900"
    >
        Create account
    </button>

    <p class="text-sm text-neutral-500">Already have an account? <a href="/login" class="underline">Sign in</a></p>
</div>
