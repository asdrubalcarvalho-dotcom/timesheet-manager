<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TimesheetSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by middleware + controller policy checks.
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d'],
            'group_by' => ['required', 'array', 'min:1'],
            'group_by.*' => ['string', Rule::in(['user', 'project'])],
            'period' => ['required', 'string', Rule::in(['day', 'week', 'month'])],
        ];
    }

    public function messages(): array
    {
        return [
            'group_by.*.in' => 'group_by must contain only: user, project',
        ];
    }
}