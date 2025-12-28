<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExpenseRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Authorization handled in controller via Policy
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'project_id' => ['sometimes', 'required', 'exists:projects,id'],
            'technician_id' => ['sometimes', 'nullable', 'integer', 'exists:technicians,id'],
            'date' => ['sometimes', 'required', 'date'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf,doc,docx', 'max:5120'],
            'expense_type' => ['sometimes', 'required', Rule::in(['reimbursement', 'mileage', 'company_card'])],
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'rejected'])],
            '_method' => ['sometimes', 'string'], // Laravel method spoofing
        ];

        // Conditional validation based on expense_type (if being updated)
        $expenseType = $this->input('expense_type');
        
        if ($expenseType === 'mileage') {
            $rules['distance_km'] = ['sometimes', 'required', 'numeric', 'min:0.01', 'max:99999.99'];
            $rules['rate_per_km'] = ['sometimes', 'required', 'numeric', 'min:0.01', 'max:999.99'];
            $rules['vehicle_type'] = ['sometimes', 'required', 'string', 'max:50'];
            $rules['amount'] = ['nullable', 'numeric'];
        } elseif ($expenseType === 'reimbursement') {
            $rules['amount'] = ['sometimes', 'required', 'numeric', 'min:0.01', 'max:999999.99'];
            // Attachment optional on update (may already exist)
            $rules['distance_km'] = ['nullable'];
            $rules['rate_per_km'] = ['nullable'];
            $rules['vehicle_type'] = ['nullable'];
        } elseif ($expenseType === 'company_card') {
            $rules['amount'] = ['sometimes', 'required', 'numeric', 'min:0.01', 'max:999999.99'];
            $rules['card_transaction_id'] = ['nullable', 'string', 'max:100'];
            $rules['transaction_date'] = ['nullable', 'date'];
            $rules['distance_km'] = ['nullable'];
            $rules['rate_per_km'] = ['nullable'];
            $rules['vehicle_type'] = ['nullable'];
        }

        return $rules;
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'project_id.required' => 'Project is required.',
            'project_id.exists' => 'Selected project does not exist.',
            'date.required' => 'Expense date is required.',
            'amount.required' => 'Amount is required for this expense type.',
            'amount.min' => 'Amount must be greater than zero.',
            'category.required' => 'Category is required.',
            'description.required' => 'Description is required.',
            'expense_type.required' => 'Expense type is required.',
            'expense_type.in' => 'Invalid expense type selected.',
            'distance_km.required' => 'Distance is required for mileage expenses.',
            'rate_per_km.required' => 'Rate per km is required for mileage expenses.',
            'vehicle_type.required' => 'Vehicle type is required for mileage expenses.',
            'attachment_path.required' => 'Receipt attachment is required for reimbursement expenses.',
        ];
    }
}
