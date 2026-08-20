<?php

namespace App\Http\Controllers;

use App\Exceptions\SunatPadronUnavailable;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\CustomerLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Validator;

class CustomerController extends Controller
{
    public function lookup(
        string $ruc,
        CompanyContext $context,
        CustomerLookupService $customers,
    ): JsonResponse {
        Validator::make(
            ['ruc' => $ruc],
            ['ruc' => ['required', 'regex:/^\d{11}$/']],
            ['ruc.regex' => 'El RUC debe tener exactamente 11 dígitos.'],
        )->validate();

        try {
            $result = $customers->findByRuc($context->company(), $ruc);
        } catch (SunatPadronUnavailable $exception) {
            report($exception);

            return response()->json([
                'message' => 'La consulta automática de RUC todavía no está disponible.',
            ], 503);
        }

        if (! $result) {
            return response()->json([
                'message' => 'No se encontró el RUC en el padrón oficial de SUNAT.',
            ], 404);
        }

        return (new CustomerResource($result->customer))
            ->additional([
                'meta' => [
                    'source' => $result->source,
                    'status' => $result->status,
                    'condition' => $result->condition,
                ],
            ])
            ->response()
            ->setStatusCode(200);
    }

    public function index(CompanyContext $context): AnonymousResourceCollection
    {
        return CustomerResource::collection(
            Customer::query()
                ->where('company_id', $context->company()->id)
                ->orderBy('name')
                ->limit(100)
                ->get(),
        );
    }

    public function store(
        StoreCustomerRequest $request,
        CompanyContext $context,
    ): JsonResponse {
        $customer = Customer::query()->updateOrCreate(
            [
                'company_id' => $context->company()->id,
                'ruc' => $request->string('ruc')->value(),
            ],
            ['name' => trim($request->string('name')->value())],
        );

        return (new CustomerResource($customer))
            ->response()
            ->setStatusCode(200);
    }
}
