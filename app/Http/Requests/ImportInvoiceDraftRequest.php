<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ImportInvoiceDraftRequest extends FormRequest
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
            'products_text' => ['nullable', 'required_without:file', 'string', 'min:5', 'max:10000'],
            'file' => ['nullable', 'required_without:products_text', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max('12mb')],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_ruc.regex' => 'El documento del cliente no tiene el formato esperado para el tipo seleccionado.',
            'customer_document_type.in' => 'Solo se admiten RUC o DNI como documento del cliente.',
            'tax_mode.in' => 'Selecciona si el precio incluye IGV o si debe agregarse.',
            'products_text.required_without' => 'Escribe los productos o adjunta un archivo.',
            'products_text.min' => 'La descripción de productos es demasiado corta.',
            'file.required_without' => 'Adjunta un archivo o escribe los productos.',
        ];
    }
}
