<?php

namespace Database\Seeders;

use App\Models\ExtraEpisode;
use App\Services\SrtToVtt;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

/**
 * The Extra English course: the 30 episodes (database/seeders/data/extra_episodes.json:
 * number, title, video file name on the download host, length) and their
 * English captions, converted from document/extra/<NN>*.srt to .vtt on
 * the public disk. Safe to run again. The videos themselves are never in
 * git or on this app (scripts/upload-media.py extra puts them on the host).
 */
class ExtraEnglishSeeder extends Seeder
{
    public function run(): void
    {
        $episodes = json_decode((string) file_get_contents(database_path('seeders/data/extra_episodes.json')), true);

        foreach ($episodes as $episode) {
            $model = ExtraEpisode::updateOrCreate(['number' => $episode['number']], $episode);

            $source = glob(base_path(sprintf('document/extra/%02d*.srt', $model->number)))[0] ?? null;

            if ($source !== null) {
                Storage::disk('public')->put($model->captionsPath(), SrtToVtt::convert((string) file_get_contents($source)));
            }
        }
    }
}
