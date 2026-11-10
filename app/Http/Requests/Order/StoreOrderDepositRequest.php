<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderDepositRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99', 'regex:/^\d+(?:\.\d{1,2})?$/'],
            'payment_method' => ['required', Rule::in(['cash', 'card', 'bakong', 'bank_transfer'])],
            'transaction_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
