<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $documentType = (string) $this->input('document_type', '01');
        $this->merge([
            'document_type' => $documentType,
            'customer_document_type' => (string) $this->input(
                'customer_document_type',
                $documentType === '03' ? '1' : '6',
            ),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $customerDocumentType = (string) $this->input('customer_document_type', '6');

        return [
            'document_type' => ['required', 'in:01,03'],
            'customer_document_type' => ['required', 'in:1,6'],
            'customer_ruc' => [
                'required',
                $customerDocumentType === '6' ? 'regex:/^\d{11}$/' : 'regex:/^\d{8}$/',
            ],
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
