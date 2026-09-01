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
    </div>

    <button
        wire:click="register"
        class="w-full cursor-pointer rounded-full bg-ink px-5 py-2.5 text-sm font-semibold text-ground transition-colors hover:opacity-85 dark:bg-ink-dark dark:text-ground-dark"
    >
        Create account
    </button>

    <p class="text-sm text-ink-faint dark:text-ink-faint-dark">Already have an account? <a href="/login" class="underline">Sign in</a></p>
</div>
