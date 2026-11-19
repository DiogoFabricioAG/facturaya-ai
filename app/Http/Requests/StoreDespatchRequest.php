<?php

namespace App\Http\Requests;

use App\Models\Despatch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** First scope: remitente, sale, private transport, one principal vehicle and DNI driver. */
class StoreDespatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $decimal = ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/', 'numeric', 'gt:0'];
        $lightVehicle = in_array($this->input('vehicle_category'), ['M1', 'L'], true);
        $transportRule = $lightVehicle ? 'prohibited' : 'required';

        return [
            'idempotency_key' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'invoice_id' => ['required', 'string', 'ulid'],
            'issue_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('America/Lima')->format('Y-m-d')],
            'transfer_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:issue_date'],
            'origin' => ['required', 'array:ubigeo,address'],
            'origin.ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'origin.address' => ['required', 'string', 'max:500'],
            'destination' => ['required', 'array:ubigeo,address'],
            'destination.ubigeo' => ['required', 'regex:/^\d{6}$/'],
            'destination.address' => ['required', 'string', 'max:500'],
            'weight_kg' => $decimal,
            // Legacy callers without a category retain ordinary private-transport validation.
            'vehicle_category' => ['sometimes', 'required', 'in:M1,L,other'],
            'vehicle' => [$transportRule, 'nullable', 'array:plate'],
            'vehicle.plate' => [$transportRule, 'nullable', 'regex:/^[A-Z0-9]{6}$/'],
            'driver' => [$transportRule, 'nullable', 'array:dni,first_name,last_name,license'],
            'driver.dni' => [$transportRule, 'nullable', 'regex:/^\d{8}$/'],
            'driver.first_name' => [$transportRule, 'nullable', 'string', 'max:100'],
            'driver.last_name' => [$transportRule, 'nullable', 'string', 'max:100'],
            'driver.license' => [$transportRule, 'nullable', 'regex:/^[A-Z0-9]{6,15}$/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*' => ['required', 'array:description,quantity,unit'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => $decimal,
            'items.*.unit' => ['required', Rule::in(array_keys(Despatch::UNITS))],
            'company_id' => ['prohibited'],
            'environment' => ['prohibited'],
            'transport_mode' => ['prohibited'],
            'reason_code' => ['prohibited'],
        ];
    }
}
