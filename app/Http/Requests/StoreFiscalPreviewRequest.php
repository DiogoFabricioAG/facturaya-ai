<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreFiscalPreviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * La forma decimal se valida en el servicio; aquí solo se exige que los
     * importes lleguen como cadenas, nunca como números JSON. La empresa y el
     * entorno salen del token, por eso se prohíben en el cuerpo.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'contract_version' => ['required', 'string', 'max:16'],
            'operation_id' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'idempotency_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'draft_revision' => [
                'required',
                'integer',
                'min:1',
                'max:4294967295',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value)) {
                        $fail('La revisión debe ser un entero JSON, no una cadena.');
                    }
                },
            ],
            'document_type' => ['required', 'string', 'in:01,03,07'],
            'issue_date' => ['required', 'date_format:Y-m-d'],
            'currency' => ['required', 'string', 'in:PEN'],
            'tax_mode' => ['required', 'string', 'in:included,excluded'],
            'company_id' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'actor' => ['prohibited'],
            'actor_id' => ['prohibited'],
            'environment' => ['prohibited'],
            'customer' => ['required_if:document_type,01,03', 'nullable', 'array'],
            'customer.document_type' => ['required_if:document_type,01,03', 'string', 'in:0,1,6'],
            'customer.name' => ['required_if:document_type,01,03', 'string', 'max:255'],
            'customer.number' => ['nullable', 'string', 'max:20'],
            'items' => ['required_if:document_type,01,03', 'nullable', 'array', 'min:1', 'max:100'],
            'items.*.description' => ['required_if:document_type,01,03', 'string', 'max:500'],
            'items.*.quantity' => ['required_if:document_type,01,03', 'string', 'max:20'],
            'items.*.total_price' => ['required_if:document_type,01,03', 'string', 'max:20'],
            'origin' => ['required_if:document_type,07', 'nullable', 'array'],
            'origin.series' => ['required_if:document_type,07', 'string', 'max:4'],
            'origin.correlative' => [
                'required_if:document_type,07',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value) && ! is_int($value)) {
                        $fail('El correlativo de origen debe ser una cadena o un entero.');
                    }
                },
            ],
            'reason_code' => ['required_if:document_type,07', 'nullable', 'string', 'max:2'],
            'reason_description' => ['required_if:document_type,07', 'nullable', 'string', 'max:255'],
        ];
    }
}
