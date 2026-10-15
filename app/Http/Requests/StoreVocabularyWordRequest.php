<?php

namespace App\Http\Requests;

use App\Services\WordLookup;
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
            'word' => ['required', 'string'],
            'meaning' => ['nullable', 'string', 'max:255'],
            'pos' => ['nullable', 'string', 'max:50'],
            'example' => ['nullable', 'string', 'max:500'],
        ];
    }
}
