<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use App\Services\Reports\TimesheetReports;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class RunReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is enforced by middleware + controller policy checks.
        return true;
    }

    public function rules(): array
    {
        $report = (string) $this->input('report', '');

        $allowedReports = array_keys(TimesheetReports::TEMPLATES);
        $template = TimesheetReports::template($report);

        $allowedGroupBy = $template['allowed_group_by'] ?? [];

        return [
            'report' => ['required', 'string', Rule::in($allowedReports)],
            'filters' => ['sometimes', 'array'],
            'filters.from' => ['nullable', 'required_if:report,timesheets_by_user_period', 'date_format:Y-m-d'],
            'filters.to' => ['nullable', 'required_if:report,timesheets_by_user_period', 'date_format:Y-m-d'],
            'filters.status' => ['sometimes', 'string'],
            'group_by' => ['required', 'string', Rule::in($allowedGroupBy)],
        ];
    }
}
