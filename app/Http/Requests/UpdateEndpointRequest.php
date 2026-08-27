<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'url' => ['sometimes', 'required', 'string', 'max:2048', 'url:http,https'],
            'status' => ['sometimes', 'required', 'string', Rule::in(['active', 'disabled'])],
        ];
    }
}
