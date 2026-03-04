<?php

namespace App\Http\Services\Swot;

use App\Models\SwotAnalysis;
use App\Models\SwotSourceGovernance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SwotAutoRefreshService
{
    public function __construct(
        private readonly SwotAnalysisService $analysisService,
    ) {
    }

    /**
     * @return array{
     *   scanned:int,
     *   due:int,
     *   refreshed:int,
     *   skipped:int,
     *   failed:int,
     *   errors:array<int, string>
     * }
     */
    public function run(?string $customerUuid = null, bool $force = false): array
    {
        $staleHours = $this->resolveStaleHours();
        $now = Carbon::now();

        $analyses = $this->latestAnalysesByCustomer($customerUuid);

        $summary = [
            'scanned' => count($analyses),
            'due' => 0,
            'refreshed' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($analyses as $analysis) {
            $generatedAt = $analysis->generated_at ?? $analysis->created_at;
            $isStale = $force || $this->isStale($generatedAt, $now, $staleHours);
            $hasApprovedSourceUpdate = $force || $this->hasApprovedSourceUpdate(
                $analysis->customer_uuid,
                $generatedAt
            );

            if (! $isStale && ! $hasApprovedSourceUpdate) {
                $summary['skipped']++;
                continue;
            }

            $summary['due']++;

            try {
                $analysisRunId = trim((string) ($analysis->trend_analysis_run_id ?? ''));
                $generated = $this->analysisService->regenerateLatestFromStoredPrompt(
                    (string) $analysis->customer_uuid,
                    $analysisRunId !== '' ? $analysisRunId : null,
                );
                if ($generated === null) {
                    $summary['skipped']++;
                    $message = sprintf(
                        'customer_uuid=%s skipped auto-refresh: missing stored analysis_prompt from frontend.',
                        (string) $analysis->customer_uuid
                    );
                    Log::warning($message, [
                        'analysis_uuid' => $analysis->uuid,
                    ]);
                    continue;
                }

                $summary['refreshed']++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $message = sprintf(
                    'customer_uuid=%s failed to auto-refresh SWOT: %s',
                    (string) $analysis->customer_uuid,
                    $exception->getMessage()
                );
                $summary['errors'][] = $message;
                Log::error($message, ['exception' => $exception]);
            }
        }

        return $summary;
    }

    /**
     * @return array<int, SwotAnalysis>
     */
    private function latestAnalysesByCustomer(?string $customerUuid): array
    {
        $rows = SwotAnalysis::query()
            ->when(
                is_string($customerUuid) && trim($customerUuid) !== '',
                fn ($query) => $query->where('customer_uuid', trim((string) $customerUuid))
            )
            ->orderBy('customer_uuid')
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        /** @var array<string, SwotAnalysis> $latest */
        $latest = [];
        foreach ($rows as $row) {
            $key = (string) $row->customer_uuid;
            if ($key === '' || isset($latest[$key])) {
                continue;
            }
            $latest[$key] = $row;
        }

        return array_values($latest);
    }

    private function resolveStaleHours(): int
    {
        $raw = (int) config('swot.auto_refresh.stale_hours', 1);

        return $raw > 0 ? $raw : 1;
    }

    private function isStale(?CarbonInterface $generatedAt, CarbonInterface $now, int $staleHours): bool
    {
        if (! $generatedAt) {
            return true;
        }

        return $generatedAt->lessThanOrEqualTo($now->copy()->subHours($staleHours));
    }

    private function hasApprovedSourceUpdate(string $customerUuid, ?CarbonInterface $generatedAt): bool
    {
        $query = SwotSourceGovernance::query()
            ->where('customer_uuid', $customerUuid)
            ->where('status', 'approved');

        if ($generatedAt) {
            $query->where(function ($nested) use ($generatedAt): void {
                $nested
                    ->where('updated_at', '>', $generatedAt)
                    ->orWhere('last_seen_at', '>', $generatedAt)
                    ->orWhere('created_at', '>', $generatedAt);
            });
        }

        return $query->exists();
    }

}
