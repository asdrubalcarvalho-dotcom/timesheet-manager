<?php

declare(strict_types=1);

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

final class ExportReportRequest extends RunReportRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx'])],
        ]);
    }
}
