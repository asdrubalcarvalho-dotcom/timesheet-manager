<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'filters' => ['sometimes', 'array'],
            'filters.from' => ['nullable', 'date_format:Y-m-d'],
            'filters.to' => ['nullable', 'date_format:Y-m-d'],
            'filters.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'filters.project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx'])],
        ];
    }
}
