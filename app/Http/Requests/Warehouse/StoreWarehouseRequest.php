<?php

namespace App\Http\Requests\Warehouse;

use Illuminate\Foundation\Http\FormRequest;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'unique:warehouses,name'],
            'code' => ['nullable', 'string', 'max:50', 'unique:warehouses,code'],
            'address' => ['nullable', 'string', 'max:255'],
        ];
    }
}
