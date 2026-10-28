<?php

namespace App\Services;

/**
 * Reads the small JSON object an AI model was asked to answer with. A model
 * sometimes breaks its own JSON — it quotes a word inside a text value with
 * double quotes, wraps a value in single quotes, or adds a code fence — and
 * a verdict that is plainly there should not turn into "please try again".
 * Strict JSON is tried first; only when that fails are the values pulled out
 * one by one, each running from its key to the next key.
 */
class AiJson
{
    /**
     * @return array<string, mixed>|null null when no `"key":` pairs could be found
     */
    public static function decode(string $raw): ?array
    {
        $raw = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($raw)));

        $data = json_decode($raw, true);

        if (is_array($data)) {
            return $data;
        }

        if (! preg_match_all('/(?:^\{|,)\s*"([A-Za-z_][A-Za-z0-9_]*)"\s*:\s*/', $raw, $found, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $result = [];

        foreach ($found as $position => $match) {
            $start = $match[0][1] + strlen($match[0][0]);
            $end = isset($found[$position + 1]) ? $found[$position + 1][0][1] : strlen($raw);

            $result[$match[1][0]] = self::value(substr($raw, $start, $end - $start), ! isset($found[$position + 1]));
        }

        return $result;
    }

    private static function value(string $text, bool $isLast): mixed
    {
        $text = trim($text);

        if ($isLast) {
            $text = trim((string) preg_replace('/\}\s*$/', '', $text));
        }

        $text = rtrim($text, ", \t\n\r");

        if ($text === 'null') {
            return null;
        }

        if (strlen($text) >= 2 && in_array($text[0], ['"', "'"], true) && $text[-1] === $text[0]) {
            $text = substr($text, 1, -1);
        }

        return trim(str_replace(['\\"', '\\n'], ['"', "\n"], $text));
    }
}
