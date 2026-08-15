<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class ImportInvoiceDraftRequest extends FormRequest
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
            'products_text' => ['nullable', 'required_without:file', 'string', 'min:5', 'max:10000'],
            'file' => ['nullable', 'required_without:products_text', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max('12mb')],
        ];
    }

    public function messages(): array
    {
        return [
            'customer_ruc.regex' => 'El RUC del cliente debe tener exactamente 11 dígitos.',
            'tax_mode.in' => 'Selecciona si el precio incluye IGV o si debe agregarse.',
            'products_text.required_without' => 'Escribe los productos o adjunta un archivo.',
            'products_text.min' => 'La descripción de productos es demasiado corta.',
            'file.required_without' => 'Adjunta un archivo o escribe los productos.',
        ];
    }
}
