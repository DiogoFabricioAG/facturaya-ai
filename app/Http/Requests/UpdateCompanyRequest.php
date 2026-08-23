<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['sometimes', 'required', 'regex:/^\d{11}$/', Rule::unique('companies', 'ruc')->ignore($this->route('company'))],
            'legal_name' => ['sometimes', 'required', 'string', 'max:255'],
            'trade_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ubigeo' => ['sometimes', 'required', 'regex:/^\d{6}$/'],
            'department' => ['sometimes', 'required', 'string', 'max:100'],
            'province' => ['sometimes', 'required', 'string', 'max:100'],
            'district' => ['sometimes', 'required', 'string', 'max:100'],
            'address' => ['sometimes', 'required', 'string', 'max:500'],
            'sunat_driver' => ['sometimes', 'required', 'in:fake,greenter'],
            'sunat_environment' => ['sometimes', 'required', 'in:beta,production'],
            'sol_user' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sol_password' => ['sometimes', 'nullable', 'string', 'max:255'],
            'certificate' => ['sometimes', 'nullable', File::default()->max('2mb'), 'extensions:p12,pfx'],
            'certificate_password' => ['sometimes', 'nullable', 'required_with:certificate', 'string', 'max:1024'],
            'default_series' => ['sometimes', 'required', 'regex:/^F[A-Z0-9]{3}$/'],
            'default_credit_note_series' => ['sometimes', 'required', 'regex:/^F[A-Z0-9]{3}$/'],
            'default_boleta_series' => ['sometimes', 'required', 'regex:/^B[A-Z0-9]{3}$/'],
            'default_boleta_credit_note_series' => ['sometimes', 'required', 'regex:/^B[A-Z0-9]{3}$/'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'certificate.extensions' => 'Sube el certificado original en formato .p12 o .pfx.',
            'certificate_password.required_with' => 'Escribe la contraseña con la que se descargó o exportó el certificado.',
        ];
    }
}
