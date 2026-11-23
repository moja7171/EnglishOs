<?php

namespace App\Models;

use Database\Factories\VocabUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the 90 units (5-94) of the Vocab in Use course: a short list of
 * texts (A to E) from the Cambridge book. Every unit is open from the
 * start; the course never suggests an order. See App\Services\VocabCourse.
 */
#[Fillable(['number', 'title', 'book_section', 'book_section_name', 'page'])]
class VocabUnit extends Model
{
    /** @use HasFactory<VocabUnitFactory> */
    use HasFactory;

    /**
     * @return HasMany<VocabText, $this>
     */
    public function texts(): HasMany
    {
        return $this->hasMany(VocabText::class)->orderBy('letter');
    }

    /**
     * @return HasMany<VocabUnitRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(VocabUnitRun::class);
    }
}
