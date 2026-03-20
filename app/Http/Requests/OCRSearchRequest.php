<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class OCRSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'q.required' => 'A search query is required.',
            'q.min' => 'Search queries must be at least 2 characters.',
            'q.max' => 'Search queries may not be greater than 120 characters.',
            'page.integer' => 'The page must be a whole number.',
            'page.min' => 'The page must be at least 1.',
            'per_page.integer' => 'The per page value must be a whole number.',
            'per_page.min' => 'The per page value must be at least 1.',
            'per_page.max' => 'The per page value may not be greater than 50.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query('q');

        $this->merge([
            'q' => is_string($query) ? trim(preg_replace('/\s+/', ' ', $query) ?? $query) : $query,
        ]);
    }
}
