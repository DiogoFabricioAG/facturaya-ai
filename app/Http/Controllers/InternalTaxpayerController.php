<?php

namespace App\Http\Controllers;

use App\Exceptions\RucLookupUnavailable;
use App\Services\Taxpayer\TaxpayerDirectory;
use Illuminate\Http\JsonResponse;

/**
 * Consulta de RUC para el portal app.meowlab.tech (ADR-0010): sólo lectura del padrón,
 * sin empresa ni clientes de por medio. La llama el bridge con su bearer interno.
 */
final class InternalTaxpayerController extends Controller
{
    public function show(string $ruc, TaxpayerDirectory $taxpayers): JsonResponse
    {
        try {
            $found = $taxpayers->find($ruc);
        } catch (RucLookupUnavailable $exception) {
            report($exception);

            return response()->json(['error' => 'lookup_unavailable'], 503);
        }

        if (! $found) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(['data' => [
            'ruc' => $found->data->ruc,
            'legal_name' => $found->data->legalName,
            'status' => $found->data->status,
            'condition' => $found->data->condition,
            'address' => $found->data->address,
            'ubigeo' => $found->data->ubigeo,
        ]]);
    }
}
