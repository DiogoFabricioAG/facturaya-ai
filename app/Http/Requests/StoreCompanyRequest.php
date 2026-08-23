<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class StoreCompanyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sunat_driver' => $this->input('sunat_driver', config('facturaya.sunat.default_driver')),
            'sunat_environment' => $this->input('sunat_environment', config('facturaya.sunat.default_environment')),
            'default_series' => $this->input('default_series', 'F001'),
            'default_credit_note_series' => $this->input('default_credit_note_series', 'FC01'),
            'default_boleta_series' => $this->input('default_boleta_series', 'B001'),
            'default_boleta_credit_note_series' => $this->input('default_boleta_credit_note_series', 'BC01'),
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ruc' => ['required', 'regex:/^\d{11}$/', Rule::unique('companies', 'ruc')],
            'legal_name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'department' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:500'],
            'sunat_driver' => ['required', 'in:fake,greenter'],
            'sunat_environment' => ['required', 'in:beta,production'],
            'sol_user' => ['nullable', 'required_if:sunat_driver,greenter', 'string', 'max:100'],
            'sol_password' => ['nullable', 'required_if:sunat_driver,greenter', 'string', 'max:255'],
            'certificate' => ['nullable', 'required_if:sunat_driver,greenter', File::default()->max('2mb'), 'extensions:p12,pfx'],
            'certificate_password' => ['nullable', 'required_with:certificate', 'string', 'max:1024'],
            'default_series' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'default_credit_note_series' => ['required', 'regex:/^F[A-Z0-9]{3}$/'],
            'default_boleta_series' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'default_boleta_credit_note_series' => ['required', 'regex:/^B[A-Z0-9]{3}$/'],
            'token_name' => ['nullable', 'string', 'max:100'],
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
