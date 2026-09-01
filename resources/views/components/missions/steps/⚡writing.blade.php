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
        <p class="text-xs font-semibold tracking-wide text-neutral-500 uppercase">Writing</p>
        <h2 class="text-lg font-bold">{{ $writing['title'] ?? 'Writing' }}</h2>
    </div>

    <div class="flex flex-wrap gap-4 text-xs text-neutral-500">
        <div>
            <span class="font-semibold uppercase">Write about:</span>
            {{ implode(' · ', $writing['prompts'] ?? []) }}
        </div>
    </div>

    @if (count($vocabularyWords))
        <div>
            <p class="text-xs font-semibold text-neutral-500 uppercase">Words you picked — try to use some</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($vocabularyWords as $word)
                    <span class="rounded-full bg-neutral-200 px-2 py-0.5 text-xs font-semibold text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300">{{ $word }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if (count($writing['try_to_use'] ?? []))
        <div>
            <p class="text-xs font-semibold text-neutral-500 uppercase">Connectors that help</p>
            <div class="mt-1 flex flex-wrap gap-1.5">
                @foreach ($writing['try_to_use'] as $word)
                    <span class="rounded border border-neutral-300 px-2 py-0.5 text-xs dark:border-neutral-700">{{ $word }}</span>
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
        class="w-full rounded border border-neutral-300 bg-transparent p-3 text-sm dark:border-neutral-700"
    ></textarea>

    <div class="flex items-center justify-between text-xs">
        <span class="{{ $this->wordCount >= ($writing['min_words'] ?? 100) ? 'text-green-600' : 'text-neutral-500' }}">
            {{ $this->wordCount }} words (target {{ $writing['min_words'] ?? 100 }}–{{ $writing['max_words'] ?? 150 }})
        </span>
    </div>

    @error('text')
        <p class="text-sm text-red-600">{{ $message }}</p>
    @enderror

    @unless ($readOnly)
        <button
            wire:click="save"
            class="cursor-pointer rounded bg-neutral-900 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-neutral-700 dark:bg-white dark:text-neutral-900 dark:hover:bg-neutral-200"
        >
            Continue
        </button>
    @endunless
</div>
