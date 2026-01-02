<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ApprovalHeatmapRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by middleware + controller policy checks.
        return true;
    }

    public function rules(): array
    {
        return [
            'range' => ['required', 'array'],
            'range.from' => ['required', 'date_format:Y-m-d'],
            'range.to' => ['required', 'date_format:Y-m-d'],
            'include' => ['required', 'array'],
            'include.timesheets' => ['required', 'boolean'],
            'include.expenses' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = (array) $this->all();

            $from = (string) (($data['range']['from'] ?? '') ?? '');
            $to = (string) (($data['range']['to'] ?? '') ?? '');

            if ($from === '' || $to === '') {
                return;
            }

            try {
                $fromDate = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
                $toDate = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();
            } catch (\Throwable) {
                return;
            }

            if ($fromDate->greaterThan($toDate)) {
                $validator->errors()->add('range', 'range.from must be less than or equal to range.to');
                return;
            }

            // Max 62 days (inclusive)
            $days = $fromDate->diffInDays($toDate) + 1;
            if ($days > 62) {
                $validator->errors()->add('range', 'range must be at most 62 days');
            }

            $includeTimesheets = (bool) (($data['include']['timesheets'] ?? false) === true);
            $includeExpenses = (bool) (($data['include']['expenses'] ?? false) === true);

            if (!$includeTimesheets && !$includeExpenses) {
                $validator->errors()->add('include', 'At least one include.* must be true');
            }
        });
    }
}
