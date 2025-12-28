<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExpenseRequest extends FormRequest
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
            'project_id' => ['required', 'exists:projects,id'],
            'technician_id' => ['nullable', 'integer', 'exists:technicians,id'],
            'date' => ['required', 'date'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:jpeg,jpg,png,pdf,doc,docx', 'max:5120'],
            'expense_type' => ['required', Rule::in(['reimbursement', 'mileage', 'company_card'])],
        ];

        // Conditional validation based on expense_type
        $expenseType = $this->input('expense_type', 'reimbursement');

        if ($expenseType === 'mileage') {
            // Mileage expenses: require distance, rate, vehicle type
            $rules['distance_km'] = ['required', 'numeric', 'min:0.01', 'max:99999.99'];
            $rules['rate_per_km'] = ['required', 'numeric', 'min:0.01', 'max:999.99'];
            $rules['vehicle_type'] = ['required', 'string', 'max:50'];
            $rules['amount'] = ['nullable', 'numeric']; // Auto-calculated, optional
        } elseif ($expenseType === 'reimbursement') {
            // Reimbursement: require amount
            $rules['amount'] = ['required', 'numeric', 'min:0.01', 'max:999999.99'];
            // Note: attachment is optional but recommended for reimbursement
            $rules['distance_km'] = ['nullable'];
            $rules['rate_per_km'] = ['nullable'];
            $rules['vehicle_type'] = ['nullable'];
        } elseif ($expenseType === 'company_card') {
            // Company card: require amount and optional transaction details
            $rules['amount'] = ['required', 'numeric', 'min:0.01', 'max:999999.99'];
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
            'expense_type.required' => 'Expense type is required.',
            'expense_type.in' => 'Invalid expense type selected.',
            'distance_km.required' => 'Distance is required for mileage expenses.',
            'rate_per_km.required' => 'Rate per km is required for mileage expenses.',
            'vehicle_type.required' => 'Vehicle type is required for mileage expenses.',
            'attachment.file' => 'Attachment must be a valid file.',
            'attachment.mimes' => 'Attachment must be a JPEG, PNG, PDF, DOC, or DOCX file.',
            'attachment.max' => 'Attachment must not exceed 5MB.',
        ];
    }
}
