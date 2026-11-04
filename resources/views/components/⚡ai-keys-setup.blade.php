<?php

use App\Livewire\Concerns\SavesAiKeys;
use App\Services\GeminiClient;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    use SavesAiKeys;

    /** The key to ask for now: Gemini first, then Groq. */
    #[Computed]
    public function provider(): string
    {
        return auth()->user()->savedAiKey('gemini') === null ? 'gemini' : 'groq';
    }

    public function saveKey(string $provider): void
    {
        $this->saveAiKey($provider);

        $learner = auth()->user();

        if ($learner->mustAddAiKeys()) {
            return;
        }

        $hasBegun = $learner->program_started_at !== null || $learner->latestPlacementTest() !== null;

        $this->redirect(route($hasBegun ? 'home' : 'onboarding'), navigate: true);
    }
};
?>

@php
    $guides = [
        'gemini' => [
            'number' => '۱',
            'name' => 'Gemini',
            'url' => GeminiClient::KEY_PAGE_URL,
            'steps' => [
                'VPN رو روشن کن.',
                'دکمه‌ی پایین رو بزن و با حساب گوگلت وارد شو.',
                'Create API key رو بزن و کلید رو کپی کن.',
                'برگرد اینجا و کلید رو بچسبون.',
            ],
        ],
        'groq' => [
            'number' => '۲',
            'name' => 'Groq',
            'url' => 'https://console.groq.com/keys',
            'steps' => [
                'VPN رو روشن کن.',
                'دکمه‌ی پایین رو بزن و وارد حسابت شو یا یکی بساز.',
                'Create API Key رو بزن، یه اسم دلخواه بنویس و تأیید کن.',
                'کلید رو همون لحظه کپی کن، چون دیگه نشون داده نمی‌شه؛ بعد اینجا بچسبون.',
            ],
        ],
    ];
    $provider = $this->provider;
    $guide = $guides[$provider];
@endphp

<div dir="rtl" class="font-fa mx-auto flex min-h-full w-full max-w-md flex-col gap-4 p-5">
    <header class="space-y-1">
        <p class="text-sm font-bold text-accent dark:text-accent-dark">کلید {{ $guide['number'] }} از ۲</p>
        <h1 class="text-2xl font-extrabold text-ink dark:text-ink-dark">ساخت کلید <span dir="ltr">{{ $guide['name'] }}</span></h1>
        <p class="text-sm leading-6 text-ink-soft dark:text-ink-soft-dark">برای استفاده از قابلیت‌های هوش مصنوعی اپ، باید این دو کلید رایگان رو بسازی.</p>
    </header>

    <div class="flex gap-2.5 rounded-2xl bg-surface-sunken px-3.5 py-3 text-sm leading-6 text-ink-soft dark:bg-surface-sunken-dark dark:text-ink-soft-dark">
        @svg('heroicon-o-shield-check', 'mt-0.5 h-5 w-5 shrink-0 text-accent dark:text-accent-dark')
        <p>نصب اپ و ساخت کلیدها VPN می‌خواد. برای کار با اپ VPN لازم نیست؛ فقط Pi که بیرون از اپه به VPN نیاز داره.</p>
    </div>

    <ol class="card space-y-3 p-4" wire:key="ai-key-steps-{{ $provider }}">
        @foreach ($guide['steps'] as $index => $step)
            <li class="flex gap-3 text-sm leading-6 text-ink dark:text-ink-dark" wire:key="ai-key-step-{{ $provider }}-{{ $index }}">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-accent text-xs font-bold text-white dark:bg-accent-dark">{{ ['۱', '۲', '۳', '۴'][$index] }}</span>
                <span>{{ $step }}</span>
            </li>
        @endforeach
    </ol>

    <a href="{{ $guide['url'] }}" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 rounded-2xl border-2 border-accent px-4 py-3 text-base font-bold text-accent dark:border-accent-dark dark:text-accent-dark">
        @svg('heroicon-o-arrow-top-right-on-square', 'h-5 w-5')
        <span>باز کردن سایت <span dir="ltr">{{ $guide['name'] }}</span></span>
    </a>

    <form wire:submit="saveKey('{{ $provider }}')" wire:key="ai-key-form-{{ $provider }}" class="space-y-2">
        <div dir="ltr">
            <x-password-input wire-model="aiKeyInput.{{ $provider }}" autocomplete="off" placeholder="Paste your key" />
        </div>
        @error("aiKeyInput.{$provider}")
            <p class="text-sm text-danger-ink">{{ $message }}</p>
        @enderror
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="saveKey"
            class="w-full cursor-pointer rounded-2xl bg-accent px-4 py-3 text-base font-bold text-white transition-opacity hover:opacity-90 disabled:pointer-events-none disabled:opacity-50 dark:bg-accent-dark"
        >
            <span wire:loading.remove wire:target="saveKey">ثبت کلید</span>
            <span wire:loading wire:target="saveKey">در حال بررسی…</span>
        </button>
    </form>

    <form method="POST" action="{{ route('logout') }}" class="mt-auto pt-2 text-center">
        @csrf
        <button type="submit" class="cursor-pointer text-sm font-semibold text-ink-faint underline dark:text-ink-faint-dark">خروج از حساب</button>
    </form>
</div>
