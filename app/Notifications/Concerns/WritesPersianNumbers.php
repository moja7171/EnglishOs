<?php

namespace App\Notifications\Concerns;

/**
 * Persian alerts read wrong with Latin digits mixed into the text.
 */
trait WritesPersianNumbers
{
    protected function persianNumber(int $number): string
    {
        return strtr((string) $number, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }
}
