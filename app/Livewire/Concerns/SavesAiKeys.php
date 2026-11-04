<?php

namespace App\Livewire\Concerns;

use App\Models\User;
use App\Services\GeminiClient;
use App\Services\GroqClient;

/**
 * Saving a learner's own Gemini/Groq key, shared by the keys page after
 * sign-up and Profile → AI keys. Only a key the provider has just answered
 * with is saved; anything else leaves the saved key as it was.
 */
trait SavesAiKeys
{
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

    public function saveAiKey(string $provider): void
    {
        $column = $this->aiKeyColumn($provider);
        $key = trim($this->aiKeyInput[$provider] ?? '');

        $this->resetErrorBag("aiKeyInput.{$provider}");
        unset($this->aiKeyStatus[$provider]);

        if ($key === '' || strlen($key) > 200 || preg_match('/\s/', $key)) {
            $this->addError("aiKeyInput.{$provider}", 'کلید رو کامل و بدون فاصله بچسبون.');

            return;
        }

        $works = $this->checkAiKey($provider, $key);

        if ($works !== true) {
            $this->addError("aiKeyInput.{$provider}", $works === false
                ? 'این کلید کار نکرد. دوباره کپیش کن و بچسبون.'
                : 'الان نشد کلید رو بررسی کنیم. چند دقیقه دیگه دوباره ثبتش کن.');

            return;
        }

        auth()->user()->forceFill([$column => $key])->save();

        $this->aiKeyInput[$provider] = '';
        $this->aiKeyStatus[$provider] = 'ok';
    }

    /**
     * The saved key's last four characters, for "•••• abcd" — null when no
     * key is saved.
     */
    public function savedAiKeyEnding(string $provider): ?string
    {
        $key = auth()->user()->savedAiKey($provider);

        return $key === null ? null : substr($key, -4);
    }

    protected function checkAiKey(string $provider, string $key): ?bool
    {
        return $provider === 'gemini' ? GeminiClient::keyWorks($key) : GroqClient::keyWorks($key);
    }

    protected function aiKeyColumn(string $provider): string
    {
        return User::AI_KEY_COLUMNS[$provider] ?? abort(404);
    }
}
