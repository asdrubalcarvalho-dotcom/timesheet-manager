<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AiTimesheetPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'prompt' => 'required|string|max:2000',
            'timezone' => 'required|string|max:64',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'technician_id' => 'nullable|exists:technicians,id',
            'dry_run' => 'sometimes|boolean',
        ];
    }
}
