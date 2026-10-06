<?php

namespace App\Http\Controllers;

use App\Services\CompanyContext;
use App\Services\FiscalOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalOperationController extends Controller
{
    public function issue(
        Request $request,
        FiscalOperationService $service,
        CompanyContext $context,
    ): JsonResponse {
        $result = $service->issue($context->company(), $request->all());

        return response()->json($result, in_array($result['status'] ?? '', ['processing', 'unknown'], true) ? 200 : 201);
    }

    public function show(
        string $operationId,
        FiscalOperationService $service,
        CompanyContext $context,
    ): JsonResponse {
        $result = $service->show($context->company(), $operationId);

        return response()->json($result, 200);
    }

    public function reconcile(
        Request $request,
        string $operationId,
        FiscalOperationService $service,
        CompanyContext $context,
    ): JsonResponse {
        $result = $service->reconcile($context->company(), $operationId, $request->all());

        return response()->json($result, 200);
    }

    public function file(
        string $operationId,
        string $type,
        FiscalOperationService $service,
        CompanyContext $context,
    ): StreamedResponse {
        return $service->file($context->company(), $operationId, $type);
    }

    public function adminDeclareNotIssued(
        Request $request,
        string $operationId,
        FiscalOperationService $service,
    ): JsonResponse {
        $result = $service->adminDeclareNotIssued($operationId, $request->all());

        return response()->json($result, 200);
    }
}
