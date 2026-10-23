<?php

namespace App\Http\Requests;

use App\Models\DictionaryEntry;
use App\Services\WordLookup;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreVocabularyWordRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['word' => WordLookup::normalize((string) $this->input('word')) ?? '']);
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'word' => ['required', 'string', function (string $attribute, string $value, Closure $fail): void {
                // A phrase is a word to learn only when the dictionary lists
                // it — "give up", not "went to the shop".
                if (WordLookup::isPhrase($value) && ! DictionaryEntry::where('word', $value)->first()?->isKnownToDictionary()) {
                    $fail('Only a phrase the dictionary knows can be added.');
                }
            }],
            'meaning' => ['nullable', 'string', 'max:255'],
            'pos' => ['nullable', 'string', 'max:50'],
            'example' => ['nullable', 'string', 'max:500'],
        ];
    }
}
