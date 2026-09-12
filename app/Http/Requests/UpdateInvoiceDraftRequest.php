<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceDraftRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $documentType = (string) $this->input('document_type', '01');
        $customerDocumentType = $this->input('customer_document_type');
        $customerDocumentType = $customerDocumentType === null || trim((string) $customerDocumentType) === ''
            ? ($documentType === '03' ? '0' : '6')
            : (string) $customerDocumentType;
        $anonymousBoleta = $documentType === '03' && $customerDocumentType === '0';

        $this->merge([
            'document_type' => $documentType,
            'customer_document_type' => $customerDocumentType,
            'customer_ruc' => $anonymousBoleta || blank($this->input('customer_ruc')) ? null : trim((string) $this->input('customer_ruc')),
            'customer_name' => $anonymousBoleta || blank($this->input('customer_name')) ? null : trim((string) $this->input('customer_name')),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $documentType = (string) $this->input('document_type', '01');
        $customerDocumentType = (string) $this->input('customer_document_type', '6');

        return [
            'document_type' => ['required', 'in:01,03'],
            'customer_document_type' => ['required', $documentType === '03' ? 'in:0,1,6' : 'in:1,6'],
            'customer_ruc' => [
                $customerDocumentType === '0' ? 'nullable' : 'required',
                $customerDocumentType === '0' ? 'nullable' : ($customerDocumentType === '6' ? 'regex:/^\d{11}$/' : 'regex:/^\d{8}$/'),
            ],
            'customer_name' => [$customerDocumentType === '0' ? 'nullable' : 'required', 'nullable', 'string', 'max:255'],
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
