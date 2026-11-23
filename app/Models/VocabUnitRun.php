<?php

namespace App\Models;

use Database\Factories\VocabUnitRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How far one learner got in one Vocab in Use unit. A row exists once the
 * unit was opened; finished_at is set when the third step is done.
 */
#[Fillable(['user_id', 'vocab_unit_id', 'listened_letters', 'listen_done_at', 'read_done_at', 'finished_at'])]
class VocabUnitRun extends Model
{
    /** @use HasFactory<VocabUnitRunFactory> */
    use HasFactory;

    protected $attributes = [
        'listened_letters' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'listened_letters' => 'array',
            'listen_done_at' => 'datetime',
            'read_done_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VocabUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(VocabUnit::class, 'vocab_unit_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
