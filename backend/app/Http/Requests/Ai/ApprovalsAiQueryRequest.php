<?php

declare(strict_types=1);

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApprovalsAiQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'question' => ['required', 'string'],
            'context' => ['required', 'array'],
            'context.range' => ['required', 'array'],
            'context.range.from' => ['required', 'date_format:Y-m-d'],
            'context.range.to' => ['required', 'date_format:Y-m-d', 'after_or_equal:context.range.from'],
            'context.types' => ['sometimes', 'array'],
            'context.types.*' => ['string', Rule::in(['timesheets', 'expenses'])],
            'format' => ['sometimes', 'string', Rule::in(['text', 'markdown', 'json'])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validatedPayload(): array
    {
        $validated = $this->validated();

        return [
            'question' => (string) $validated['question'],
            'format' => (string) ($validated['format'] ?? 'text'),
            'range' => [
                'from' => (string) $validated['context']['range']['from'],
                'to' => (string) $validated['context']['range']['to'],
            ],
            'types' => array_values(array_unique(array_map('strval', (array) ($validated['context']['types'] ?? [])))),
        ];
    }
}
