<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreFiscalPreviewRequest;
use App\Services\CompanyContext;
use App\Services\FiscalPreviewService;
use Illuminate\Http\JsonResponse;

class FiscalPreviewController extends Controller
{
    public function store(
        StoreFiscalPreviewRequest $request,
        FiscalPreviewService $service,
        CompanyContext $context,
    ): JsonResponse {
        return response()->json(
            ['data' => $service->preview($context->company(), $request->validated())],
            201,
        );
    }
}
