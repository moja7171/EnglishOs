<?php

namespace App\Livewire\Concerns;

use App\Models\StepDraft;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps a step's work so far on the server (a StepDraft) as it happens —
 * answers, AI results, recordings — instead of only in the component's
 * state, so a learner who leaves the step (a reload, a closed app, the
 * phone killing the page) comes back to where they were. Typed text
 * stays with x-draft in the browser; this is for everything else.
 *
 * The using component lists its kept properties in draftedProperties(),
 * calls restoreStepDraft() from mount() when not read-only, keepStepDraft()
 * after each change worth keeping, and forgetStepDraft() once its
 * Evidence is saved. A recording goes through keepDraftRecording(), which
 * stores the file at once (the temporary upload would not survive).
 */
trait KeepsStepDraft
{
    abstract protected function draftPhase(): string;

    /**
     * @return list<string> the public properties kept in the draft
     */
    abstract protected function draftedProperties(): array;

    protected function stepDraft(): ?StepDraft
    {
        return $this->run->stepDrafts()->where('phase', $this->draftPhase())->first();
    }

    /**
     * Puts the kept properties back; false when there was no draft.
     */
    protected function restoreStepDraft(): bool
    {
        $draft = $this->stepDraft();

        if (! $draft) {
            return false;
        }

        foreach ($this->draftedProperties() as $property) {
            if (array_key_exists($property, $draft->data)) {
                $this->{$property} = $draft->data[$property];
            }
        }

        return true;
    }

    protected function keepStepDraft(): void
    {
        $data = collect($this->draftedProperties())
            ->mapWithKeys(fn (string $property) => [$property => $this->{$property}])
            ->all();

        $this->run->stepDrafts()->updateOrCreate(['phase' => $this->draftPhase()], ['data' => $data]);
    }

    /**
     * Stores a recording on the public disk and lists it in the draft, so
     * an unsaved draft's pruning removes it too. A take that replaces an
     * earlier one deletes the earlier file.
     *
     * @return string the stored file's path on the public disk
     */
    protected function keepDraftRecording(UploadedFile $recording, ?string $replacedPath = null): string
    {
        $path = $recording->store('missions/'.strtolower($this->run->mission->code).'/evidence', 'public');
        $draft = $this->stepDraft() ?? $this->run->stepDrafts()->make(['phase' => $this->draftPhase(), 'data' => []]);
        $paths = $draft->paths ?? [];

        if ($replacedPath !== null && in_array($replacedPath, $paths, true)) {
            Storage::disk('public')->delete($replacedPath);
            $paths = array_values(array_diff($paths, [$replacedPath]));
        }

        $draft->paths = [...$paths, $path];
        $draft->save();

        return $path;
    }

    /**
     * The step is saved: its Evidence now holds the work, and the kept
     * recordings, so only the row goes.
     */
    protected function forgetStepDraft(): void
    {
        $this->stepDraft()?->delete();
    }
}
