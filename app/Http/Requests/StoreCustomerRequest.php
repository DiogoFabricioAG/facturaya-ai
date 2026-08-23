<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'document_type' => (string) $this->input('document_type', '6'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $documentType = (string) $this->input('document_type', '6');

        return [
            'document_type' => ['required', 'in:1,6'],
            'ruc' => [
                'required',
                $documentType === '6' ? 'regex:/^\d{11}$/' : 'regex:/^\d{8}$/',
            ],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'ruc.regex' => 'El documento del cliente no tiene el formato esperado para el tipo seleccionado.',
        ];
    }
}
