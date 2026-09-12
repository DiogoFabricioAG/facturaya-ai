<?php

namespace App\Services;

use App\Models\InvoiceDraft;

/**
 * SUNAT's identification rule for electronic boletas.
 *
 * The amount is persisted with two decimal places, so comparing cents avoids
 * floating point edge cases around the S/ 700.00 threshold.
 */
final class BoletaCustomerPolicy
{
    public const IDENTIFICATION_THRESHOLD_CENTS = 70000;

    public function isBoleta(InvoiceDraft $draft): bool
    {
        return (string) ($draft->document_type ?: '01') === '03';
    }

    public function isAnonymous(InvoiceDraft $draft): bool
    {
        return $this->isBoleta($draft) && $this->customerType($draft) === '0';
    }

    public function requiresIdentification(InvoiceDraft $draft): bool
    {
        if (! $this->isBoleta($draft)) {
            return false;
        }

        // The statutory limit is expressed in soles. Until the application
        // has an official exchange-rate source, foreign-currency boletas are
        // kept on the safe side and require buyer identification.
        return (string) ($draft->currency ?: 'PEN') !== 'PEN'
            || $this->toCents($draft->total) > self::IDENTIFICATION_THRESHOLD_CENTS;
    }

    /**
     * Return an actionable validation message, or null when the customer data
     * is valid for the current document and total.
     */
    public function validate(InvoiceDraft $draft): ?string
    {
        if (! $this->isBoleta($draft)) {
            return null;
        }

        $type = $this->customerType($draft);
        $number = trim((string) ($draft->customer_ruc ?? ''));
        $name = trim((string) ($draft->customer_name ?? ''));

        if ($type === '0') {
            if ($this->requiresIdentification($draft)) {
                if ((string) ($draft->currency ?: 'PEN') !== 'PEN') {
                    return 'Las boletas en moneda distinta a PEN deben incluir la identificación del cliente.';
                }

                return 'Las boletas por importes superiores a S/ 700.00 deben incluir nombres y documento del cliente (DNI o RUC).';
            }

            return null;
        }

        if (! in_array($type, ['1', '6'], true)) {
            return 'Selecciona DNI o RUC para identificar al cliente de la boleta.';
        }

        $validNumber = $type === '1'
            ? preg_match('/^\d{8}$/', $number) === 1
            : preg_match('/^\d{11}$/', $number) === 1;

        if (! $validNumber || $name === '') {
            return 'Completa correctamente el nombre y el '.($type === '1' ? 'DNI' : 'RUC').' del cliente para emitir esta boleta.';
        }

        return null;
    }

    public function customerType(InvoiceDraft $draft): string
    {
        $type = $draft->customer_document_type;

        if ($type !== null && (string) $type !== '') {
            return (string) $type;
        }

        return $this->isBoleta($draft) ? '0' : '6';
    }

    private function toCents(mixed $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }
}
