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
            'technician_id' => 'nullable|exists:technicians,id', // Optional for Managers/Admins
            'project_id' => 'required|exists:projects,id',
            'task_id' => 'required|exists:tasks,id',
            'location_id' => 'required|exists:locations,id',
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'hours_worked' => 'required|numeric|min:0.25|max:24',
            'description' => 'required|string|max:1000',
            'status' => ['nullable', 'string', Rule::in(['draft', 'submitted'])]
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

        // Determine which technician to check for overlaps
        $technicianId = null;
        
        // If technician_id is provided in request (Managers/Admins creating for others)
        if ($this->has('technician_id') && $this->technician_id) {
            $technicianId = $this->technician_id;
        } else {
            // Otherwise, use authenticated user's technician record
            $technician = \App\Models\Technician::where('user_id', $this->user()->id)->first();
            
            if (!$technician) {
                // Fallback to email if user_id relationship not set yet
                $technician = \App\Models\Technician::where('email', $this->user()->email)->first();
            }
            
            if (!$technician) {
                return false;
            }
            
            $technicianId = $technician->id;
        }

        // Check for existing overlapping timesheets for this technician
        $existingTimesheets = Timesheet::where('technician_id', $technicianId)
            ->whereDate('date', $this->date)
            ->whereNotNull('start_time')
            ->whereNotNull('end_time')
            ->get();
            
        foreach ($existingTimesheets as $existing) {
            // Extract time part only from datetime fields for comparison
            // Handle both string and DateTime formats
            $existingStart = $existing->start_time;
            if ($existingStart instanceof \Carbon\Carbon || $existingStart instanceof \DateTime) {
                $existingStart = $existingStart->format('H:i');
            }
            
            $existingEnd = $existing->end_time;
            if ($existingEnd instanceof \Carbon\Carbon || $existingEnd instanceof \DateTime) {
                $existingEnd = $existingEnd->format('H:i');
            }
            
            if (!$existingStart || !$existingEnd) {
                continue; // Skip if times are null
            }
            
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
