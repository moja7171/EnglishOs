<?php

namespace App\Http\Controllers;

use App\Services\ListeningPicks;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Streams the audio of an in-app listening pick (see ListeningPicks::
 * audioPath()) from document/, behind the same login as the rest of the
 * app. A real file response, so the player can seek (Range requests).
 */
class ListeningAudioController extends Controller
{
    public function __invoke(ListeningPicks $picks, string $missionCode, string $slug): BinaryFileResponse
    {
        $path = $picks->audioPath($missionCode, $slug);

        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'audio/mpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
