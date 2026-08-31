<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'title', 'module', 'outcome', 'phases'])]
class Mission extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'phases' => 'array',
        ];
    }

    /**
     * @return HasMany<MissionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(MissionRun::class);
    }
}
