<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Services\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
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
