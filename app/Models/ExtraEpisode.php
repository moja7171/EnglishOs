<?php

namespace App\Models;

use App\Services\MediaHost;
use Database\Factories\ExtraEpisodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One of the 30 episodes of the Extra English sitcom course. The video
 * lives on the download host, the English captions (.vtt) and the small
 * poster frame stay on this app. Every episode is open from the start; a
 * learner only gets a plain "watched" tick (ExtraWatch).
 * See App\Services\Courses.
 */
#[Fillable(['number', 'title', 'video_file', 'duration_seconds'])]
class ExtraEpisode extends Model
{
    /** @use HasFactory<ExtraEpisodeFactory> */
    use HasFactory;

    /**
     * @return HasMany<ExtraWatch, $this>
     */
    public function watches(): HasMany
    {
        return $this->hasMany(ExtraWatch::class);
    }

    public function videoUrl(): ?string
    {
        return app(MediaHost::class)->extraVideoUrl($this->video_file);
    }

    public function captionsPath(): string
    {
        return sprintf('extra/%02d.vtt', $this->number);
    }

    public function captionsUrl(): ?string
    {
        return Storage::disk('public')->exists($this->captionsPath())
            ? Storage::disk('public')->url($this->captionsPath())
            : null;
    }

    public function posterUrl(): string
    {
        return asset(sprintf('images/extra/%02d.jpg', $this->number));
    }

    public function durationLabel(): string
    {
        return round($this->duration_seconds / 60).' min';
    }
}
