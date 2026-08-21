<?php

namespace App\Http\Controllers;

use App\Exceptions\RucLookupUnavailable;
use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CompanyContext;
use App\Services\CustomerLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function lookup(
        string $ruc,
        CompanyContext $context,
        CustomerLookupService $customers,
    ): JsonResponse {
        if (! preg_match('/^\d{11}$/', $ruc)) {
            return response()->json([
                'message' => 'El RUC debe tener exactamente 11 dígitos.',
            ], 422);
        }

        try {
            $result = $customers->findByRuc($context->company(), $ruc);
        } catch (RucLookupUnavailable $exception) {
            report($exception);

            return response()->json([
                'message' => 'El servicio de consulta RUC no está disponible temporalmente.',
            ], 503);
        }

        if (! $result) {
            return response()->json([
                'message' => 'No se encontró el RUC.',
            ], 404);
        }

        return response()->json([
            'data' => (new CustomerResource($result->customer))->resolve(),
            'meta' => [
                'source' => $result->source,
                'provider' => $result->provider,
                'status' => $result->status,
                'condition' => $result->condition,
                'address' => $result->address,
                'ubigeo' => $result->ubigeo,
            ],
        ]);
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
    ): CustomerResource|JsonResponse {
        $customer = Customer::query()->updateOrCreate(
            [
                'company_id' => $context->company()->id,
                'ruc' => $request->string('ruc')->value(),
            ],
            ['name' => trim($request->string('name')->value())],
        );

        return new CustomerResource($customer);
    }
}
