<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditNoteRequest extends FormRequest
{
    public const FULL_REASON_CODES = ['01', '02', '06'];

    public const REASONS = [
        '01' => 'Anulación de la operación',
        '02' => 'Anulación por error en el RUC',
        '03' => 'Corrección por error en la descripción',
        '04' => 'Descuento global',
        '05' => 'Descuento por ítem',
        '06' => 'Devolución total',
        '07' => 'Devolución por ítem',
        '09' => 'Disminución en el valor',
        '10' => 'Otros conceptos',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'reason_code' => ['required', Rule::in(array_keys(self::REASONS))],
            'reason_description' => ['required', 'string', 'max:250'],
            'items' => [
                Rule::requiredIf(fn (): bool => ! in_array($this->input('reason_code'), self::FULL_REASON_CODES, true)),
                'array',
                'min:1',
                'max:100',
            ],
            'items.*.invoice_draft_item_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
