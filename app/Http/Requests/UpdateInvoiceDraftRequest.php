<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_ruc' => ['required', 'regex:/^\d{11}$/'],
            'customer_name' => ['required', 'string', 'max:255'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'tax_mode' => ['required', 'in:included,excluded'],
            'currency' => ['required', 'in:PEN,USD'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.unit_price' => ['required', 'numeric', 'gte:0', 'max:99999999999'],
            'items.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'items.*.source_page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
