<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class TimesheetPivotRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by middleware + controller policy checks.
        return true;
    }

    public function rules(): array
    {
        return [
            'period' => ['required', 'string', Rule::in(['day', 'week', 'month'])],

            'range' => ['required', 'array'],
            'range.from' => ['required', 'date_format:Y-m-d'],
            'range.to' => ['required', 'date_format:Y-m-d'],

            'dimensions' => ['required', 'array'],
            'dimensions.rows' => ['required', 'array', 'min:1', 'max:1'],
            'dimensions.rows.*' => ['string', 'distinct', Rule::in(['user', 'project'])],
            'dimensions.columns' => ['required', 'array', 'min:1', 'max:1'],
            'dimensions.columns.*' => ['string', 'distinct', Rule::in(['user', 'project'])],

            'metrics' => ['sometimes', 'array'],
            'metrics.*' => ['string', Rule::in(['hours'])],

            'include' => ['sometimes', 'array'],
            'include.row_totals' => ['sometimes', 'boolean'],
            'include.column_totals' => ['sometimes', 'boolean'],
            'include.grand_total' => ['sometimes', 'boolean'],

            'filters' => ['sometimes', 'array'],
            'filters.user_id' => ['nullable', 'integer', 'exists:users,id'],
            'filters.project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'filters.location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'filters.task_id' => ['nullable', 'integer', 'exists:tasks,id'],
            'filters.status' => ['nullable', 'string', Rule::in(['draft', 'submitted', 'pending', 'approved', 'rejected', 'closed'])],

            'sort' => ['sometimes', 'array'],
            'sort.rows' => ['sometimes', 'string', Rule::in(['name', 'total_desc', 'total_asc'])],
            'sort.columns' => ['sometimes', 'string', Rule::in(['name', 'total_desc', 'total_asc'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $from = $this->input('range.from');
            $to = $this->input('range.to');

            if (is_string($from) && is_string($to)) {
                try {
                    $fromDate = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
                    $toDate = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();
                } catch (\Throwable) {
                    return;
                }

                if ($fromDate->greaterThan($toDate)) {
                    $validator->errors()->add('range.from', 'range.from must be <= range.to');
                }

                // Recommended safety limit to keep reports fast and predictable.
                if ($fromDate->diffInDays($toDate) > 366) {
                    $validator->errors()->add('range', 'range is too large (max 366 days)');
                }
            }

            $rows = (array) $this->input('dimensions.rows', []);
            $columns = (array) $this->input('dimensions.columns', []);

            if (count($rows) === 1 && count($columns) === 1 && (string) $rows[0] === (string) $columns[0]) {
                $validator->errors()->add('dimensions', 'rows and columns must be different dimensions');
            }
        });
    }
}
