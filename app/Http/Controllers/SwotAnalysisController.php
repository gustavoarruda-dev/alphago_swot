<?php

namespace App\Http\Controllers;

use App\Http\Requests\GenerateSwotAnalysisRequest;
use App\Http\Requests\StoreSwotFactorRequest;
use App\Http\Requests\UpdateSwotActionRequest;
use App\Http\Requests\UpdateSwotFactorRequest;
use App\Http\Requests\UpdateSwotRecommendationRequest;
use App\Http\Services\Swot\CustomerScopeResolver;
use App\Http\Services\Swot\SwotAnalysisService;
use App\Models\SwotAnalysis;
use App\Models\SwotCardItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SwotAnalysisController extends Controller
{
    public function __construct(
        private readonly SwotAnalysisService $service,
        private readonly CustomerScopeResolver $customerScope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->listForCustomer($customerUuid),
        ]);
    }

    public function generate(GenerateSwotAnalysisRequest $request): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        $payload = $this->service->generate($customerUuid, $request->validated());

        return response()->json([
            'success' => true,
            'data' => $payload,
        ], 201);
    }

    public function show(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);
        $options = $request->validate([
            'top_factors_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'bottom_factors_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'recommendations_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->getForCustomer($analysis, $customerUuid, $options),
        ]);
    }

    public function overview(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->getOverviewForCustomer($analysis, $customerUuid),
        ]);
    }

    public function factors(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);
        $options = $request->validate([
            'top_factors_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'bottom_factors_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->getFactorsForCustomer($analysis, $customerUuid, $options),
        ]);
    }

    public function recommendations(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);
        $options = $request->validate([
            'recommendations_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->service->getRecommendationsForCustomer($analysis, $customerUuid, $options),
        ]);
    }

    public function actionPlan(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->getActionPlanForCustomer($analysis, $customerUuid),
        ]);
    }

    public function strategicImplications(Request $request, SwotAnalysis $analysis): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->getStrategicImplicationsForCustomer($analysis, $customerUuid),
        ]);
    }

    public function storeFactor(
        StoreSwotFactorRequest $request,
        SwotAnalysis $analysis
    ): JsonResponse {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->createFactor($analysis, $customerUuid, $request->validated()),
        ], 201);
    }

    public function updateFactor(
        UpdateSwotFactorRequest $request,
        SwotAnalysis $analysis,
        SwotCardItem $item
    ): JsonResponse {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->updateFactor($analysis, $item, $customerUuid, $request->validated()),
        ]);
    }

    public function destroyFactor(Request $request, SwotAnalysis $analysis, SwotCardItem $item): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->deleteFactor($analysis, $item, $customerUuid),
        ]);
    }

    public function updateRecommendation(
        UpdateSwotRecommendationRequest $request,
        SwotAnalysis $analysis,
        SwotCardItem $item
    ): JsonResponse {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->updateRecommendation($analysis, $item, $customerUuid, $request->validated()),
        ]);
    }

    public function destroyRecommendation(Request $request, SwotAnalysis $analysis, SwotCardItem $item): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->deleteRecommendation($analysis, $item, $customerUuid),
        ]);
    }

    public function updateAction(
        UpdateSwotActionRequest $request,
        SwotAnalysis $analysis,
        SwotCardItem $item
    ): JsonResponse {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->updateAction($analysis, $item, $customerUuid, $request->validated()),
        ]);
    }

    public function destroyAction(Request $request, SwotAnalysis $analysis, SwotCardItem $item): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->deleteAction($analysis, $item, $customerUuid),
        ]);
    }
}
