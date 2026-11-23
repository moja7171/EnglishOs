<?php

namespace App\Services;

use Illuminate\Support\HtmlString;

/**
 * Persian text with English in it ("از have / has + قسمت سوم فعل استفاده کن"):
 * every run of English is wrapped in its own left-to-right <bdi>, so the
 * browser never lets the Persian around it pull the words, the slash, the
 * apostrophe or the full stop to the wrong side. Used for every Persian line
 * that can hold English (grammar rules, mistake notes, examples).
 */
class BidiText
{
    /**
     * One English run: words joined by a space, "/", "+", "-", an apostrophe,
     * a dot or ", " — plus the sentence's own ending mark. Not glued to a
     * Persian letter or the middle of an HTML entity.
     */
    private const ENGLISH_RUN = '/(?<![\w&#])[A-Za-z0-9]+(?:(?:\s*[\/+]\s*|,\s+|\s+|[\'’.\-])[A-Za-z0-9]+)*[.?!]?/u';

    /**
     * Plain text from a learner or the AI: escaped, then isolated.
     */
    public function fromPlain(string $text): HtmlString
    {
        $marked = preg_replace(self::ENGLISH_RUN, "\x01$0\x02", $text);

        return new HtmlString(str_replace(["\x01", "\x02"], ['<bdi dir="ltr">', '</bdi>'], e($marked)));
    }

    /**
     * Developer-written seed markup (it may hold <strong> / <em> / <br>): the
     * tags are kept and only the text between them is isolated.
     */
    public function fromMarkup(string $markup): HtmlString
    {
        return new HtmlString($this->isolate($markup));
    }

    private function isolate(string $markup): string
    {
        $parts = preg_split('/(<[^>]+>)/', $markup, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

        return collect($parts)
            ->map(fn (string $part) => str_starts_with($part, '<')
                ? $part
                : preg_replace(self::ENGLISH_RUN, '<bdi dir="ltr">$0</bdi>', $part))
            ->implode('');
    }
}
