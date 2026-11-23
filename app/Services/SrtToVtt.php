<?php

namespace App\Services;

/**
 * Turns a SubRip (.srt) caption file into WebVTT, which is what the
 * browser's <track> element reads: a "WEBVTT" header and a dot instead of
 * the comma in the timecodes. Used for the Extra English captions.
 */
class SrtToVtt
{
    public static function convert(string $srt): string
    {
        $srt = preg_replace('/^\xEF\xBB\xBF/', '', $srt) ?? $srt;
        $srt = trim(str_replace(["\r\n", "\r"], "\n", $srt));
        $srt = preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $srt) ?? $srt;

        return "WEBVTT\n\n".$srt."\n";
    }
}
