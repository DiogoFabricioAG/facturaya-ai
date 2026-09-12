<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ImportInvoiceDraftRequest extends FormRequest
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
            'products_text' => ['nullable', 'required_without:file', 'string', 'min:5', 'max:10000'],
            'file' => ['nullable', 'required_without:products_text', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max('12mb')],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_ruc.regex' => 'El documento del cliente no tiene el formato esperado para el tipo seleccionado.',
            'customer_document_type.in' => 'Selecciona RUC, DNI o consumidor final para el cliente.',
            'tax_mode.in' => 'Selecciona si el precio incluye IGV o si debe agregarse.',
            'products_text.required_without' => 'Escribe los productos o adjunta un archivo.',
            'products_text.min' => 'La descripción de productos es demasiado corta.',
            'file.required_without' => 'Adjunta un archivo o escribe los productos.',
        ];
    }
}
