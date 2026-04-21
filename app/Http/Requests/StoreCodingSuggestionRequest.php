<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreCodingSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // In production, delegate to a Gate policy — e.g. the authenticated
        // user must have the `coder.submit` permission on the target tenant.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'encounter_id'  => ['required', 'string', 'max:64', 'regex:/^[a-zA-Z0-9_\-]+$/'],
            'clinical_note' => ['required', 'string', 'min:20', 'max:50000'],
        ];
    }
}
