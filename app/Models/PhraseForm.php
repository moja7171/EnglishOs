<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One way an English phrasal verb or fixed phrase can be written in a text
 * ("gave up"), and the phrase it is a form of ("give up"). The list the
 * tap-a-word sheet uses to suggest the phrase around a tapped word and to
 * look a phrase up under its base form (see PhraseFinder and WordLookup).
 */
#[Fillable(['form', 'phrase'])]
class PhraseForm extends Model
{
    public $timestamps = false;

    /**
     * The base form of $phrase — itself when it already is one — or null
     * when it is not a phrase the dictionary knows. A form shared by two
     * phrases ("lay down" is also the past of "lie down") keeps the one
     * written exactly like it.
     */
    public static function baseOf(string $phrase): ?string
    {
        $phrases = self::where('form', $phrase)->pluck('phrase');

        return $phrases->contains($phrase) ? $phrase : $phrases->first();
    }
}
