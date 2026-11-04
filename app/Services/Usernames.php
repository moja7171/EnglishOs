<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * The @username that tells two people with the same name apart, and the
 * private code behind a learner's invite link. Stored form only: lowercase,
 * English or Persian letters, digits and "_", so Arabic and Persian spellings
 * of one letter can never give two names that look the same.
 */
class Usernames
{
    public const MIN_LENGTH = 2;

    public const MAX_LENGTH = 30;

    public const INVITE_CODE_LENGTH = 10;

    /** Names that would look like the app itself speaking. */
    public const RESERVED = ['admin', 'administrator', 'support', 'englishos', 'english_os', 'ادمین', 'مدیر', 'پشتیبانی'];

    /** The base for a name with nothing usable in it (only emoji, say). */
    private const FALLBACK = 'user';

    /** @var array<string, string> */
    private const SAME_LETTERS = ['ي' => 'ی', 'ى' => 'ی', 'ك' => 'ک', 'ۀ' => 'ه', 'ة' => 'ه'];

    /**
     * One spelling for each letter and digit, in text a person typed or a
     * name as stored — what search compares.
     */
    public function unifyLetters(string $text): string
    {
        return strtr($text, self::SAME_LETTERS + [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    /**
     * Turns typed text into its stored username form: "@Ali Rezaei" becomes
     * "ali_rezaei". May come back empty or too short; callers validate it.
     */
    public function normalize(string $input): string
    {
        $text = mb_strtolower($this->unifyLetters(ltrim(trim($input), '@')));
        $text = preg_replace('/[\s\x{200C}.\-]+/u', '_', $text);
        $text = preg_replace('/[^a-z0-9_\x{0621}-\x{063A}\x{0641}-\x{064A}\x{067E}\x{0686}\x{0698}\x{06A9}\x{06AF}\x{06CC}]/u', '', $text);
        $text = preg_replace('/_+/', '_', $text);

        return trim(mb_substr(trim($text, '_'), 0, self::MAX_LENGTH), '_');
    }

    /**
     * A free username for this name: the name itself, then with a number
     * when someone already holds it ("ali_rezaei2").
     */
    public function forName(string $name): string
    {
        $base = $this->normalize($name);

        if (mb_strlen($base) < self::MIN_LENGTH) {
            $base = self::FALLBACK;
        }

        $base = mb_substr($base, 0, self::MAX_LENGTH - 4);
        $candidate = $base;

        for ($number = 2; ! $this->isAvailable($candidate); $number++) {
            $candidate = $base.$number;
        }

        return $candidate;
    }

    public function isAvailable(string $username): bool
    {
        return ! in_array($username, self::RESERVED, true)
            && ! User::query()->where('username', $username)->exists();
    }

    /**
     * An unguessable code, so an invite link reaches its owner even with
     * "Show me in search" off and cannot be walked through by number.
     */
    public function newInviteCode(): string
    {
        do {
            $code = Str::lower(Str::random(self::INVITE_CODE_LENGTH));
        } while (User::query()->where('invite_code', $code)->exists());

        return $code;
    }
}
