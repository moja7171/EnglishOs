<?php

namespace App\Livewire\Concerns;

use App\Models\Evidence;
use App\Models\ShadowDraft;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Keeps each shadowing recording on the server the moment its upload
 * finishes (a ShadowDraft), instead of only in the component's temporary
 * upload — so a learner who leaves the step (a reload, a closed tab, the
 * phone killing the page) comes back to every line they already recorded.
 * The drafts only become Evidence in saveShadowEvidence(), when the step
 * itself is saved.
 *
 * The using component uploads into $shadowRecordings (keyed by line index)
 * and calls restoreShadowDrafts() from mount() when not read-only.
 */
trait KeepsShadowDrafts
{
    /** @var array<int, string> keyed by line index — recordings kept so far, not yet Evidence */
    public array $draftShadowUrls = [];

    abstract protected function phaseKey(): string;

    /**
     * @return Collection<int, ShadowDraft>
     */
    protected function shadowDrafts(): Collection
    {
        return $this->run->shadowDrafts()->where('phase', $this->phaseKey())->orderBy('line_index')->get();
    }

    protected function shadowDraft(int $index): ?ShadowDraft
    {
        return $this->run->shadowDrafts()->where('phase', $this->phaseKey())->where('line_index', $index)->first();
    }

    /**
     * @return Collection<int, ShadowDraft> the drafts restored, for a step that keeps more per line
     */
    protected function restoreShadowDrafts(): Collection
    {
        $drafts = $this->shadowDrafts();

        foreach ($drafts as $draft) {
            $this->draftShadowUrls[$draft->line_index] = $draft->url();
        }

        return $drafts;
    }

    /**
     * Livewire runs this once an upload into shadowRecordings.{index}
     * finishes. A new take replaces the line's old one, file included.
     */
    public function updatedShadowRecordings(mixed $recording, int|string $index): void
    {
        if (! $recording instanceof UploadedFile) {
            return;
        }

        $index = (int) $index;
        $path = $recording->store('missions/'.strtolower($this->run->mission->code).'/evidence', 'public');

        if ($previous = $this->shadowDraft($index)) {
            Storage::disk('public')->delete($previous->path);
        }

        $this->run->shadowDrafts()->updateOrCreate(
            ['phase' => $this->phaseKey(), 'line_index' => $index],
            ['path' => $path, 'feedback' => null],
        );

        $this->draftShadowUrls[$index] = Storage::disk('public')->url($path);
        unset($this->shadowRecordings[$index]);
    }

    /**
     * One AUDIO Evidence row per kept line — content_ref is JSON here
     * (unlike most other steps' plain-URL audio Evidence) since a step can
     * produce more than one recording; line_index is what lets a read-only
     * review map each file back to its line. The files stay where they are.
     */
    protected function saveShadowEvidence(): void
    {
        foreach ($this->shadowDrafts() as $draft) {
            $url = $draft->url();

            Evidence::create([
                'mission_run_id' => $this->run->id,
                'phase' => $this->phaseKey(),
                'type' => Evidence::TYPE_AUDIO,
                'content_ref' => json_encode(['line_index' => $draft->line_index, 'url' => $url]),
            ]);

            $this->savedShadowUrls[$draft->line_index] = $url;
            $draft->delete();
        }
    }
}
