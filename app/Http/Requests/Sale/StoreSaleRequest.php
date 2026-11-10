<?php

namespace App\Http\Requests\Sale;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'bakong', 'bank_transfer'])],
            'amount_paid' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'discount' => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
