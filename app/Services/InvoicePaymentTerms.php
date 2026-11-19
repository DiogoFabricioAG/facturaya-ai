<?php

namespace App\Services;

use App\Models\InvoiceDraft;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit payment data, never inferred from product text or an AI extraction. */
final class InvoicePaymentTerms
{
    public static function rules(): array
    {
        return [
            'payment_method' => ['sometimes', 'required', 'in:contado,credito'],
            'outstanding_amount' => ['required_if:payment_method,credito', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'installments' => ['required_if:payment_method,credito', 'nullable', 'array', 'max:12'],
            'installments.*' => ['required', 'array:due_date,amount'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d'],
            'installments.*.amount' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
        ];
    }

    /** Omitted fields preserve an existing draft; changing to contado must explicitly clear credit data. */
    public static function attributes(array $input, ?InvoiceDraft $existing = null): array
    {
        return [
            'payment_method' => $input['payment_method'] ?? $existing?->payment_method ?? 'contado',
            'outstanding_amount' => array_key_exists('outstanding_amount', $input) ? $input['outstanding_amount'] : $existing?->outstanding_amount,
            'installments' => array_key_exists('installments', $input) ? $input['installments'] : ($existing?->installments ?? []),
        ];
    }

    public static function validate(InvoiceDraft $draft): void
    {
        $data = self::attributes([], $draft);
        Validator::make($data, self::rules())->validate();
        if ($data['payment_method'] === 'contado') {
            if ($data['outstanding_amount'] !== null || ! empty($data['installments'])) {
                self::fail('No consignes saldo ni cuotas en un comprobante al contado.');
            }

            return;
        }
        if ($draft->document_type !== '01') {
            self::fail('El pago al crédito está disponible para facturas en este flujo.');
        }
        $balance = BigDecimal::of($data['outstanding_amount']);
        if ($balance->isLessThanOrEqualTo(0) || $balance->isGreaterThan(BigDecimal::of($draft->total))) {
            self::fail('El saldo pendiente debe ser mayor que cero y no superar el total del comprobante.');
        }
        if (empty($data['installments'])) {
            self::fail('Indica al menos una cuota con importe y fecha de vencimiento.');
        }
        $sum = BigDecimal::zero();
        $previousDate = $draft->issue_date->format('Y-m-d');
        foreach ($data['installments'] as $installment) {
            if ($installment['due_date'] <= $previousDate) {
                self::fail('Los vencimientos deben ser posteriores a la emisión y estar en orden cronológico, sin fechas repetidas.');
            }
            $amount = BigDecimal::of($installment['amount']);
            if ($amount->isLessThanOrEqualTo(0)) {
                self::fail('Cada cuota debe tener un importe mayor que cero.');
            }
            $sum = $sum->plus($amount);
            $previousDate = $installment['due_date'];
        }
        if (! $sum->isEqualTo($balance)) {
            self::fail('La suma de las cuotas debe coincidir exactamente con el saldo pendiente.');
        }
    }

    public static function summary(InvoiceDraft $draft): array
    {
        if (($draft->payment_method ?? 'contado') !== 'credito') {
            return ['FORMA DE PAGO: CONTADO'];
        }
        $lines = ['FORMA DE PAGO: CREDITO', 'SALDO PENDIENTE: '.$draft->currency.' '.$draft->outstanding_amount];
        foreach ($draft->installments ?? [] as $index => $installment) {
            $lines[] = 'Cuota '.($index + 1).' - Vence: '.$installment['due_date'].' - '.$draft->currency.' '.$installment['amount'];
        }

        return $lines;
    }

    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment_method' => $message]);
    }
}
