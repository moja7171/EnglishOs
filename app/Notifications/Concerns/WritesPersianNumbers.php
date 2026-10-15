<?php

namespace App\Notifications\Concerns;

/**
 * Persian alerts read wrong with Latin digits mixed into the text. Works on
 * a count (12) and on a clock time ("12:00") alike.
 */
trait WritesPersianNumbers
{
    protected function persianNumber(int|string $number): string
    {
        return strtr((string) $number, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
