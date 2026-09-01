<?php

use App\Models\Evidence;
use App\Models\MissionRun;
use Livewire\Component;

new class extends Component
{
    public MissionRun $run;

    public bool $readOnly = false;

    public string $text = '';

    public function mount(): void
    {
        if ($this->readOnly) {
            $this->text = $this->run->latestEvidence('writing')?->content_ref ?? '';
        }
    }

    public function getWordCountProperty(): int
    {
        return count(array_filter(preg_split('/\s+/', trim($this->text))));
    }

    public function save(): void
    {
        $minWords = $this->run->mission->stepContent('writing')['min_words'] ?? 100;

        if ($this->wordCount < $minWords) {
            $this->addError('text', "Write at least {$minWords} words before continuing.");

            return;
        }

        Evidence::create([
            'mission_run_id' => $this->run->id,
            'phase' => 'writing',
            'type' => Evidence::TYPE_TEXT,
            'content_ref' => $this->text,
        ]);

        $this->dispatch('clear-draft', prefix: $this->draftPrefix());
        $this->redirect(route('missions.show', $this->run->mission), navigate: true);
    }

    /**
     * Must match the prefix embedded in the Blade template's x-draft
     * attribute exactly — both build it the same way from the run id.
     */
    public function draftPrefix(): string
    {
        return "eos-draft:{$this->run->id}:writing:";
    }
};
?>

@php
    $writing = $run->mission->stepContent('writing');
    $vocabularyWords = $run->selectedVocabularyWords();
    $draftPrefix = $this->draftPrefix();
@endphp

<div class="space-y-4">
    <x-hook :text="$writing['hook'] ?? null" />

    <div>
        <p class="text-xs font-semibold tracking-wide text-ink-faint uppercase dark:text-ink-faint-dark">Writing</p>
        <h2 class="font-display text-lg font-bold text-ink dark:text-ink-dark">{{ $writing['title'] ?? 'Writing' }}</h2>
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-ink-faint dark:text-ink-faint-dark">
        <div>
            <span class="font-semibold uppercase">Write about:</span>
            {{ implode(' · ', $writing['prompts'] ?? []) }}
        </div>
    </div>

    <x-vocabulary-pills :words="$vocabularyWords" />

    @if (count($writing['try_to_use'] ?? []))
        <div>
            <p class="text-xs font-semibold text-ink-faint uppercase dark:text-ink-faint-dark">Connectors that help</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($writing['try_to_use'] as $word)
                    <span class="rounded-full border border-line px-2 py-0.5 text-xs text-ink-soft dark:border-line-dark dark:text-ink-soft-dark">{{ $word }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <textarea
        wire:model.live="text"
        rows="10"
        placeholder="Start writing…"
        @unless ($readOnly)
            x-draft="{ key: '{{ $draftPrefix }}text', field: 'text' }"
        @endunless
        @readonly($readOnly)
        class="w-full rounded-xl border border-line bg-transparent p-3 text-sm text-ink dark:border-line-dark dark:text-ink-dark"
    ></textarea>

    <div class="flex items-center justify-between text-xs">
        <span class="{{ $this->wordCount >= ($writing['min_words'] ?? 100) ? 'text-success dark:text-success-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">
            {{ $this->wordCount }} words (target {{ $writing['min_words'] ?? 100 }}–{{ $writing['max_words'] ?? 150 }})
        </span>
    </div>

    @error('text')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @unless ($readOnly)
        <button
            wire:click="save"
            class="cursor-pointer rounded-full bg-accent px-5 py-2.5 text-sm font-semibold text-white transition-colors hover:opacity-90 dark:bg-accent-dark"
        >
            Continue
        </button>
    @endunless
</div>
