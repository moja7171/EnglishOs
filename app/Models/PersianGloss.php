<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The Persian equivalents of one English word as one part of speech, from
 * the offline dictionary built out of Wiktionary (see PersianGlossary and
 * scripts/persian-glosses/build.py).
 *
 * @property list<string> $words
 */
#[Fillable(['word', 'pos', 'words'])]
class PersianGloss extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'words' => 'array',
        ];
    }
}
