<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTravelSegmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Policy will handle authorization
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'technician_id' => ['required', 'integer', 'exists:technicians,id'],
            'project_id' => ['required', 'integer', 'exists:projects,id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'origin_country' => ['required', 'string', 'size:2'],
            'origin_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'destination_country' => ['required', 'string', 'size:2'],
            'destination_location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:planned,completed,cancelled'],
        ];

        // If status is 'completed', end_at is required
        if ($this->input('status') === 'completed') {
            $rules['end_at'] = ['required', 'date', 'after_or_equal:start_at'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'technician_id.required' => 'Technician is required.',
            'technician_id.exists' => 'Selected technician does not exist.',
            'project_id.required' => 'Project is required.',
            'project_id.exists' => 'Selected project does not exist.',
            'travel_date.required' => 'Travel date is required.',
            'travel_date.date' => 'Travel date must be a valid date.',
            'origin_country.required' => 'Origin country is required.',
            'origin_country.size' => 'Origin country must be a 2-letter ISO code.',
            'origin_location_id.exists' => 'Selected origin location does not exist.',
            'destination_country.required' => 'Destination country is required.',
            'destination_country.size' => 'Destination country must be a 2-letter ISO code.',
            'destination_location_id.exists' => 'Selected destination location does not exist.',
            'status.in' => 'Status must be one of: planned, completed, cancelled.',
        ];
    }
}
