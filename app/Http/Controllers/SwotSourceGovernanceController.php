<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSwotSourceGovernanceRequest;
use App\Http\Requests\UpdateSwotSourceGovernanceRequest;
use App\Http\Services\Swot\CustomerScopeResolver;
use App\Models\SwotAnalysis;
use App\Models\SwotSourceGovernance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SwotSourceGovernanceController extends Controller
{
    public function __construct(
        private readonly CustomerScopeResolver $customerScope,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);

        $sources = SwotSourceGovernance::query()
            ->where('customer_uuid', $customerUuid)
            ->when(
                is_string($request->query('analysis_run_id')) &&
                trim((string) $request->query('analysis_run_id')) !== '',
                function ($query) use ($request): void {
                    $query->where('analysis_run_id', trim((string) $request->query('analysis_run_id')));
                }
            )
            ->orderByDesc('is_priority')
            ->orderByRaw("CASE status WHEN 'approved' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
            ->orderBy('source_name')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $sources,
        ]);
    }

    public function store(StoreSwotSourceGovernanceRequest $request): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);
        $validated = $request->validated();

        $analysis = $this->resolveAnalysis($customerUuid, $validated['analysis_run_id'] ?? null);

        $sourceOrigin = trim((string) ($validated['source_origin'] ?? 'internal'));
        if ($sourceOrigin === '') {
            $sourceOrigin = 'internal';
        }

        $sourceKey = $this->normalizeSourceKey(
            (string) $validated['source_name'],
            isset($validated['source_url']) ? (string) $validated['source_url'] : null,
        );

        $source = SwotSourceGovernance::query()->withTrashed()->updateOrCreate(
            [
                'customer_uuid' => $customerUuid,
                'source_origin' => $sourceOrigin,
                'source_key' => $sourceKey,
            ],
            [
                'analysis_id' => $analysis?->id,
                'analysis_run_id' => $validated['analysis_run_id'] ?? null,
                'source_name' => trim((string) $validated['source_name']),
                'source_url' => $validated['source_url'] ?? null,
                'source_category' => $validated['source_category'] ?? null,
                'status' => $validated['status'] ?? 'pending',
                'is_priority' => (bool) ($validated['is_priority'] ?? false),
                'extra_metadata' => $validated['extra_metadata'] ?? null,
                'last_seen_at' => Carbon::now(),
                'deleted_at' => null,
            ]
        );
        if ($source->trashed()) {
            $source->restore();
            $source->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => $source,
        ], 201);
    }

    public function update(
        UpdateSwotSourceGovernanceRequest $request,
        SwotSourceGovernance $source
    ): JsonResponse {
        $customerUuid = $this->customerScope->resolve($request);
        if ($source->customer_uuid !== $customerUuid) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to change this source.',
            ], 403);
        }

        $validated = $request->validated();

        if (array_key_exists('analysis_run_id', $validated)) {
            $analysis = $this->resolveAnalysis($customerUuid, $validated['analysis_run_id']);
            $validated['analysis_id'] = $analysis?->id;
        }

        if (array_key_exists('source_name', $validated)) {
            $name = (string) $validated['source_name'];
            $url = array_key_exists('source_url', $validated)
                ? (is_string($validated['source_url']) ? $validated['source_url'] : null)
                : $source->source_url;
            $validated['source_key'] = $this->normalizeSourceKey($name, $url);
            $validated['source_name'] = trim($name);
        }

        if (array_key_exists('source_origin', $validated)) {
            $validated['source_origin'] = trim((string) $validated['source_origin']);
        }

        $validated['last_seen_at'] = Carbon::now();

        $source->fill($validated);
        $source->save();

        return response()->json([
            'success' => true,
            'data' => $source,
        ]);
    }

    public function destroy(Request $request, SwotSourceGovernance $source): JsonResponse
    {
        $customerUuid = $this->customerScope->resolve($request);
        if ($source->customer_uuid !== $customerUuid) {
            return response()->json([
                'success' => false,
                'message' => 'You are not allowed to remove this source.',
            ], 403);
        }

        $source->delete();

        return response()->json([
            'success' => true,
            'message' => 'Source removed.',
        ]);
    }

    private function resolveAnalysis(string $customerUuid, mixed $analysisRunId): ?SwotAnalysis
    {
        $runId = trim((string) $analysisRunId);
        if ($runId === '') {
            return null;
        }

        // Keep parity with Tendências: analysis_run_id can be an arbitrary run token.
        // Only link to a SWOT analysis row when the run id is a valid UUID.
        if (! Str::isUuid($runId)) {
            return null;
        }

        return SwotAnalysis::query()
            ->where('customer_uuid', $customerUuid)
            ->where('uuid', $runId)
            ->first();
    }

    private function normalizeSourceKey(string $sourceName, ?string $sourceUrl = null): string
    {
        $name = mb_strtolower(trim($sourceName));

        $url = trim((string) $sourceUrl);
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && trim($host) !== '') {
                $name = mb_strtolower(trim($host));
            }
        }

        $name = preg_replace('/\\s+/', ' ', $name) ?? $name;

        return $name;
    }
}
