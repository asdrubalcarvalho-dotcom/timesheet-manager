<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Models\Timesheet;

class StoreTimesheetRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Allow authenticated users to create timesheets
        return auth()->check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'required|exists:tasks,id',
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'hours_worked' => 'required|numeric|min:0.25|max:24',
            'description' => 'required|string|max:1000',
            'status' => ['nullable', 'string', Rule::in(['submitted', 'approved', 'rejected', 'closed'])]
        ];
    }

    /**
     * Custom validation for time overlap detection
     * This preserves the critical business rule of no overlapping times
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->hasTimeOverlap()) {
                $validator->errors()->add('time_overlap', 
                    'Time overlap detected. This time period conflicts with an existing timesheet entry.');
            }
        });
    }

    /**
     * Check for time overlaps (CRITICAL BUSINESS RULE)
     * This method preserves the existing overlap validation logic
     */
    private function hasTimeOverlap(): bool
    {
        if (!$this->start_time || !$this->end_time) {
            return false;
        }

        // Find technician by authenticated user's email
        $technician = \App\Models\Technician::where('email', $this->user()->email)->first();
        
        if (!$technician) {
            return false;
        }

        // Check for existing overlapping timesheets
        $existingTimesheets = Timesheet::where('technician_id', $technician->id)
            ->whereDate('date', $this->date)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get();
            
        foreach ($existingTimesheets as $existing) {
            // Extract time part only from datetime fields for comparison
            $existingStart = $existing->start_time ? $existing->start_time->format('H:i') : null;
            $existingEnd = $existing->end_time ? $existing->end_time->format('H:i') : null;
            
            // Check if new timesheet overlaps with this existing one
            // Overlap occurs when: new_start < existing_end AND existing_start < new_end
            if ($this->start_time < $existingEnd && $existingStart < $this->end_time) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get custom messages for validation errors.
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Please select a project.',
            'task_id.required' => 'Please select a task.',
            'location_id.required' => 'Please select a location.',
            'date.required' => 'Date is required.',
            'hours_worked.required' => 'Hours worked is required.',
            'hours_worked.min' => 'Minimum 15 minutes (0.25 hours) required.',
            'hours_worked.max' => 'Maximum 24 hours per day allowed.',
            'description.required' => 'Description is required.',
            'end_time.after' => 'End time must be after start time.',
        ];
    }
}
