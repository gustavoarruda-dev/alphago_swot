<?php

namespace App\Http\Services\Swot;

use App\Models\SwotAnalysis;
use App\Models\SwotCard;
use App\Models\SwotCardItem;
use App\Models\SwotSourceGovernance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SwotAnalysisService
{
    private const HISTORICAL_CARD_ITEMS_PER_ANALYSIS = 3;
    private const HISTORICAL_TABLE_ITEMS_PER_ANALYSIS = 10;

    private const FACTOR_CARD_DEFINITIONS = [
        'strengths' => [
            'card_key' => 'factors.strengths',
            'title' => 'Forças',
            'subtitle' => 'Vantagens competitivas',
            'sort_order' => 10,
        ],
        'opportunities' => [
            'card_key' => 'factors.opportunities',
            'title' => 'Oportunidades',
            'subtitle' => 'Cenários favoráveis externos',
            'sort_order' => 20,
        ],
        'weaknesses' => [
            'card_key' => 'factors.weaknesses',
            'title' => 'Fraquezas',
            'subtitle' => 'Limitações e gaps internos',
            'sort_order' => 30,
        ],
        'threats' => [
            'card_key' => 'factors.threats',
            'title' => 'Ameaças',
            'subtitle' => 'Riscos e desafios externos',
            'sort_order' => 40,
        ],
    ];

    private const RECOMMENDATION_CARD_DEFINITIONS = [
        'short_term' => [
            'card_key' => 'recommendations.short_term',
            'title' => 'Curto Prazo',
            'subtitle' => '0-3 meses',
            'sort_order' => 10,
        ],
        'mid_term' => [
            'card_key' => 'recommendations.mid_term',
            'title' => 'Médio Prazo',
            'subtitle' => '3-6 meses',
            'sort_order' => 20,
        ],
        'long_term' => [
            'card_key' => 'recommendations.long_term',
            'title' => 'Longo Prazo',
            'subtitle' => '6-12 meses',
            'sort_order' => 30,
        ],
    ];

    private const ACTION_PLAN_CARD_DEFINITIONS = [
        'technology-product' => [
            'card_key' => 'action_plan.technology-product',
            'title' => 'Tecnologia & Produto',
            'sort_order' => 10,
        ],
        'commercial-marketing' => [
            'card_key' => 'action_plan.commercial-marketing',
            'title' => 'Comercial & Marketing',
            'sort_order' => 20,
        ],
        'operations-support' => [
            'card_key' => 'action_plan.operations-support',
            'title' => 'Operações & Suporte',
            'sort_order' => 30,
        ],
        'finance-pricing' => [
            'card_key' => 'action_plan.finance-pricing',
            'title' => 'Financeiro & Pricing',
            'sort_order' => 40,
        ],
        'hr-people' => [
            'card_key' => 'action_plan.hr-people',
            'title' => 'RH & Capital Humano',
            'sort_order' => 50,
        ],
        'legal-compliance' => [
            'card_key' => 'action_plan.legal-compliance',
            'title' => 'Jurídico & Compliance',
            'sort_order' => 60,
        ],
    ];

    private const GENERIC_INTERNAL_SOURCE_LABELS = [
        'swot interna',
        'swot interno',
        'swot',
        'fonte interna',
        'fonte interno',
        'analise interna',
        'analise interno',
        'dados internos',
        'dados interno',
        'base interna',
        'base interno',
        'interna',
        'interno',
        'internal source',
        'internal',
    ];

    private const REQUIRED_GENERATION_TOOLS = [
        'search_swot_generation_context',
        'search_web_market',
    ];

    private const DISALLOWED_GENERATED_PHRASES = [
        'executar iniciativa',
        'hipotese:',
        'hipótese:',
        'lorem ipsum',
    ];

    private const FACTOR_SPECIFICITY_KEYWORDS = [
        'bacen',
        'bcb',
        'cvm',
        'pix',
        'open finance',
        'open banking',
        'cdi',
        'selic',
        'nps',
        'cac',
        'ltv',
        'churn',
        'mrr',
        'arr',
        'ebitda',
        'api',
        'sla',
        'kpi',
        'lgpd',
        'chargeback',
        'fraude',
        'onboarding',
    ];

    private const MIN_SUMMARY_CHARS = 120;
    private const MIN_STRATEGIC_NOTE_CHARS = 120;
    private const MIN_FACTOR_ITEMS_PER_BUCKET = 3;
    private const MIN_FACTOR_TITLE_CHARS = 16;
    private const MIN_FACTOR_DESCRIPTION_CHARS = 70;
    private const MIN_RECOMMENDATION_ITEMS_PER_BUCKET = 3;
    private const MIN_IMPLICATION_GROUPS = 4;
    private const MIN_IMPLICATION_ITEMS_PER_GROUP = 10;
    private const MIN_ACTION_AREAS = 6;
    private const MIN_ACTION_ITEMS_PER_AREA = 10;
    private const REQUIRED_IMPLICATION_GROUP_KEYS = [
        'so-accelerate',
        'st-defend',
        'wo-invest',
        'wt-mitigate',
    ];

    public function __construct(
        private readonly SwotBrainClient $brainClient,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function generate(string $customerUuid, array $payload): array
    {
        $normalizedFilters = $this->normalizeFilters($payload['filters'] ?? []);
        $trendAnalysisRunId = $this->sanitizeString($payload['trend_analysis_run_id'] ?? null);
        $analysisRunId = $this->sanitizeString($payload['analysis_run_id'] ?? null) ?: $trendAnalysisRunId;

        $prompt = $this->sanitizeString($payload['analysis_prompt'] ?? null);
        if ($prompt === null) {
            throw new \InvalidArgumentException('analysis_prompt is required.');
        }

        $brainFilters = $normalizedFilters;
        $brainFilters['page_context'] = 'swot';
        $brainFilters['swot_mode'] = 'generation';
        if ($analysisRunId !== null) {
            $brainFilters['analysis_run_id'] = $analysisRunId;
        }

        [$brainResponse, $structuredAnswer] = $this->requestStructuredGeneration($customerUuid, $prompt, $brainFilters);
        $postGenerationSourceCatalog = $this->buildSourceCatalog($customerUuid, $analysisRunId);
        $structuredAnswer = $this->applySourceCatalogToStructured(
            $structuredAnswer,
            $postGenerationSourceCatalog
        );
        $analysisTitle = $this->sanitizeString($payload['analysis_title'] ?? null) ?? 'Análise SWOT';

        $analysis = DB::transaction(function () use (
            $customerUuid,
            $analysisTitle,
            $trendAnalysisRunId,
            $analysisRunId,
            $prompt,
            $normalizedFilters,
            $brainResponse,
            $structuredAnswer
        ): SwotAnalysis {
            // Keep full history: each sync/generation creates a new analysis row.
            // Previous analyses (including manual edits) stay preserved.
            $analysis = new SwotAnalysis();
            $analysis->customer_uuid = $customerUuid;

            $analysis->fill([
                'trend_analysis_run_id' => $trendAnalysisRunId,
                'status' => 'generated',
                'analysis_title' => $analysisTitle,
                'analysis_summary' => $structuredAnswer['analysis_summary'],
                'brain_conversation_id' => $this->sanitizeString($brainResponse['conversation_id'] ?? null),
                'filters' => $normalizedFilters,
                'raw_ai_payload' => [
                    'input' => [
                        'analysis_prompt' => $prompt,
                        'analysis_run_id' => $analysisRunId,
                        'filters' => $normalizedFilters,
                    ],
                    'brain' => $brainResponse,
                    'structured' => $structuredAnswer,
                ],
                'generated_at' => now(),
            ]);
            $analysis->save();

            $this->syncEditableCards($analysis, $structuredAnswer);

            return $analysis;
        });

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForCustomer(string $customerUuid): array
    {
        return SwotAnalysis::query()
            ->where('customer_uuid', $customerUuid)
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get()
            ->map(function (SwotAnalysis $analysis): array {
                return [
                    'uuid' => $analysis->uuid,
                    'analysis_title' => $analysis->analysis_title,
                    'status' => $analysis->status,
                    'trend_analysis_run_id' => $analysis->trend_analysis_run_id,
                    'generated_at' => optional($analysis->generated_at)->toIso8601String(),
                    'created_at' => optional($analysis->created_at)->toIso8601String(),
                    'updated_at' => optional($analysis->updated_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getForCustomer(SwotAnalysis $analysis, string $customerUuid, array $options = []): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);

        return $this->formatAnalysisPayload($analysis->loadMissing('cards.items'), $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverviewForCustomer(SwotAnalysis $analysis, string $customerUuid): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $payload = $this->formatAnalysisPayload($analysis->loadMissing('cards.items'));

        return [
            'analysis' => $payload['analysis'],
            'content' => [
                'analysis_summary' => Arr::get($payload, 'content.analysis_summary', ''),
                'strategic_note' => Arr::get($payload, 'content.strategic_note', ''),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getFactorsForCustomer(SwotAnalysis $analysis, string $customerUuid, array $options = []): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $payload = $this->formatAnalysisPayload($analysis->loadMissing('cards.items'), $options);

        return [
            'analysis' => $payload['analysis'],
            'content' => [
                'factors' => Arr::get($payload, 'content.factors', []),
                'factor_sections' => Arr::get($payload, 'content.factor_sections', []),
            ],
            'meta' => [
                'factors' => Arr::get($payload, 'meta.factors', []),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function getRecommendationsForCustomer(
        SwotAnalysis $analysis,
        string $customerUuid,
        array $options = []
    ): array {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $payload = $this->formatAnalysisPayload($analysis->loadMissing('cards.items'), $options);

        return [
            'analysis' => $payload['analysis'],
            'content' => [
                'recommendations' => Arr::get($payload, 'content.recommendations', []),
                'recommendation_sections' => Arr::get($payload, 'content.recommendation_sections', []),
            ],
            'meta' => [
                'recommendations' => Arr::get($payload, 'meta.recommendations', []),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getActionPlanForCustomer(
        SwotAnalysis $analysis,
        string $customerUuid,
        array $options = []
    ): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $payload = $this->formatAnalysisPayload($analysis->loadMissing('cards.items'));
        $actionPlan = Arr::get($payload, 'content.action_plan', []);

        $areaKey = $this->sanitizeString($options['area_key'] ?? null);
        $sortBy = $this->sanitizeString($options['sort_by'] ?? null) ?? 'priority';
        $sortDir = strtolower($this->sanitizeString($options['sort_dir'] ?? null) ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($options['page'] ?? 1));
        $perPage = min(100, max(1, (int) ($options['per_page'] ?? 10)));

        if ($areaKey !== null && is_array($actionPlan)) {
            foreach ($actionPlan as $index => $area) {
                if (! is_array($area)) {
                    continue;
                }
                $currentAreaKey = $this->sanitizeString($area['area_key'] ?? null);
                if ($currentAreaKey === null || ! hash_equals($currentAreaKey, $areaKey)) {
                    continue;
                }

                $items = collect(is_array($area['items'] ?? null) ? $area['items'] : []);
                $sorted = $this->sortActionPlanItemsCollection($items, $sortBy, $sortDir);
                $totalItems = $sorted->count();
                $totalPages = max(1, (int) ceil($totalItems / $perPage));
                $safePage = min($page, $totalPages);
                $offset = ($safePage - 1) * $perPage;

                $actionPlan[$index]['items'] = $sorted->slice($offset, $perPage)->values()->all();

                return [
                    'analysis' => $payload['analysis'],
                    'content' => [
                        'action_plan' => $actionPlan,
                    ],
                    'meta' => [
                        'action_plan' => [
                            'area_key' => $areaKey,
                            'sort_by' => $sortBy,
                            'sort_dir' => $sortDir,
                            'page' => $safePage,
                            'per_page' => $perPage,
                            'total_items' => $totalItems,
                            'total_pages' => $totalPages,
                        ],
                    ],
                ];
            }
        }

        return [
            'analysis' => $payload['analysis'],
            'content' => [
                'action_plan' => $actionPlan,
            ],
        ];
    }

    /**
     * @param \Illuminate\Support\Collection<int, array<string, mixed>> $items
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function sortActionPlanItemsCollection($items, string $sortBy, string $sortDir)
    {
        $priorityWeight = static function (?string $value): int {
            $normalized = Str::lower(trim((string) $value));
            return match ($normalized) {
                'crítica', 'critica' => 4,
                'alta' => 3,
                'média', 'media' => 2,
                'baixa' => 1,
                default => 0,
            };
        };

        $mapText = static function (mixed $value): string {
            return Str::lower(trim((string) $value));
        };

        $sorted = $items->sort(function (array $left, array $right) use ($sortBy, $sortDir, $priorityWeight, $mapText): int {
            $leftValue = null;
            $rightValue = null;

            if ($sortBy === 'priority') {
                $leftValue = $priorityWeight($left['priority'] ?? null);
                $rightValue = $priorityWeight($right['priority'] ?? null);
            } elseif ($sortBy === 'swot_link') {
                $leftValue = $mapText($left['swot_link'] ?? ($left['source_name'] ?? ''));
                $rightValue = $mapText($right['swot_link'] ?? ($right['source_name'] ?? ''));
            } else {
                $leftValue = $mapText($left[$sortBy] ?? '');
                $rightValue = $mapText($right[$sortBy] ?? '');
            }

            if ($leftValue === $rightValue) {
                return 0;
            }

            $cmp = $leftValue <=> $rightValue;
            return $sortDir === 'asc' ? $cmp : -$cmp;
        });

        return $sorted->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function getStrategicImplicationsForCustomer(SwotAnalysis $analysis, string $customerUuid): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $payload = $this->formatAnalysisPayload($analysis->loadMissing('cards.items'));

        return [
            'analysis' => $payload['analysis'],
            'content' => [
                'strategic_implications' => Arr::get($payload, 'content.strategic_implications', []),
                'strategic_note' => Arr::get($payload, 'content.strategic_note', ''),
            ],
        ];
    }

    /**
     * Regenerates SWOT for the latest analysis of a customer using the same prompt saved previously.
     *
     * @return array<string, mixed>|null
     */
    public function regenerateLatestFromStoredPrompt(
        string $customerUuid,
        ?string $analysisRunId = null,
        ?string $fallbackPrompt = null
    ): ?array
    {
        $requestedRunId = $this->sanitizeString($analysisRunId);

        $analysis = SwotAnalysis::query()
            ->where('customer_uuid', $customerUuid)
            ->when(
                $requestedRunId !== null,
                fn ($query) => $query->where('trend_analysis_run_id', $requestedRunId)
            )
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->first();

        if (! $analysis && $requestedRunId !== null) {
            $analysis = SwotAnalysis::query()
                ->where('customer_uuid', $customerUuid)
                ->orderByDesc('generated_at')
                ->orderByDesc('created_at')
                ->first();
        }

        $resolvedRunId = $requestedRunId
            ?? $this->sanitizeString($analysis?->trend_analysis_run_id)
            ?? (string) Str::uuid();
        $approvedSources = $this->resolveApprovedSourceFilters($customerUuid, $resolvedRunId);

        if (! $analysis) {
            $prompt = $this->sanitizeString($fallbackPrompt);
            if ($prompt === null) {
                return null;
            }

            $filters = [
                'analysis_run_id' => $resolvedRunId,
                'page_context' => 'swot',
                'view_mode' => 'swot',
                'swot_mode' => 'generation',
            ];
            if ($approvedSources !== []) {
                $filters['approved_sources'] = $approvedSources;
            }

            return $this->generate($customerUuid, [
                'analysis_title' => 'Análise SWOT',
                'trend_analysis_run_id' => $resolvedRunId,
                'analysis_run_id' => $resolvedRunId,
                'analysis_prompt' => $this->buildGovernanceReinforcedPrompt($prompt, $approvedSources),
                'filters' => $filters,
            ]);
        }

        $storedPrompt = $this->resolveStoredPrompt($analysis->raw_ai_payload)
            ?? $this->sanitizeString($fallbackPrompt);
        if ($storedPrompt === null) {
            return null;
        }

        $storedFilters = Arr::get($analysis->raw_ai_payload, 'input.filters');
        $filters = is_array($storedFilters)
            ? $this->normalizeFilters($storedFilters)
            : $this->normalizeFilters(is_array($analysis->filters) ? $analysis->filters : []);

        if ($approvedSources !== []) {
            $filters['approved_sources'] = $approvedSources;
        }
        $filters['analysis_run_id'] = $resolvedRunId;
        $filters['page_context'] = 'swot';
        $filters['view_mode'] = 'swot';
        $filters['swot_mode'] = 'generation';

        return $this->generate($customerUuid, [
            'analysis_title' => $analysis->analysis_title ?: 'Análise SWOT',
            'trend_analysis_run_id' => $resolvedRunId,
            'analysis_run_id' => $resolvedRunId,
            'analysis_prompt' => $this->buildGovernanceReinforcedPrompt($storedPrompt, $approvedSources),
            'filters' => $filters,
        ]);
    }

    /**
     * @param array<int, string> $approvedSources
     */
    private function buildGovernanceReinforcedPrompt(string $basePrompt, array $approvedSources): string
    {
        if ($approvedSources === []) {
            return $basePrompt;
        }

        $lines = [
            rtrim($basePrompt),
            '',
            '[FONTES APROVADAS (USO OBRIGATORIO)]',
            'Use prioritariamente estas fontes aprovadas na geração SWOT:',
        ];

        foreach (array_values($approvedSources) as $index => $source) {
            $lines[] = sprintf('%d. %s', $index + 1, $source);
        }

        $lines[] = 'Cada item dos cards deve referenciar evidência dessas fontes quando aplicável em source_name/source_url.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createFactor(SwotAnalysis $analysis, string $customerUuid, array $payload): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);

        $quadrant = $this->normalizeFactorQuadrant($payload['quadrant'] ?? '');
        $definition = self::FACTOR_CARD_DEFINITIONS[$quadrant] ?? null;
        if ($definition === null) {
            throw new \InvalidArgumentException('Invalid factor quadrant.');
        }

        $card = $this->getOrCreateCard($analysis, 'factors', $definition);

        $nextOrder = (int) ($card->items()->max('sort_order') ?? 0) + 1;

        $title = $this->sanitizeString($payload['title'] ?? null);
        if ($title === null) {
            throw new \InvalidArgumentException('title is required.');
        }

        $sourceName = $this->sanitizeSourceName($payload['source_name'] ?? null);
        $sourceUrl = $this->normalizeExternalUrl($payload['source_url'] ?? $payload['swot_link'] ?? null);

        $card->items()->create([
            'item_key' => null,
            'title' => $title,
            'description' => $this->sanitizeString($payload['description'] ?? null),
            'tag' => $this->sanitizeString($payload['tag'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'impact' => $this->sanitizeString($payload['impact'] ?? null),
            'dimension' => $this->sanitizeString($payload['dimension'] ?? null),
            'swot_link' => $sourceUrl,
            'sort_order' => $nextOrder,
            'metadata' => [
                'origin' => 'manual',
                'source_name' => $sourceName,
                'source_url' => $sourceUrl,
                'sources' => $this->normalizeSourceReferences($payload['sources'] ?? []),
            ],
        ]);

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateFactor(
        SwotAnalysis $analysis,
        SwotCardItem $item,
        string $customerUuid,
        array $payload
    ): array {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'factors');

        $sourceUrlProvided = array_key_exists('source_url', $payload) || array_key_exists('swot_link', $payload);
        $sourceUrl = $this->normalizeExternalUrl($payload['source_url'] ?? $payload['swot_link'] ?? null);

        $updates = array_filter([
            'title' => $this->sanitizeString($payload['title'] ?? null),
            'description' => $this->sanitizeString($payload['description'] ?? null),
            'tag' => $this->sanitizeString($payload['tag'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'impact' => $this->sanitizeString($payload['impact'] ?? null),
            'dimension' => $this->sanitizeString($payload['dimension'] ?? null),
        ], static fn ($value) => $value !== null);
        if ($sourceUrlProvided) {
            $updates['swot_link'] = $sourceUrl;
        }
        $item->fill($updates);

        $this->applySourceMetadataPatch(
            $item,
            $payload,
            defaultOrigin: 'manual',
            sourceUrl: $sourceUrlProvided ? $sourceUrl : null,
        );

        $item->save();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteFactor(SwotAnalysis $analysis, SwotCardItem $item, string $customerUuid): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'factors');

        $item->delete();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateRecommendation(
        SwotAnalysis $analysis,
        SwotCardItem $item,
        string $customerUuid,
        array $payload
    ): array {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'recommendations');

        $sourceUrlProvided = array_key_exists('source_url', $payload) || array_key_exists('swot_link', $payload);
        $sourceUrl = $this->normalizeExternalUrl($payload['source_url'] ?? $payload['swot_link'] ?? null);

        $updates = array_filter([
            'title' => $this->sanitizeString($payload['title'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'period' => $this->sanitizeString($payload['period_label'] ?? $payload['period'] ?? null),
        ], static fn ($value) => $value !== null);
        if ($sourceUrlProvided) {
            $updates['swot_link'] = $sourceUrl;
        }
        $item->fill($updates);

        $this->applySourceMetadataPatch(
            $item,
            $payload,
            defaultOrigin: 'manual',
            sourceUrl: $sourceUrlProvided ? $sourceUrl : null,
        );

        $item->save();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRecommendation(SwotAnalysis $analysis, SwotCardItem $item, string $customerUuid): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'recommendations');

        $item->delete();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateAction(
        SwotAnalysis $analysis,
        SwotCardItem $item,
        string $customerUuid,
        array $payload
    ): array {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'action_plan');

        $sourceUrlProvided = array_key_exists('source_url', $payload) || array_key_exists('swot_link', $payload);
        $sourceUrl = $this->normalizeExternalUrl($payload['source_url'] ?? $payload['swot_link'] ?? null);

        $updates = array_filter([
            'title' => $this->sanitizeString($payload['strategic_action'] ?? $payload['title'] ?? null),
            'period' => $this->sanitizeString($payload['period'] ?? null),
            'kpi' => $this->sanitizeString($payload['kpi'] ?? null),
            'owner' => $this->sanitizeString($payload['owner'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
        ], static fn ($value) => $value !== null);
        if ($sourceUrlProvided) {
            $updates['swot_link'] = $sourceUrl;
        }
        $item->fill($updates);

        $this->applySourceMetadataPatch(
            $item,
            $payload,
            defaultOrigin: 'manual',
            sourceUrl: $sourceUrlProvided ? $sourceUrl : null,
        );

        $item->save();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteAction(SwotAnalysis $analysis, SwotCardItem $item, string $customerUuid): array
    {
        $this->assertAnalysisCustomerScope($analysis, $customerUuid);
        $this->assertItemBelongsToAnalysisGroup($analysis, $item, 'action_plan');

        $item->delete();

        return $this->formatAnalysisPayload($analysis->fresh(['cards.items']));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applySourceMetadataPatch(
        SwotCardItem $item,
        array $payload,
        string $defaultOrigin = 'manual',
        ?string $sourceUrl = null
    ): void {
        $sourceNameProvided = array_key_exists('source_name', $payload);
        $sourceUrlProvided = array_key_exists('source_url', $payload) || array_key_exists('swot_link', $payload);

        if (! $sourceNameProvided && ! $sourceUrlProvided) {
            return;
        }

        $metadata = is_array($item->metadata) ? $item->metadata : [];
        if (! isset($metadata['origin']) || ! is_string($metadata['origin']) || trim($metadata['origin']) === '') {
            $metadata['origin'] = $defaultOrigin;
        }

        if ($sourceNameProvided) {
            $metadata['source_name'] = $this->sanitizeSourceName($payload['source_name'] ?? null);
        }
        if ($sourceUrlProvided) {
            $metadata['source_url'] = $sourceUrl;
        }
        if (array_key_exists('sources', $payload)) {
            $metadata['sources'] = $this->normalizeSourceReferences($payload['sources'] ?? []);
        }

        $item->metadata = $metadata;
    }

    /**
     * @param array<string, mixed> $structured
     */
    private function syncEditableCards(SwotAnalysis $analysis, array $structured): void
    {
        $this->syncFactorCards($analysis, $structured['factors']);
        $this->syncRecommendationCards($analysis, $structured['recommendations']);
        $this->syncActionPlanCards($analysis, $structured['action_plan']);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $factors
     */
    private function syncFactorCards(SwotAnalysis $analysis, array $factors): void
    {
        foreach (self::FACTOR_CARD_DEFINITIONS as $key => $definition) {
            $card = $this->getOrCreateCard($analysis, 'factors', $definition);
            $card->items()->delete();

            $items = Arr::get($factors, $key, []);
            if (! is_array($items)) {
                $items = [];
            }

            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = $this->sanitizeString($item['title'] ?? null);
                if ($title === null) {
                    continue;
                }

                $card->items()->create([
                    'item_key' => $this->sanitizeString($item['item_key'] ?? null),
                    'title' => $title,
                    'description' => $this->sanitizeString($item['description'] ?? null),
                    'tag' => $this->sanitizeString($item['tag'] ?? null),
                    'priority' => $this->sanitizeString($item['priority'] ?? null),
                    'impact' => $this->sanitizeString($item['impact'] ?? null),
                    'dimension' => $this->sanitizeString($item['dimension'] ?? null),
                    'swot_link' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeSourceName($item['source_name'] ?? null),
                        'source_url' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                        'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                    ],
                ]);
            }
        }
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $recommendations
     */
    private function syncRecommendationCards(SwotAnalysis $analysis, array $recommendations): void
    {
        foreach (self::RECOMMENDATION_CARD_DEFINITIONS as $key => $definition) {
            $card = $this->getOrCreateCard($analysis, 'recommendations', $definition);
            $card->items()->delete();

            $items = Arr::get($recommendations, $key, []);
            if (! is_array($items)) {
                $items = [];
            }

            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = $this->sanitizeString($item['title'] ?? null);
                if ($title === null) {
                    continue;
                }

                $card->items()->create([
                    'item_key' => $this->sanitizeString($item['item_key'] ?? null),
                    'title' => $title,
                    'priority' => $this->sanitizeString($item['priority'] ?? null),
                    'period' => $this->sanitizeString($item['period_label'] ?? $item['period'] ?? null),
                    'swot_link' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeSourceName($item['source_name'] ?? null),
                        'source_url' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                        'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                    ],
                ]);
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $actionPlan
     */
    private function syncActionPlanCards(SwotAnalysis $analysis, array $actionPlan): void
    {
        $actionPlanByArea = [];
        foreach ($actionPlan as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $areaKey = $this->sanitizeString($entry['area_key'] ?? null);
            if ($areaKey === null) {
                continue;
            }

            $actionPlanByArea[$areaKey] = $entry;
        }

        foreach (self::ACTION_PLAN_CARD_DEFINITIONS as $areaKey => $definition) {
            $card = $this->getOrCreateCard($analysis, 'action_plan', $definition);
            $card->items()->delete();

            $entry = $actionPlanByArea[$areaKey] ?? [];
            if (is_array($entry) && isset($entry['title']) && is_string($entry['title']) && trim($entry['title']) !== '') {
                $card->title = trim($entry['title']);
                $card->save();
            }

            $items = Arr::get($entry, 'items', []);
            if (! is_array($items)) {
                $items = [];
            }

            foreach (array_values($items) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $title = $this->sanitizeString($item['strategic_action'] ?? $item['title'] ?? null);
                if ($title === null) {
                    continue;
                }

                $card->items()->create([
                    'item_key' => $this->sanitizeString($item['item_key'] ?? null),
                    'title' => $title,
                    'swot_link' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                    'period' => $this->sanitizeString($item['period'] ?? null),
                    'kpi' => $this->sanitizeString($item['kpi'] ?? null),
                    'owner' => $this->sanitizeString($item['owner'] ?? null),
                    'priority' => $this->sanitizeString($item['priority'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeSourceName($item['source_name'] ?? null),
                        'source_url' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                        'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                    ],
                ]);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function getOrCreateCard(SwotAnalysis $analysis, string $cardGroup, array $definition): SwotCard
    {
        return SwotCard::query()->withTrashed()->updateOrCreate(
            [
                'analysis_id' => $analysis->id,
                'card_key' => $definition['card_key'],
            ],
            [
                'deleted_at' => null,
                'card_group' => $cardGroup,
                'title' => $definition['title'],
                'subtitle' => $definition['subtitle'] ?? null,
                'sort_order' => $definition['sort_order'] ?? 0,
                'is_editable' => true,
                'metadata' => [
                    'source' => 'swot',
                ],
            ]
        );
    }

    private function assertAnalysisCustomerScope(SwotAnalysis $analysis, string $customerUuid): void
    {
        if (! hash_equals((string) $analysis->customer_uuid, $customerUuid)) {
            throw new AuthorizationException('You are not allowed to access this analysis.');
        }
    }

    private function assertItemBelongsToAnalysisGroup(
        SwotAnalysis $analysis,
        SwotCardItem $item,
        string $expectedGroup
    ): void {
        $card = $item->card;
        if (
            $card === null ||
            (int) $card->analysis_id !== (int) $analysis->id ||
            ! hash_equals((string) $card->card_group, $expectedGroup)
        ) {
            throw new AuthorizationException('You are not allowed to modify this item.');
        }
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function formatAnalysisPayload(SwotAnalysis $analysis, array $options = []): array
    {
        $cards = $analysis->cards
            ->whereNull('deleted_at')
            ->sortBy('sort_order')
            ->values();
        $historicalCards = $this->loadHistoricalCardsForCustomer($analysis);

        $topFactorsLimit = $this->normalizePositiveInt($options['top_factors_limit'] ?? null);
        $bottomFactorsLimit = $this->normalizePositiveInt($options['bottom_factors_limit'] ?? null);
        $recommendationsLimit = $this->normalizePositiveInt($options['recommendations_limit'] ?? null);
        $sourceCatalog = $this->buildSourceCatalog(
            (string) $analysis->customer_uuid,
            $this->sanitizeString($analysis->trend_analysis_run_id)
        );
        $analysisWideSources = array_values(array_map(
            fn (array $source): array => [
                'source_name' => $this->sanitizeSourceName($source['source_name'] ?? null),
                'source_url' => $this->normalizeExternalUrl($source['source_url'] ?? null),
                'source_origin' => $this->sanitizeString($source['source_origin'] ?? null),
                'source_category' => $this->sanitizeString($source['source_category'] ?? null),
            ],
            array_filter(
                $sourceCatalog['ordered'] ?? [],
                static fn (mixed $source): bool => is_array($source)
            )
        ));

        $factors = [
            'strengths' => [],
            'opportunities' => [],
            'weaknesses' => [],
            'threats' => [],
        ];
        $factorSections = [];
        $factorCounts = [
            'strengths' => 0,
            'opportunities' => 0,
            'weaknesses' => 0,
            'threats' => 0,
        ];

        foreach (self::FACTOR_CARD_DEFINITIONS as $quadrant => $definition) {
            /** @var SwotCard|null $card */
            $card = $cards->firstWhere('card_key', $definition['card_key']);
            $historicalGroupCards = $historicalCards->get($definition['card_key'], collect());
            $factorSections[$quadrant] = [
                'title' => $card?->title ?: $definition['title'],
                'subtitle' => $card?->subtitle ?: ($definition['subtitle'] ?? ''),
            ];
            if ($historicalGroupCards->isEmpty()) {
                continue;
            }

            $items = $historicalGroupCards
                ->flatMap(fn (SwotCard $historicalCard) => $this->sliceHistoricalCardItems(
                    $historicalCard,
                    self::HISTORICAL_CARD_ITEMS_PER_ANALYSIS
                ))
                ->map(function (SwotCardItem $item) use ($sourceCatalog, $analysisWideSources): array {
                    $sources = $this->resolveSourceReferencesList(
                        Arr::get($item->metadata ?? [], 'sources'),
                        Arr::get($item->metadata ?? [], 'source_name'),
                        $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                        $sourceCatalog,
                        $analysisWideSources
                    );
                    $primarySource = $sources[0] ?? $this->resolveSourceReference(
                        Arr::get($item->metadata ?? [], 'source_name'),
                        $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                        $sourceCatalog
                    );

                    return [
                        'id' => $item->uuid,
                        'title' => $item->title,
                        'description' => $item->description,
                        'tag' => $item->tag,
                        'priority' => $item->priority,
                        'impact' => $item->impact,
                        'dimension' => $item->dimension,
                        'source_url' => $primarySource['source_url'],
                        'source_name' => $primarySource['source_name'],
                        'source_origin' => $primarySource['source_origin'],
                        'source_category' => $primarySource['source_category'],
                        'sources' => $sources,
                    ];
                })
                ->all();

            $factorCounts[$quadrant] = count($items);
            $limit = in_array($quadrant, ['strengths', 'opportunities'], true)
                ? $topFactorsLimit
                : $bottomFactorsLimit;
            if ($limit !== null) {
                $items = array_slice($items, 0, $limit);
            }

            $factors[$quadrant] = $items;
        }

        $recommendations = [
            'short_term' => [],
            'mid_term' => [],
            'long_term' => [],
        ];
        $recommendationSections = [];
        $recommendationCounts = [
            'short_term' => 0,
            'mid_term' => 0,
            'long_term' => 0,
        ];

        foreach (self::RECOMMENDATION_CARD_DEFINITIONS as $bucket => $definition) {
            /** @var SwotCard|null $card */
            $card = $cards->firstWhere('card_key', $definition['card_key']);
            $historicalGroupCards = $historicalCards->get($definition['card_key'], collect());
            $recommendationSections[$bucket] = [
                'title' => $card?->title ?: $definition['title'],
                'subtitle' => $card?->subtitle ?: ($definition['subtitle'] ?? ''),
            ];
            if ($historicalGroupCards->isEmpty()) {
                continue;
            }

            $items = $historicalGroupCards
                ->flatMap(fn (SwotCard $historicalCard) => $this->sliceHistoricalCardItems(
                    $historicalCard,
                    self::HISTORICAL_CARD_ITEMS_PER_ANALYSIS
                ))
                ->map(function (SwotCardItem $item) use ($sourceCatalog, $analysisWideSources): array {
                    $sources = $this->resolveSourceReferencesList(
                        Arr::get($item->metadata ?? [], 'sources'),
                        Arr::get($item->metadata ?? [], 'source_name'),
                        $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                        $sourceCatalog,
                        $analysisWideSources
                    );
                    $primarySource = $sources[0] ?? $this->resolveSourceReference(
                        Arr::get($item->metadata ?? [], 'source_name'),
                        $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                        $sourceCatalog
                    );

                    return [
                        'id' => $item->uuid,
                        'title' => $item->title,
                        'priority' => $item->priority,
                        'period_label' => $item->period,
                        'source_url' => $primarySource['source_url'],
                        'source_name' => $primarySource['source_name'],
                        'source_origin' => $primarySource['source_origin'],
                        'source_category' => $primarySource['source_category'],
                        'sources' => $sources,
                    ];
                })
                ->all();

            $recommendationCounts[$bucket] = count($items);
            if ($recommendationsLimit !== null) {
                $items = array_slice($items, 0, $recommendationsLimit);
            }

            $recommendations[$bucket] = $items;
        }

        $actionPlan = [];
        foreach (self::ACTION_PLAN_CARD_DEFINITIONS as $areaKey => $definition) {
            /** @var SwotCard|null $card */
            $card = $cards->firstWhere('card_key', $definition['card_key']);
            $historicalGroupCards = $historicalCards->get($definition['card_key'], collect());
            if ($historicalGroupCards->isEmpty()) {
                continue;
            }

            $actionPlan[] = [
                'id' => $card?->uuid ?? $definition['card_key'],
                'area_key' => $areaKey,
                'title' => $card?->title ?? $definition['title'],
                'items' => $historicalGroupCards
                    ->flatMap(fn (SwotCard $historicalCard) => $this->sliceHistoricalCardItems(
                        $historicalCard,
                        self::HISTORICAL_TABLE_ITEMS_PER_ANALYSIS
                    ))
                    ->map(function (SwotCardItem $item) use ($sourceCatalog, $analysisWideSources): array {
                        $sources = $this->resolveSourceReferencesList(
                            Arr::get($item->metadata ?? [], 'sources'),
                            Arr::get($item->metadata ?? [], 'source_name'),
                            $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                            $sourceCatalog,
                            $analysisWideSources
                        );
                        $primarySource = $sources[0] ?? $this->resolveSourceReference(
                            Arr::get($item->metadata ?? [], 'source_name'),
                            $item->swot_link ?: Arr::get($item->metadata ?? [], 'source_url'),
                            $sourceCatalog
                        );

                        return [
                            'id' => $item->uuid,
                            'strategic_action' => $item->title,
                            'swot_link' => $primarySource['source_url'],
                            'source_name' => $primarySource['source_name'],
                            'source_origin' => $primarySource['source_origin'],
                            'source_category' => $primarySource['source_category'],
                            'sources' => $sources,
                            'period' => $item->period,
                            'kpi' => $item->kpi,
                            'owner' => $item->owner,
                            'priority' => $item->priority,
                        ];
                    })
                    ->all(),
            ];
        }

        $rawStructured = $analysis->raw_ai_payload['structured'] ?? null;
        $strategicImplications = [];
        $strategicNote = '';
        if (is_array($rawStructured)) {
            $strategicImplications = $this->normalizeStrategicImplications(
                $rawStructured['strategic_implications'] ?? []
            );
            $strategicImplications = $this->applySourceCatalogToStrategicImplications(
                $strategicImplications,
                $sourceCatalog,
                $analysisWideSources
            );
            $strategicImplications = $this->limitStrategicImplicationItems(
                $strategicImplications,
                3
            );
            $strategicNote = $this->sanitizeString($rawStructured['strategic_note'] ?? null) ?? '';
        }

        return [
            'analysis' => [
                'uuid' => $analysis->uuid,
                'analysis_title' => $analysis->analysis_title,
                'status' => $analysis->status,
                'customer_uuid' => $analysis->customer_uuid,
                'trend_analysis_run_id' => $analysis->trend_analysis_run_id,
                'brain_conversation_id' => $analysis->brain_conversation_id,
                'generated_at' => optional($analysis->generated_at)->toIso8601String(),
                'created_at' => optional($analysis->created_at)->toIso8601String(),
                'updated_at' => optional($analysis->updated_at)->toIso8601String(),
            ],
            'content' => [
                'analysis_summary' => $analysis->analysis_summary,
                'factors' => $factors,
                'factor_sections' => $factorSections,
                'recommendations' => $recommendations,
                'recommendation_sections' => $recommendationSections,
                'action_plan' => $actionPlan,
                'strategic_implications' => $strategicImplications,
                'strategic_note' => $strategicNote,
            ],
            'meta' => [
                'factors' => [
                    'counts' => $factorCounts,
                    'limits' => [
                        'top' => $topFactorsLimit,
                        'bottom' => $bottomFactorsLimit,
                    ],
                    'has_more' => [
                        'top' => ($factorCounts['strengths'] > count($factors['strengths']))
                            || ($factorCounts['opportunities'] > count($factors['opportunities'])),
                        'bottom' => ($factorCounts['weaknesses'] > count($factors['weaknesses']))
                            || ($factorCounts['threats'] > count($factors['threats'])),
                    ],
                ],
                'recommendations' => [
                    'counts' => $recommendationCounts,
                    'limit' => $recommendationsLimit,
                    'has_more' => ($recommendationCounts['short_term'] > count($recommendations['short_term']))
                        || ($recommendationCounts['mid_term'] > count($recommendations['mid_term']))
                        || ($recommendationCounts['long_term'] > count($recommendations['long_term'])),
                ],
            ],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, SwotCard>>
     */
    private function loadHistoricalCardsForCustomer(SwotAnalysis $analysis)
    {
        return SwotCard::query()
            ->select('swot_cards.*')
            ->join('swot_analyses', 'swot_analyses.id', '=', 'swot_cards.analysis_id')
            ->where('swot_analyses.customer_uuid', $analysis->customer_uuid)
            ->whereNull('swot_analyses.deleted_at')
            ->whereNull('swot_cards.deleted_at')
            ->with(['items' => fn ($query) => $query->whereNull('deleted_at')->orderBy('sort_order')])
            ->orderByDesc('swot_analyses.generated_at')
            ->orderByDesc('swot_analyses.created_at')
            ->orderBy('swot_cards.sort_order')
            ->get()
            ->groupBy('card_key');
    }

    /**
     * @return \Illuminate\Support\Collection<int, SwotCardItem>
     */
    private function sliceHistoricalCardItems(SwotCard $card, int $limit)
    {
        return $card->items
            ->whereNull('deleted_at')
            ->sortBy('sort_order')
            ->values()
            ->slice(0, max(1, $limit))
            ->values();
    }

    /**
     * @param array<string, mixed> $brainFilters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function requestStructuredGeneration(string $customerUuid, string $prompt, array $brainFilters): array
    {
        $basePrompt = trim($prompt);
        $conversationId = null;
        $toolsSeen = [];
        $blockResponses = [];

        $factorsBlock = $this->requestSwotJsonBlock(
            $customerUuid,
            $brainFilters,
            $conversationId,
            $toolsSeen,
            'factors',
            $this->buildSwotFactorsBlockPrompt($basePrompt),
            '{"factors":{"strengths":[...],"opportunities":[...],"weaknesses":[...],"threats":[...]}}'
        );
        $conversationId = $factorsBlock['conversation_id'];
        $toolsSeen = $factorsBlock['tools_seen'];
        $blockResponses[] = ['block' => 'factors', 'response' => $factorsBlock['response']];

        $missingTools = $this->missingRequiredGenerationToolsFromList($toolsSeen);
        if ($missingTools !== []) {
            $factorsBlock = $this->requestSwotJsonBlock(
                $customerUuid,
                $brainFilters,
                $conversationId,
                $toolsSeen,
                'factors',
                $this->buildSwotFactorsBlockPrompt($basePrompt, $missingTools),
                '{"factors":{"strengths":[...],"opportunities":[...],"weaknesses":[...],"threats":[...]}}'
            );
            $conversationId = $factorsBlock['conversation_id'];
            $toolsSeen = $factorsBlock['tools_seen'];
            $blockResponses[] = ['block' => 'factors-retry', 'response' => $factorsBlock['response']];
            $missingTools = $this->missingRequiredGenerationToolsFromList($toolsSeen);
            if ($missingTools !== []) {
                throw new RuntimeException(
                    'SWOT generation failed quality gates: Ferramentas obrigatórias ausentes: '.implode(', ', $missingTools)
                );
            }
        }

        $overviewBlock = $this->requestSwotJsonBlock(
            $customerUuid,
            $brainFilters,
            $conversationId,
            $toolsSeen,
            'overview',
            $this->buildSwotOverviewBlockPrompt(),
            '{"analysis_summary":"string","strategic_note":"string"}'
        );
        $conversationId = $overviewBlock['conversation_id'];
        $toolsSeen = $overviewBlock['tools_seen'];
        $blockResponses[] = ['block' => 'overview', 'response' => $overviewBlock['response']];

        $recommendationsBlock = $this->requestSwotJsonBlock(
            $customerUuid,
            $brainFilters,
            $conversationId,
            $toolsSeen,
            'recommendations',
            $this->buildSwotRecommendationsBlockPrompt(),
            '{"recommendations":{"short_term":[...],"mid_term":[...],"long_term":[...]}}'
        );
        $conversationId = $recommendationsBlock['conversation_id'];
        $toolsSeen = $recommendationsBlock['tools_seen'];
        $blockResponses[] = ['block' => 'recommendations', 'response' => $recommendationsBlock['response']];

        $actionPlanBlock = $this->requestSwotJsonBlock(
            $customerUuid,
            $brainFilters,
            $conversationId,
            $toolsSeen,
            'action_plan',
            $this->buildSwotActionPlanBlockPrompt(),
            '{"action_plan":[{"area_key":"...","title":"...","items":[...]}]}'
        );
        $conversationId = $actionPlanBlock['conversation_id'];
        $toolsSeen = $actionPlanBlock['tools_seen'];
        $blockResponses[] = ['block' => 'action_plan', 'response' => $actionPlanBlock['response']];
        $actionPlan = $this->normalizeActionPlan($actionPlanBlock['json']['action_plan'] ?? $actionPlanBlock['json']);
        if (! $this->isActionPlanStructurallyComplete($actionPlan)) {
            $actionPlanBlock = $this->requestSwotJsonBlock(
                $customerUuid,
                $brainFilters,
                $conversationId,
                $toolsSeen,
                'action_plan',
                $this->buildSwotActionPlanBlockPrompt()."\n\n".$this->buildBlockCompletenessPrompt(
                    'action_plan',
                    [
                        'Retorne obrigatoriamente as 6 areas fixas exigidas.',
                        'Cada area precisa conter no minimo 10 items validos.',
                        'Nao omita areas mesmo quando houver pouca evidencia; use o corpus interno consolidado e complemente com web market apenas quando necessário.',
                    ]
                ),
                '{"action_plan":[{"area_key":"...","title":"...","items":[...]}]}'
            );
            $conversationId = $actionPlanBlock['conversation_id'];
            $toolsSeen = $actionPlanBlock['tools_seen'];
            $blockResponses[] = ['block' => 'action_plan-retry', 'response' => $actionPlanBlock['response']];
            $actionPlan = $this->normalizeActionPlan($actionPlanBlock['json']['action_plan'] ?? $actionPlanBlock['json']);
        }

        $implicationsBlock = $this->requestSwotJsonBlock(
            $customerUuid,
            $brainFilters,
            $conversationId,
            $toolsSeen,
            'strategic_implications',
            $this->buildSwotStrategicImplicationsBlockPrompt(),
            '{"strategic_implications":[{"id":"so-accelerate|st-defend|wo-invest|wt-mitigate","items":[...]}]}'
        );
        $conversationId = $implicationsBlock['conversation_id'];
        $toolsSeen = $implicationsBlock['tools_seen'];
        $blockResponses[] = ['block' => 'strategic_implications', 'response' => $implicationsBlock['response']];
        $strategicImplications = $this->normalizeStrategicImplications(
            $implicationsBlock['json']['strategic_implications'] ?? $implicationsBlock['json']
        );
        if (! $this->isStrategicImplicationsStructurallyComplete($strategicImplications)) {
            $implicationsBlock = $this->requestSwotJsonBlock(
                $customerUuid,
                $brainFilters,
                $conversationId,
                $toolsSeen,
                'strategic_implications',
                $this->buildSwotStrategicImplicationsBlockPrompt()."\n\n".$this->buildBlockCompletenessPrompt(
                    'strategic_implications',
                    [
                        'Retorne obrigatoriamente os 4 grupos fixos: so-accelerate, st-defend, wo-invest, wt-mitigate.',
                        'Cada grupo precisa conter no minimo 10 items validos.',
                        'Nao colapse grupos e nao devolva grupos vazios.',
                    ]
                ),
                '{"strategic_implications":[{"id":"so-accelerate|st-defend|wo-invest|wt-mitigate","items":[...]}]}'
            );
            $conversationId = $implicationsBlock['conversation_id'];
            $toolsSeen = $implicationsBlock['tools_seen'];
            $blockResponses[] = ['block' => 'strategic_implications-retry', 'response' => $implicationsBlock['response']];
            $strategicImplications = $this->normalizeStrategicImplications(
                $implicationsBlock['json']['strategic_implications'] ?? $implicationsBlock['json']
            );
        }

        $overview = $this->normalizeOverviewBlock($overviewBlock['json']);
        $factors = $this->normalizeFactorsBlock($factorsBlock['json']);
        $recommendations = $this->normalizeRecommendationsBlock($recommendationsBlock['json']);

        $structuredAnswer = [
            'analysis_summary' => $overview['analysis_summary'],
            'factors' => $factors,
            'recommendations' => $recommendations,
            'action_plan' => $actionPlan,
            'strategic_implications' => $strategicImplications,
            'strategic_note' => $overview['strategic_note'],
        ];

        $qualityIssues = $this->collectStructuredQualityIssues($structuredAnswer);
        if ($qualityIssues !== []) {
            throw new RuntimeException('SWOT generation failed quality gates: '.implode(' | ', $qualityIssues));
        }

        return [[
            'conversation_id' => $conversationId,
            'sources' => [
                'tools_used' => $toolsSeen,
                'steps' => count($blockResponses),
            ],
            'blocks' => $blockResponses,
        ], $structuredAnswer];
    }

    /**
     * @param array<string, mixed> $brainFilters
     * @param array<int, string> $toolsSeen
     * @return array{
     *     response: array<string, mixed>,
     *     json: array<string, mixed>,
     *     conversation_id: string|null,
     *     tools_seen: array<int, string>
     * }
     */
    private function requestSwotJsonBlock(
        string $customerUuid,
        array $brainFilters,
        ?string $conversationId,
        array $toolsSeen,
        string $blockId,
        string $question,
        string $schemaDescription
    ): array {
        $lastIssue = 'Unknown block parsing error.';

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $requestPayload = [
                'question' => $attempt === 1
                    ? $question
                    : $question."\n\n".$this->buildBlockJsonRepairPrompt($schemaDescription, $lastIssue),
                'customer_uuid' => $customerUuid,
                'filters' => $brainFilters,
            ];
            if (is_string($conversationId) && $conversationId !== '') {
                $requestPayload['conversation_id'] = $conversationId;
            }

            try {
                $response = $this->brainClient->ask($requestPayload);
            } catch (\Throwable $exception) {
                $lastIssue = $exception->getMessage();
                continue;
            }
            $conversationId = $this->sanitizeString($response['conversation_id'] ?? null) ?? $conversationId;
            $toolsSeen = $this->mergeToolsUsed($toolsSeen, $this->extractToolsUsedFromBrainResponse($response));

            $answer = $response['answer'] ?? null;
            if (! is_string($answer) || trim($answer) === '') {
                $lastIssue = sprintf('Block "%s" returned empty answer.', $blockId);
                continue;
            }

            try {
                $decoded = $this->decodeAnswerJson($answer);
            } catch (\Throwable $exception) {
                $lastIssue = $exception->getMessage();
                continue;
            }

            return [
                'response' => $response,
                'json' => $decoded,
                'conversation_id' => $conversationId,
                'tools_seen' => $toolsSeen,
            ];
        }

        throw new RuntimeException(sprintf(
            'SWOT generation failed on block "%s": %s',
            $blockId,
            $lastIssue
        ));
    }

    /**
     * @param array<string, mixed> $brainResponse
     * @return array<int, string>
     */
    private function missingRequiredGenerationTools(array $brainResponse): array
    {
        $sourcesUsed = Arr::get($brainResponse, 'sources_used', []);
        if (! is_array($sourcesUsed)) {
            return self::REQUIRED_GENERATION_TOOLS;
        }

        $used = array_values(array_filter(array_map(
            static fn (mixed $tool): string => strtolower(trim((string) $tool)),
            $sourcesUsed
        )));

        $missing = [];
        foreach (self::REQUIRED_GENERATION_TOOLS as $requiredTool) {
            if (! in_array(strtolower($requiredTool), $used, true)) {
                $missing[] = $requiredTool;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, string> $seen
     * @param mixed $rawTools
     * @return array<int, string>
     */
    private function mergeToolsUsed(array $seen, mixed $rawTools): array
    {
        $merged = $seen;
        if (! is_array($rawTools)) {
            return array_values(array_unique($merged));
        }

        foreach ($rawTools as $tool) {
            $normalized = strtolower(trim((string) $tool));
            if ($normalized === '') {
                continue;
            }
            $merged[] = $normalized;
        }

        return array_values(array_unique($merged));
    }

    /**
     * @param array<string, mixed> $brainResponse
     * @return array<int, string>
     */
    private function extractToolsUsedFromBrainResponse(array $brainResponse): array
    {
        $fromTopLevel = Arr::get($brainResponse, 'sources_used', []);
        if (is_array($fromTopLevel) && $fromTopLevel !== []) {
            return array_values($fromTopLevel);
        }

        $fromSourcesObject = Arr::get($brainResponse, 'sources.tools_used', []);
        if (is_array($fromSourcesObject)) {
            return array_values($fromSourcesObject);
        }

        return [];
    }

    /**
     * @param array<int, string> $used
     * @return array<int, string>
     */
    private function missingRequiredGenerationToolsFromList(array $used): array
    {
        $missing = [];
        foreach (self::REQUIRED_GENERATION_TOOLS as $requiredTool) {
            if (! in_array(strtolower($requiredTool), $used, true)) {
                $missing[] = $requiredTool;
            }
        }

        return $missing;
    }

    /**
     * @param array<int, array<string, mixed>> $actionPlan
     */
    private function isActionPlanStructurallyComplete(array $actionPlan): bool
    {
        if (count($actionPlan) < self::MIN_ACTION_AREAS) {
            return false;
        }

        $areas = [];
        foreach ($actionPlan as $entry) {
            if (! is_array($entry)) {
                return false;
            }
            $areaKey = $this->sanitizeString($entry['area_key'] ?? null);
            if ($areaKey === null) {
                return false;
            }
            $areas[$areaKey] = true;
            $items = $entry['items'] ?? [];
            if (! is_array($items) || count($items) < self::MIN_ACTION_ITEMS_PER_AREA) {
                return false;
            }
        }

        foreach (array_keys(self::ACTION_PLAN_CARD_DEFINITIONS) as $requiredArea) {
            if (! isset($areas[$requiredArea])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private function isStrategicImplicationsStructurallyComplete(array $groups): bool
    {
        if (count($groups) < self::MIN_IMPLICATION_GROUPS) {
            return false;
        }

        $groupIds = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                return false;
            }
            $id = $this->sanitizeString($group['id'] ?? null);
            if ($id === null) {
                return false;
            }
            $groupIds[$id] = true;
            $items = $group['items'] ?? [];
            if (! is_array($items) || count($items) < self::MIN_IMPLICATION_ITEMS_PER_GROUP) {
                return false;
            }
        }

        foreach (self::REQUIRED_IMPLICATION_GROUP_KEYS as $requiredGroup) {
            if (! isset($groupIds[$requiredGroup])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $missingTools
     * @param array<int, string> $qualityIssues
     */
    private function buildGenerationReinforcementPrompt(array $missingTools, array $qualityIssues): string
    {
        $lines = [
            '[REFORCO OBRIGATORIO]',
            'Refaca TODA a resposta em JSON valido, mantendo EXATAMENTE o schema solicitado anteriormente.',
            'Objeto JSON deve conter APENAS as chaves: analysis_summary, factors, recommendations, action_plan, strategic_implications, strategic_note.',
            'Nao faça perguntas ao usuario e nao responda em formato conversacional.',
            'Se nao houver mencoes suficientes da marca, use sinais de mercado e segmento via search_web_market para preencher os blocos.',
        ];

        if ($missingTools !== []) {
            $lines[] = '- Execute obrigatoriamente as tools faltantes: '.implode(', ', $missingTools).'.';
        }

        foreach ($qualityIssues as $issue) {
            $lines[] = '- '.$issue;
        }

        $lines[] = '- Nao use texto generico, placeholders ou frases-modelo.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $missingTools
     */
    private function buildSwotFactorsBlockPrompt(string $basePrompt, array $missingTools = []): string
    {
        $lines = [
            '[SWOT BLOCK | FACTORS]',
            'Retorne APENAS JSON valido (sem markdown e sem texto fora do JSON).',
            'Use contexto da analise abaixo e gere SOMENTE o bloco factors.',
            '',
            '[CONTEXTO BASE]',
            $basePrompt,
            '',
            'Regras obrigatorias:',
            '- Execute obrigatoriamente: search_swot_generation_context e search_web_market.',
            '- Use search_swot_generation_context como corpus interno principal, cobrindo o conjunto das fontes elegiveis no banco.',
            '- Use search_clipping e search_social_listening apenas para aprofundar lacunas pontuais, quando realmente necessario.',
            '- Mesmo com corpus interno insuficiente, complete com evidencias de web market.',
            '- Minimo 3 itens em strengths, opportunities, weaknesses e threats.',
            '- Cada item deve conter: title, description, priority, impact, tag, dimension, source_name, source_url.',
            '- source_url deve ser URL externa HTTP/HTTPS valida.',
            '',
            'Schema de resposta:',
            '{"factors":{"strengths":[{"title":"string","description":"string","priority":"Alta|Media|Baixa","impact":"Alta|Media|Baixa","tag":"string","dimension":"string","source_name":"string","source_url":"https://..."}],"opportunities":[...],"weaknesses":[...],"threats":[...]}}',
        ];

        if ($missingTools !== []) {
            $lines[] = 'Tools ainda ausentes e obrigatorias: '.implode(', ', $missingTools).'.';
        }

        return implode("\n", $lines);
    }

    private function buildSwotOverviewBlockPrompt(): string
    {
        return implode("\n", [
            '[SWOT BLOCK | OVERVIEW]',
            'Retorne APENAS JSON valido (sem markdown e sem texto fora do JSON).',
            'Gere somente os campos de contexto executivo:',
            '- analysis_summary: 3 a 6 frases analiticas',
            '- strategic_note: 3 a 6 frases analiticas',
            'Schema de resposta:',
            '{"analysis_summary":"string","strategic_note":"string"}',
        ]);
    }

    private function buildSwotRecommendationsBlockPrompt(): string
    {
        return implode("\n", [
            '[SWOT BLOCK | RECOMMENDATIONS]',
            'Retorne APENAS JSON valido (sem markdown e sem texto fora do JSON).',
            'Gere somente o bloco recommendations.',
            'Regras obrigatorias:',
            '- Minimo 3 itens em short_term, mid_term e long_term.',
            '- Cada item: title, priority, period_label, source_name, source_url.',
            '- period_label deve ser coerente com bucket (0-3, 3-6, 6-12 meses).',
            'Schema de resposta:',
            '{"recommendations":{"short_term":[{"title":"string","priority":"Alta|Media|Baixa","period_label":"0-3 meses","source_name":"string","source_url":"https://..."}],"mid_term":[...],"long_term":[...]}}',
        ]);
    }

    private function buildSwotActionPlanBlockPrompt(): string
    {
        return implode("\n", [
            '[SWOT BLOCK | ACTION_PLAN]',
            'Retorne APENAS JSON valido (sem markdown e sem texto fora do JSON).',
            'Gere somente o bloco action_plan.',
            'Regras obrigatorias:',
            '- 6 areas fixas obrigatorias: technology-product, commercial-marketing, operations-support, finance-pricing, hr-people, legal-compliance.',
            '- Minimo 10 items por area.',
            '- Cada item: strategic_action, source_name, source_url, period, kpi, owner, priority.',
            '- source_url deve ser URL externa HTTP/HTTPS valida.',
            'Schema de resposta:',
            '{"action_plan":[{"area_key":"technology-product|commercial-marketing|operations-support|finance-pricing|hr-people|legal-compliance","title":"string","items":[{"strategic_action":"string","source_name":"string","source_url":"https://...","period":"string","kpi":"string","owner":"string","priority":"Critica|Alta|Media|Baixa"}]}]}',
        ]);
    }

    private function buildSwotStrategicImplicationsBlockPrompt(): string
    {
        return implode("\n", [
            '[SWOT BLOCK | STRATEGIC_IMPLICATIONS]',
            'Retorne APENAS JSON valido (sem markdown e sem texto fora do JSON).',
            'Gere somente o bloco strategic_implications.',
            'Regras obrigatorias:',
            '- Obrigatorio 4 grupos: so-accelerate, st-defend, wo-invest, wt-mitigate.',
            '- Minimo 10 items por grupo.',
            '- Cada item: id, title, factor_ref, scenario_ref, source_name, source_url.',
            '- Tons validos: strength, opportunity, weakness, threat.',
            'Schema de resposta:',
            '{"strategic_implications":[{"id":"so-accelerate|st-defend|wo-invest|wt-mitigate","first_factor_label":"string","first_factor_tone":"strength|opportunity|weakness|threat","second_factor_label":"string","second_factor_tone":"strength|opportunity|weakness|threat","action_label":"string","items":[{"id":"string","title":"string","factor_ref":"string","scenario_ref":"string","source_name":"string","source_url":"https://..."}]}]}',
        ]);
    }

    private function buildBlockJsonRepairPrompt(string $schemaDescription, string $parseIssue): string
    {
        return implode("\n", [
            '[JSON_REPAIR OBRIGATORIO]',
            'Sua resposta anterior nao foi parseavel.',
            'Retorne SOMENTE JSON valido, sem markdown e sem texto extra.',
            'Mantenha estritamente o schema solicitado para este bloco.',
            'Schema esperado: '.$schemaDescription,
            'Use texto conciso nos campos para evitar truncamento (frases curtas e sem prolixidade).',
            'Erro de parse: '.$parseIssue,
        ]);
    }

    /**
     * @param array<int, string> $rules
     */
    private function buildBlockCompletenessPrompt(string $blockId, array $rules): string
    {
        $lines = [
            '[REFORCO DE COMPLETUDE]',
            sprintf('O bloco "%s" anterior veio estruturalmente incompleto.', $blockId),
            'Refaca SOMENTE este bloco, mantendo JSON valido e o schema exato pedido.',
        ];

        foreach ($rules as $rule) {
            $lines[] = '- '.$rule;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<int, string>
     */
    private function collectStructuredQualityIssues(array $structured): array
    {
        $issues = [];

        $analysisSummary = $this->sanitizeString($structured['analysis_summary'] ?? null);
        if ($analysisSummary === null || mb_strlen($analysisSummary) < self::MIN_SUMMARY_CHARS) {
            $issues[] = sprintf(
                'analysis_summary deve ter pelo menos %d caracteres com profundidade analitica.',
                self::MIN_SUMMARY_CHARS
            );
        }

        $strategicNote = $this->sanitizeString($structured['strategic_note'] ?? null);
        if ($strategicNote === null || mb_strlen($strategicNote) < self::MIN_STRATEGIC_NOTE_CHARS) {
            $issues[] = sprintf(
                'strategic_note deve ter pelo menos %d caracteres com profundidade analitica.',
                self::MIN_STRATEGIC_NOTE_CHARS
            );
        }

        $factorKeys = ['strengths', 'opportunities', 'weaknesses', 'threats'];
        foreach ($factorKeys as $key) {
            $items = Arr::get($structured, "factors.$key", []);
            if (! is_array($items) || count($items) < self::MIN_FACTOR_ITEMS_PER_BUCKET) {
                $issues[] = sprintf(
                    'factors.%s precisa ter no minimo %d itens.',
                    $key,
                    self::MIN_FACTOR_ITEMS_PER_BUCKET
                );
            }
            $issues = array_merge($issues, $this->checkTitleUniqueness($items, "factors.$key"));
            $issues = array_merge($issues, $this->checkFactorSpecificity($items, "factors.$key"));
        }

        $recommendationKeys = ['short_term', 'mid_term', 'long_term'];
        foreach ($recommendationKeys as $key) {
            $items = Arr::get($structured, "recommendations.$key", []);
            if (! is_array($items) || count($items) < self::MIN_RECOMMENDATION_ITEMS_PER_BUCKET) {
                $issues[] = sprintf(
                    'recommendations.%s precisa ter no minimo %d itens.',
                    $key,
                    self::MIN_RECOMMENDATION_ITEMS_PER_BUCKET
                );
            }
            $issues = array_merge($issues, $this->checkTitleUniqueness($items, "recommendations.$key"));
            $issues = array_merge($issues, $this->checkRecommendationFieldCompleteness($items, "recommendations.$key"));
        }

        $actionPlan = Arr::get($structured, 'action_plan', []);
        if (! is_array($actionPlan) || count($actionPlan) < self::MIN_ACTION_AREAS) {
            $issues[] = sprintf('action_plan precisa ter no minimo %d areas.', self::MIN_ACTION_AREAS);
        } else {
            foreach ($actionPlan as $index => $area) {
                if (! is_array($area)) {
                    $issues[] = sprintf('action_plan[%d] deve ser objeto valido.', $index);
                    continue;
                }

                $areaItems = $area['items'] ?? null;
                if (! is_array($areaItems) || count($areaItems) < self::MIN_ACTION_ITEMS_PER_AREA) {
                    $issues[] = sprintf(
                        'action_plan[%d].items precisa ter no minimo %d itens.',
                        $index,
                        self::MIN_ACTION_ITEMS_PER_AREA
                    );
                    continue;
                }

                $issues = array_merge($issues, $this->checkTitleUniqueness($areaItems, "action_plan[$index].items"));
                $issues = array_merge(
                    $issues,
                    $this->checkActionPlanFieldCompleteness($areaItems, "action_plan[$index].items")
                );
            }
        }

        $strategicImplications = Arr::get($structured, 'strategic_implications', []);
        if (! is_array($strategicImplications) || count($strategicImplications) < self::MIN_IMPLICATION_GROUPS) {
            $issues[] = sprintf(
                'strategic_implications precisa ter no minimo %d grupos.',
                self::MIN_IMPLICATION_GROUPS
            );
        } else {
            $implicationGroupKeys = [];
            foreach ($strategicImplications as $index => $group) {
                if (! is_array($group)) {
                    $issues[] = sprintf('strategic_implications[%d] deve ser objeto valido.', $index);
                    continue;
                }

                $resolvedGroupKey = $this->resolveImplicationGroupKey(
                    $this->sanitizeString($group['id'] ?? null),
                    $this->sanitizeString($group['first_factor_tone'] ?? null),
                    $this->sanitizeString($group['second_factor_tone'] ?? null),
                );
                if ($resolvedGroupKey === null) {
                    $issues[] = sprintf(
                        'strategic_implications[%d] deve mapear para uma combinacao valida (SO, ST, WO, WT).',
                        $index
                    );
                    continue;
                }
                $implicationGroupKeys[] = $resolvedGroupKey;

                $groupItems = $group['items'] ?? null;
                if (! is_array($groupItems) || count($groupItems) < self::MIN_IMPLICATION_ITEMS_PER_GROUP) {
                    $issues[] = sprintf(
                        'strategic_implications[%d].items precisa ter no minimo %d itens.',
                        $index,
                        self::MIN_IMPLICATION_ITEMS_PER_GROUP
                    );
                    continue;
                }

                $issues = array_merge(
                    $issues,
                    $this->checkTitleUniqueness($groupItems, "strategic_implications[$index].items")
                );
                $issues = array_merge(
                    $issues,
                    $this->checkImplicationItemCompleteness($groupItems, "strategic_implications[$index].items")
                );
            }

            $uniqueGroupKeys = array_values(array_unique($implicationGroupKeys));
            if (count($uniqueGroupKeys) < count($implicationGroupKeys)) {
                $issues[] = 'strategic_implications contem combinacoes duplicadas; gere apenas 1 grupo para SO, ST, WO e WT.';
            }

            foreach (self::REQUIRED_IMPLICATION_GROUP_KEYS as $requiredGroupKey) {
                if (! in_array($requiredGroupKey, $uniqueGroupKeys, true)) {
                    $issues[] = sprintf(
                        'strategic_implications deve conter a combinacao obrigatoria "%s".',
                        $requiredGroupKey
                    );
                }
            }
        }

        foreach ($this->collectStructuredTextSamples($structured) as $sample) {
            $matchedPhrase = $this->matchDisallowedGeneratedPhrase($sample);
            if ($matchedPhrase !== null) {
                $issues[] = sprintf(
                    'Conteudo generico detectado com frase proibida: "%s".',
                    $matchedPhrase
                );
                break;
            }
        }

        return array_values(array_unique($issues));
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function checkTitleUniqueness(mixed $items, string $path): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $titles = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = $this->sanitizeString($item['title'] ?? $item['strategic_action'] ?? null);
            if ($title === null) {
                continue;
            }
            $titles[] = mb_strtolower(trim($title));
        }

        if (count($titles) <= 1) {
            return [];
        }

        $uniqueRatio = count(array_unique($titles)) / count($titles);
        if ($uniqueRatio < 0.75) {
            return [sprintf('%s possui titulos repetitivos; aumente diversidade e direcionamento.', $path)];
        }

        return [];
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function checkFactorSpecificity(mixed $items, string $path): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $issues = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->sanitizeString($item['title'] ?? null);
            if ($title === null || mb_strlen($title) < self::MIN_FACTOR_TITLE_CHARS) {
                $issues[] = sprintf(
                    '%s[%d].title precisa ter pelo menos %d caracteres e ser especifico.',
                    $path,
                    $index,
                    self::MIN_FACTOR_TITLE_CHARS
                );
                continue;
            }

            $description = $this->sanitizeString($item['description'] ?? null);
            if ($description === null || mb_strlen($description) < self::MIN_FACTOR_DESCRIPTION_CHARS) {
                $issues[] = sprintf(
                    '%s[%d].description precisa ter pelo menos %d caracteres com contexto real.',
                    $path,
                    $index,
                    self::MIN_FACTOR_DESCRIPTION_CHARS
                );
            }

            foreach (['priority', 'impact', 'tag', 'dimension'] as $requiredField) {
                if ($this->sanitizeString($item[$requiredField] ?? null) === null) {
                    $issues[] = sprintf('%s[%d].%s nao pode ser vazio.', $path, $index, $requiredField);
                }
            }

            $sourceName = $this->sanitizeSourceName($item['source_name'] ?? null);
            $sourceUrl = $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null);
            if ($sourceName === null || $sourceUrl === null) {
                $issues[] = sprintf(
                    '%s[%d] precisa incluir source_name e source_url externo valido.',
                    $path,
                    $index
                );
            }

            if (count($issues) >= 20) {
                break;
            }
        }

        return $issues;
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function checkRecommendationFieldCompleteness(mixed $items, string $path): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $issues = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $priority = $this->sanitizeString($item['priority'] ?? null);
            $periodLabel = $this->sanitizeString($item['period_label'] ?? $item['period'] ?? null);
            $sourceName = $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null);
            $sourceUrl = $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null);

            if ($priority === null) {
                $issues[] = sprintf('%s[%d].priority nao pode ser vazio.', $path, $index);
            }
            if ($periodLabel === null) {
                $issues[] = sprintf('%s[%d].period_label nao pode ser vazio.', $path, $index);
            }
            if ($sourceName === null || $sourceUrl === null) {
                $issues[] = sprintf(
                    '%s[%d] precisa incluir source_name e source_url externo valido.',
                    $path,
                    $index
                );
            }

            if (count($issues) >= 20) {
                break;
            }
        }

        return $issues;
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function checkActionPlanFieldCompleteness(mixed $items, string $path): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $issues = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $period = $this->sanitizeString($item['period'] ?? null);
            $kpi = $this->sanitizeString($item['kpi'] ?? null);
            $owner = $this->sanitizeString($item['owner'] ?? null);
            $priority = $this->sanitizeString($item['priority'] ?? null);
            $sourceName = $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null);
            $sourceUrl = $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null);

            if ($period === null) {
                $issues[] = sprintf('%s[%d].period nao pode ser vazio.', $path, $index);
            }
            if ($kpi === null) {
                $issues[] = sprintf('%s[%d].kpi nao pode ser vazio.', $path, $index);
            }
            if ($owner === null) {
                $issues[] = sprintf('%s[%d].owner nao pode ser vazio.', $path, $index);
            }
            if ($priority === null) {
                $issues[] = sprintf('%s[%d].priority nao pode ser vazio.', $path, $index);
            }
            if ($sourceName === null || $sourceUrl === null) {
                $issues[] = sprintf(
                    '%s[%d] precisa incluir source_name e source_url externo valido.',
                    $path,
                    $index
                );
            }

            if (count($issues) >= 20) {
                break;
            }
        }

        return $issues;
    }

    /**
     * @param mixed $items
     * @return array<int, string>
     */
    private function checkImplicationItemCompleteness(mixed $items, string $path): array
    {
        if (! is_array($items) || $items === []) {
            return [];
        }

        $issues = [];
        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $sourceName = $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null);
            $sourceUrl = $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null);
            if ($sourceName === null || $sourceUrl === null) {
                $issues[] = sprintf(
                    '%s[%d] precisa incluir source_name e source_url externo valido.',
                    $path,
                    $index
                );
            }

            if (count($issues) >= 20) {
                break;
            }
        }

        return $issues;
    }

    private function hasSpecificitySignal(string $text): bool
    {
        $normalized = $this->normalizeSourceLabel($text);

        if (
            preg_match('/\b(r\$|usd|eur)\s?\d+/iu', $text) === 1
            || preg_match('/\b\d+([.,]\d+)?\s?(%|x|mi|mil|bi|anos?|ano|meses?|mes|dias?|dia|horas?|hora|k|m|b)\b/iu', $text) === 1
            || preg_match('/\b[A-Z]{2,}\b/u', $text) === 1
        ) {
            return true;
        }

        foreach (self::FACTOR_SPECIFICITY_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function resolveImplicationGroupKey(?string $id, ?string $firstTone, ?string $secondTone): ?string
    {
        if ($id !== null && in_array($id, self::REQUIRED_IMPLICATION_GROUP_KEYS, true)) {
            return $id;
        }

        if ($firstTone === null || $secondTone === null) {
            return null;
        }

        if (
            ($firstTone === 'strength' && $secondTone === 'opportunity')
            || ($firstTone === 'opportunity' && $secondTone === 'strength')
        ) {
            return 'so-accelerate';
        }

        if (
            ($firstTone === 'strength' && $secondTone === 'threat')
            || ($firstTone === 'threat' && $secondTone === 'strength')
        ) {
            return 'st-defend';
        }

        if (
            ($firstTone === 'weakness' && $secondTone === 'opportunity')
            || ($firstTone === 'opportunity' && $secondTone === 'weakness')
        ) {
            return 'wo-invest';
        }

        if (
            ($firstTone === 'weakness' && $secondTone === 'threat')
            || ($firstTone === 'threat' && $secondTone === 'weakness')
        ) {
            return 'wt-mitigate';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $structured
     * @return array<int, string>
     */
    private function collectStructuredTextSamples(array $structured): array
    {
        $samples = [];

        $summary = $this->sanitizeString($structured['analysis_summary'] ?? null);
        if ($summary !== null) {
            $samples[] = $summary;
        }

        $strategicNote = $this->sanitizeString($structured['strategic_note'] ?? null);
        if ($strategicNote !== null) {
            $samples[] = $strategicNote;
        }

        foreach (['strengths', 'opportunities', 'weaknesses', 'threats'] as $key) {
            $factorItems = Arr::get($structured, "factors.$key", []);
            if (! is_array($factorItems)) {
                continue;
            }
            foreach ($factorItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                foreach (['title', 'description'] as $field) {
                    $value = $this->sanitizeString($item[$field] ?? null);
                    if ($value !== null) {
                        $samples[] = $value;
                    }
                }
            }
        }

        foreach (['short_term', 'mid_term', 'long_term'] as $key) {
            $recommendationItems = Arr::get($structured, "recommendations.$key", []);
            if (! is_array($recommendationItems)) {
                continue;
            }
            foreach ($recommendationItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = $this->sanitizeString($item['title'] ?? null);
                if ($title !== null) {
                    $samples[] = $title;
                }
            }
        }

        $actionPlan = Arr::get($structured, 'action_plan', []);
        if (is_array($actionPlan)) {
            foreach ($actionPlan as $area) {
                if (! is_array($area)) {
                    continue;
                }
                $areaItems = $area['items'] ?? [];
                if (! is_array($areaItems)) {
                    continue;
                }
                foreach ($areaItems as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $title = $this->sanitizeString($item['strategic_action'] ?? $item['title'] ?? null);
                    if ($title !== null) {
                        $samples[] = $title;
                    }
                }
            }
        }

        $strategicImplications = Arr::get($structured, 'strategic_implications', []);
        if (! is_array($strategicImplications)) {
            return $samples;
        }

        foreach ($strategicImplications as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach (['first_factor_label', 'second_factor_label', 'action_label'] as $field) {
                $value = $this->sanitizeString($group[$field] ?? null);
                if ($value !== null) {
                    $samples[] = $value;
                }
            }
            $groupItems = $group['items'] ?? [];
            if (! is_array($groupItems)) {
                continue;
            }
            foreach ($groupItems as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $title = $this->sanitizeString($item['title'] ?? null);
                if ($title !== null) {
                    $samples[] = $title;
                }
            }
        }

        return $samples;
    }

    private function matchDisallowedGeneratedPhrase(string $text): ?string
    {
        $hay = mb_strtolower($text);
        foreach (self::DISALLOWED_GENERATED_PHRASES as $phrase) {
            if (str_contains($hay, mb_strtolower($phrase))) {
                return $phrase;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $input): array
    {
        $allowedKeys = [
            'start_date',
            'end_date',
            'comparison_mode',
            'comparison_start_date',
            'comparison_end_date',
            'sectors',
            'sources',
            'tags',
            'expressions',
            'analysis_run_id',
            'view_mode',
            'approved_sources',
        ];

        $output = [];
        foreach ($allowedKeys as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $value = $input[$key];
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
                $output[$key] = $value;
                continue;
            }

            if (is_array($value) && $value !== []) {
                $output[$key] = array_values($value);
            }
        }

        return $output;
    }

    private function normalizeFactorQuadrant(mixed $quadrant): string
    {
        $value = strtolower(trim((string) $quadrant));

        if (in_array($value, ['strength', 'strengths'], true)) {
            return 'strengths';
        }
        if (in_array($value, ['opportunity', 'opportunities'], true)) {
            return 'opportunities';
        }
        if (in_array($value, ['weakness', 'weaknesses'], true)) {
            return 'weaknesses';
        }
        if (in_array($value, ['threat', 'threats'], true)) {
            return 'threats';
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{analysis_summary: string, strategic_note: string}
     */
    private function normalizeOverviewBlock(array $payload): array
    {
        $root = Arr::get($payload, 'overview');
        if (! is_array($root)) {
            $root = $payload;
        }

        return [
            'analysis_summary' => $this->sanitizeString($root['analysis_summary'] ?? $payload['analysis_summary'] ?? null) ?? '',
            'strategic_note' => $this->sanitizeString($root['strategic_note'] ?? $payload['strategic_note'] ?? null) ?? '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeFactorsBlock(array $payload): array
    {
        $root = Arr::get($payload, 'factors');
        if (! is_array($root)) {
            $root = $payload;
        }

        return [
            'strengths' => $this->normalizeList($root['strengths'] ?? []),
            'opportunities' => $this->normalizeList($root['opportunities'] ?? []),
            'weaknesses' => $this->normalizeList($root['weaknesses'] ?? []),
            'threats' => $this->normalizeList($root['threats'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function normalizeRecommendationsBlock(array $payload): array
    {
        $root = Arr::get($payload, 'recommendations');
        if (! is_array($root)) {
            $root = $payload;
        }

        return [
            'short_term' => $this->normalizeList($root['short_term'] ?? []),
            'mid_term' => $this->normalizeList($root['mid_term'] ?? []),
            'long_term' => $this->normalizeList($root['long_term'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeStructuredAnswer(string $answer): array
    {
        $decoded = $this->decodeAnswerJson($answer);
        $this->assertStructuredAnswerShape($decoded);

        $factorsInput = $decoded['factors'];
        $recommendationsInput = $decoded['recommendations'];
        $actionPlanInput = $decoded['action_plan'];

        return [
            'analysis_summary' => $this->sanitizeString($decoded['analysis_summary']) ?? '',
            'factors' => [
                'strengths' => $this->normalizeList($factorsInput['strengths']),
                'opportunities' => $this->normalizeList($factorsInput['opportunities']),
                'weaknesses' => $this->normalizeList($factorsInput['weaknesses']),
                'threats' => $this->normalizeList($factorsInput['threats']),
            ],
            'recommendations' => [
                'short_term' => $this->normalizeList($recommendationsInput['short_term']),
                'mid_term' => $this->normalizeList($recommendationsInput['mid_term']),
                'long_term' => $this->normalizeList($recommendationsInput['long_term']),
            ],
            'action_plan' => $this->normalizeActionPlan($actionPlanInput),
            'strategic_implications' => $this->normalizeStrategicImplications($decoded['strategic_implications']),
            'strategic_note' => $this->sanitizeString($decoded['strategic_note']) ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeAnswerJson(string $answer): array
    {
        $trimmed = trim($answer);

        if ($trimmed === '') {
            throw new RuntimeException('Brain answer is empty.');
        }

        $normalized = $this->sanitizeJsonPayloadCandidate($this->stripMarkdownCodeFence($trimmed));
        $decoded = json_decode($normalized, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        foreach ($this->extractJsonObjectCandidates($normalized) as $candidate) {
            $decoded = json_decode($this->sanitizeJsonPayloadCandidate($candidate), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (! is_array($decoded)) {
            $preview = preg_replace('/\s+/', ' ', $normalized);
            $preview = is_string($preview) ? mb_substr($preview, 0, 500) : '';
            Log::warning('SWOT brain answer is not valid JSON.', [
                'preview' => $preview,
            ]);
            throw new RuntimeException('Brain answer must be valid JSON.');
        }

        return $decoded;
    }

    /**
     * @return array<int, string>
     */
    private function extractJsonObjectCandidates(string $answer): array
    {
        $candidates = [];
        $len = strlen($answer);
        $depth = 0;
        $startIndex = null;
        $inString = false;
        $escape = false;

        for ($i = 0; $i < $len; $i++) {
            $ch = $answer[$i];

            if ($inString) {
                if ($escape) {
                    $escape = false;
                    continue;
                }

                if ($ch === '\\') {
                    $escape = true;
                    continue;
                }

                if ($ch === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($ch === '"') {
                $inString = true;
                continue;
            }

            if ($ch === '{') {
                if ($depth === 0) {
                    $startIndex = $i;
                }
                $depth++;
                continue;
            }

            if ($ch !== '}' || $depth === 0) {
                continue;
            }

            $depth--;
            if ($depth === 0 && $startIndex !== null) {
                $candidate = trim(substr($answer, $startIndex, ($i - $startIndex) + 1));
                if ($candidate !== '') {
                    $candidates[] = $candidate;
                }
                $startIndex = null;
            }
        }

        $outerCandidate = $this->extractJsonOuterObjectCandidate($answer);
        if ($outerCandidate !== null) {
            $candidates[] = $outerCandidate;
        }

        $candidates = array_values(array_unique($candidates));
        usort(
            $candidates,
            static fn (string $a, string $b): int => strlen($b) <=> strlen($a)
        );

        return $candidates;
    }

    private function extractJsonOuterObjectCandidate(string $answer): ?string
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $candidate = trim(substr($answer, $start, ($end - $start) + 1));

        return $candidate === '' ? null : $candidate;
    }

    private function sanitizeJsonPayloadCandidate(string $value): string
    {
        $sanitized = preg_replace('/,\s*([}\]])/', '$1', $value) ?? $value;

        return trim($sanitized);
    }

    private function stripMarkdownCodeFence(string $answer): string
    {
        $trimmed = trim($answer);
        if (! str_starts_with($trimmed, '```')) {
            return $trimmed;
        }

        $lines = preg_split('/\R/', $trimmed);
        if (! is_array($lines) || $lines === []) {
            return $trimmed;
        }

        $first = trim((string) $lines[0]);
        if (str_starts_with($first, '```')) {
            array_shift($lines);
        }

        while ($lines !== []) {
            $lastIndex = array_key_last($lines);
            if ($lastIndex === null) {
                break;
            }

            $last = trim((string) $lines[$lastIndex]);
            if ($last === '') {
                array_pop($lines);
                continue;
            }

            if ($last === '```') {
                array_pop($lines);
            }
            break;
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = $this->sanitizeString($item['title'] ?? $item['item'] ?? $item['name'] ?? null);
            if ($title === null) {
                continue;
            }

            $normalized[] = [
                'item_key' => $this->sanitizeString($item['item_key'] ?? null),
                'title' => $title,
                'description' => $this->sanitizeString($item['description'] ?? null),
                'tag' => $this->sanitizeString($item['tag'] ?? null),
                'priority' => $this->sanitizeString($item['priority'] ?? null),
                'impact' => $this->sanitizeString($item['impact'] ?? null),
                'dimension' => $this->sanitizeString($item['dimension'] ?? null),
                'period_label' => $this->sanitizeString($item['period_label'] ?? null),
                'period' => $this->sanitizeString($item['period'] ?? null),
                'strategic_action' => $this->sanitizeString($item['strategic_action'] ?? null),
                'swot_link' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                'source_name' => $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null),
                'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                'kpi' => $this->sanitizeString($item['kpi'] ?? null),
                'owner' => $this->sanitizeString($item['owner'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $actionPlan
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActionPlan(mixed $actionPlan): array
    {
        if (! is_array($actionPlan)) {
            return [];
        }

        $normalized = [];
        foreach ($actionPlan as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $areaKey = $this->sanitizeString($entry['area_key'] ?? null);
            if ($areaKey === null) {
                continue;
            }

            $items = $this->normalizeActionPlanItems($entry['items'] ?? []);
            $normalized[] = [
                'area_key' => $areaKey,
                'title' => $this->sanitizeString($entry['title'] ?? null),
                'items' => $items,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeActionPlanItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $strategicAction = $this->sanitizeString($item['strategic_action'] ?? $item['title'] ?? null);
            if ($strategicAction === null) {
                continue;
            }

            $normalized[] = [
                'item_key' => $this->sanitizeString($item['item_key'] ?? null),
                'strategic_action' => $strategicAction,
                'swot_link' => $this->normalizeExternalUrl($item['source_url'] ?? $item['swot_link'] ?? null),
                'source_name' => $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null),
                'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                'period' => $this->sanitizeString($item['period'] ?? null),
                'kpi' => $this->sanitizeString($item['kpi'] ?? null),
                'owner' => $this->sanitizeString($item['owner'] ?? null),
                'priority' => $this->sanitizeString($item['priority'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $groups
     * @return array<int, array<string, mixed>>
     */
    private function normalizeStrategicImplications(mixed $groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $id = $this->sanitizeString($group['id'] ?? null);
            $firstFactorLabel = $this->sanitizeString($group['first_factor_label'] ?? null);
            $firstFactorTone = $this->sanitizeString($group['first_factor_tone'] ?? null);
            $secondFactorLabel = $this->sanitizeString($group['second_factor_label'] ?? null);
            $secondFactorTone = $this->sanitizeString($group['second_factor_tone'] ?? null);
            $actionLabel = $this->sanitizeString($group['action_label'] ?? null);
            if (
                $id === null ||
                $firstFactorLabel === null ||
                $firstFactorTone === null ||
                $secondFactorLabel === null ||
                $secondFactorTone === null ||
                $actionLabel === null
            ) {
                continue;
            }
            if (! in_array($firstFactorTone, ['strength', 'opportunity', 'weakness', 'threat'], true)) {
                continue;
            }
            if (! in_array($secondFactorTone, ['strength', 'opportunity', 'weakness', 'threat'], true)) {
                continue;
            }

            $itemsInput = $group['items'] ?? null;
            if (! is_array($itemsInput)) {
                continue;
            }

            $items = [];
            foreach ($itemsInput as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $itemId = $this->sanitizeString($item['id'] ?? null);
                $title = $this->sanitizeString($item['title'] ?? null);
                $factorRef = $this->sanitizeString($item['factor_ref'] ?? null);
                $scenarioRef = $this->sanitizeString($item['scenario_ref'] ?? null);
                if ($itemId === null || $title === null || $factorRef === null || $scenarioRef === null) {
                    continue;
                }

                $items[] = [
                    'id' => $itemId,
                    'title' => $title,
                    'factor_ref' => $factorRef,
                    'scenario_ref' => $scenarioRef,
                    'source_url' => $this->normalizeExternalUrl($item['source_url'] ?? null),
                    'source_name' => $this->sanitizeSourceName($item['source_name'] ?? $item['source'] ?? null),
                    'sources' => $this->normalizeSourceReferences($item['sources'] ?? []),
                ];
            }

            $normalized[] = [
                'id' => $id,
                'first_factor_label' => $firstFactorLabel,
                'first_factor_tone' => $firstFactorTone,
                'second_factor_label' => $secondFactorLabel,
                'second_factor_tone' => $secondFactorTone,
                'action_label' => $actionLabel,
                'items' => $items,
            ];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSourceCatalog(string $customerUuid, ?string $analysisRunId): array
    {
        $sources = SwotSourceGovernance::query()
            ->where('customer_uuid', $customerUuid)
            ->whereNull('deleted_at')
            ->where('status', '!=', 'rejected')
            ->when(
                $analysisRunId !== null,
                function ($query) use ($analysisRunId): void {
                    $query->where(function ($nested) use ($analysisRunId): void {
                        $nested->whereNull('analysis_run_id')
                            ->orWhere('analysis_run_id', $analysisRunId);
                    });
                }
            )
            ->get()
            ->map(function (SwotSourceGovernance $source): ?array {
                $sourceName = $this->sanitizeSourceName($source->source_name);
                $sourceUrl = $this->normalizeExternalUrl($source->source_url);
                $sourceKey = $this->normalizeSourceKey($source->source_key);
                $analysisRunId = $this->sanitizeString($source->analysis_run_id);

                if ($sourceName === null && $sourceUrl === null && $sourceKey === '') {
                    return null;
                }

                return [
                    'source_name' => $sourceName,
                    'source_url' => $sourceUrl,
                    'source_key' => $sourceKey,
                    'source_origin' => $this->sanitizeString($source->source_origin),
                    'source_category' => $this->sanitizeString($source->source_category),
                    'status' => $this->sanitizeString($source->status) ?? 'pending',
                    'is_priority' => (bool) $source->is_priority,
                    'analysis_run_id' => $analysisRunId,
                ];
            })
            ->filter()
            ->values()
            ->all();

        usort(
            $sources,
            fn (array $a, array $b): int => $this->sourceRecordScore($b, $analysisRunId) <=> $this->sourceRecordScore($a, $analysisRunId)
        );

        $byKey = [];
        $byName = [];
        $byHost = [];
        foreach ($sources as $source) {
            $sourceKey = $this->normalizeSourceKey($source['source_key'] ?? null);
            if ($sourceKey === '') {
                $sourceKey = $this->normalizeSourceKey($source['source_url'] ?? $source['source_name'] ?? null);
            }
            if ($sourceKey !== '' && ! array_key_exists($sourceKey, $byKey)) {
                $byKey[$sourceKey] = $source;
            }

            $nameKey = $this->normalizeSourceLabel((string) ($source['source_name'] ?? ''));
            if ($nameKey !== '' && ! array_key_exists($nameKey, $byName)) {
                $byName[$nameKey] = $source;
            }

            $host = $this->extractHostFromUrl($source['source_url'] ?? null);
            if ($host !== null && ! array_key_exists($host, $byHost)) {
                $byHost[$host] = $source;
            }
        }

        return [
            'ordered' => $sources,
            'by_key' => $byKey,
            'by_name' => $byName,
            'by_host' => $byHost,
        ];
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sourceRecordScore(array $source, ?string $analysisRunId): int
    {
        $score = 0;

        if (! empty($source['is_priority'])) {
            $score += 100;
        }
        if (($source['status'] ?? null) === 'approved') {
            $score += 50;
        }
        if (
            $analysisRunId !== null
            && ($source['analysis_run_id'] ?? null) !== null
            && hash_equals((string) $source['analysis_run_id'], $analysisRunId)
        ) {
            $score += 20;
        }
        if (($source['source_url'] ?? null) !== null) {
            $score += 10;
        }

        return $score;
    }

    /**
     * @param array<string, mixed> $structured
     * @param array<string, mixed> $sourceCatalog
     * @return array<string, mixed>
     */
    private function applySourceCatalogToStructured(array $structured, array $sourceCatalog): array
    {
        $factorKeys = ['strengths', 'opportunities', 'weaknesses', 'threats'];
        foreach ($factorKeys as $factorKey) {
            $items = Arr::get($structured, "factors.$factorKey", []);
            if (is_array($items)) {
                Arr::set(
                    $structured,
                    "factors.$factorKey",
                    $this->applySourceCatalogToListItems($items, $sourceCatalog)
                );
            }
        }

        $recommendationKeys = ['short_term', 'mid_term', 'long_term'];
        foreach ($recommendationKeys as $recommendationKey) {
            $items = Arr::get($structured, "recommendations.$recommendationKey", []);
            if (is_array($items)) {
                Arr::set(
                    $structured,
                    "recommendations.$recommendationKey",
                    $this->applySourceCatalogToListItems($items, $sourceCatalog)
                );
            }
        }

        $actionPlan = Arr::get($structured, 'action_plan', []);
        if (is_array($actionPlan)) {
            $structured['action_plan'] = $this->applySourceCatalogToActionPlan($actionPlan, $sourceCatalog);
        }

        $strategicImplications = Arr::get($structured, 'strategic_implications', []);
        if (is_array($strategicImplications)) {
            $structured['strategic_implications'] = $this->applySourceCatalogToStrategicImplications(
                $strategicImplications,
                $sourceCatalog
            );
        }

        return $structured;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $sourceCatalog
     * @return array<int, array<string, mixed>>
     */
    private function applySourceCatalogToListItems(array $items, array $sourceCatalog): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sources = $this->resolveSourceReferencesList(
                $item['sources'] ?? [],
                $item['source_name'] ?? null,
                $item['swot_link'] ?? $item['source_url'] ?? null,
                $sourceCatalog
            );
            $source = $sources[0] ?? $this->resolveSourceReference(
                $item['source_name'] ?? null,
                $item['swot_link'] ?? $item['source_url'] ?? null,
                $sourceCatalog
            );

            $item['source_name'] = $source['source_name'];
            $item['source_url'] = $source['source_url'];
            $item['swot_link'] = $source['source_url'];
            $item['sources'] = $sources;

            $normalized[] = $item;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $actionPlan
     * @param array<string, mixed> $sourceCatalog
     * @return array<int, array<string, mixed>>
     */
    private function applySourceCatalogToActionPlan(array $actionPlan, array $sourceCatalog): array
    {
        $normalized = [];
        foreach ($actionPlan as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $items = $entry['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $entry['items'] = $this->applySourceCatalogToListItems($items, $sourceCatalog);
            $normalized[] = $entry;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     * @param array<string, mixed> $sourceCatalog
     * @return array<int, array<string, mixed>>
     */
    private function applySourceCatalogToStrategicImplications(
        array $groups,
        array $sourceCatalog,
        array $analysisWideSources = []
    ): array
    {
        $normalized = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $items = $group['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $resolvedItems = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $sources = $this->resolveSourceReferencesList(
                    $item['sources'] ?? [],
                    $item['source_name'] ?? null,
                    $item['source_url'] ?? null,
                    $sourceCatalog,
                    $analysisWideSources
                );
                $source = $sources[0] ?? $this->resolveSourceReference(
                    $item['source_name'] ?? null,
                    $item['source_url'] ?? null,
                    $sourceCatalog
                );

                $item['source_name'] = $source['source_name'];
                $item['source_url'] = $source['source_url'];
                $item['sources'] = $sources;
                $resolvedItems[] = $item;
            }

            $group['items'] = $resolvedItems;
            $normalized[] = $group;
        }

        return $normalized;
    }

    private function limitStrategicImplicationItems(array $groups, int $limit): array
    {
        if ($limit < 1) {
            return $groups;
        }

        $normalized = [];
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $items = $group['items'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            $group['items'] = array_values(array_slice($items, 0, $limit));
            $normalized[] = $group;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $sourceCatalog
     * @return array<string, string|null>
     */
    private function resolveSourceReference(mixed $sourceName, mixed $sourceUrl, array $sourceCatalog): array
    {
        $normalizedSourceName = $this->sanitizeSourceName($sourceName);
        $normalizedSourceUrl = $this->normalizeExternalUrl($sourceUrl);
        $matchedSource = null;

        if ($normalizedSourceUrl !== null) {
            $host = $this->extractHostFromUrl($normalizedSourceUrl);
            if ($host !== null && isset($sourceCatalog['by_host'][$host]) && is_array($sourceCatalog['by_host'][$host])) {
                $matchedSource = $sourceCatalog['by_host'][$host];
            }
        }

        if ($matchedSource === null && $normalizedSourceName !== null) {
            $nameKey = $this->normalizeSourceKey($normalizedSourceName);
            if ($nameKey !== '' && isset($sourceCatalog['by_key'][$nameKey]) && is_array($sourceCatalog['by_key'][$nameKey])) {
                $matchedSource = $sourceCatalog['by_key'][$nameKey];
            }
        }

        if ($matchedSource === null && $normalizedSourceName !== null) {
            $normalizedName = $this->normalizeSourceLabel($normalizedSourceName);
            if ($normalizedName !== '' && isset($sourceCatalog['by_name'][$normalizedName]) && is_array($sourceCatalog['by_name'][$normalizedName])) {
                $matchedSource = $sourceCatalog['by_name'][$normalizedName];
            }
        }

        if ($matchedSource !== null) {
            if ($normalizedSourceName === null) {
                $normalizedSourceName = $this->sanitizeSourceName($matchedSource['source_name'] ?? null);
            }
            if ($normalizedSourceUrl === null) {
                $normalizedSourceUrl = $this->normalizeExternalUrl($matchedSource['source_url'] ?? null);
            }
        }

        return [
            'source_name' => $normalizedSourceName,
            'source_url' => $normalizedSourceUrl,
            'source_origin' => $this->sanitizeString($matchedSource['source_origin'] ?? null),
            'source_category' => $this->sanitizeString($matchedSource['source_category'] ?? null),
        ];
    }

    /**
     * @param mixed $sources
     * @return array<int, array<string, string|null>>
     */
    private function normalizeSourceReferences(mixed $sources): array
    {
        if (! is_array($sources)) {
            return [];
        }

        $normalized = [];
        $seen = [];
        foreach ($sources as $source) {
            if (! is_array($source)) {
                continue;
            }

            $sourceName = $this->sanitizeSourceName($source['source_name'] ?? $source['label'] ?? $source['source'] ?? null);
            $sourceUrl = $this->normalizeExternalUrl($source['source_url'] ?? $source['url'] ?? $source['link'] ?? null);
            if ($sourceName === null && $sourceUrl === null) {
                continue;
            }

            $key = mb_strtolower(($sourceName ?? '').'|'.($sourceUrl ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $normalized[] = [
                'source_name' => $sourceName,
                'source_url' => $sourceUrl,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $sources
     * @return array<int, array<string, string|null>>
     */
    private function resolveSourceReferencesList(
        mixed $sources,
        mixed $fallbackSourceName,
        mixed $fallbackSourceUrl,
        array $sourceCatalog,
        array $analysisWideSources = []
    ): array {
        $normalized = [];
        $seen = [];

        foreach ($this->normalizeSourceReferences($sources) as $source) {
            $resolved = $this->resolveSourceReference(
                $source['source_name'] ?? null,
                $source['source_url'] ?? null,
                $sourceCatalog
            );
            $key = mb_strtolower(($resolved['source_name'] ?? '').'|'.($resolved['source_url'] ?? ''));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $resolved;
        }

        $fallback = $this->resolveSourceReference($fallbackSourceName, $fallbackSourceUrl, $sourceCatalog);
        $fallbackKey = mb_strtolower(($fallback['source_name'] ?? '').'|'.($fallback['source_url'] ?? ''));
        if ($fallbackKey !== '|' && ! isset($seen[$fallbackKey])) {
            $normalized[] = $fallback;
            $seen[$fallbackKey] = true;
        }

        foreach ($analysisWideSources as $source) {
            if (! is_array($source)) {
                continue;
            }
            $resolved = $this->resolveSourceReference(
                $source['source_name'] ?? null,
                $source['source_url'] ?? null,
                $sourceCatalog
            );
            $key = mb_strtolower(($resolved['source_name'] ?? '').'|'.($resolved['source_url'] ?? ''));
            if ($key === '|' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $normalized[] = $resolved;
        }

        return $normalized;
    }

    private function sanitizeSourceName(mixed $value): ?string
    {
        $sourceName = $this->sanitizeString($value);
        if ($sourceName === null) {
            return null;
        }

        return $this->isGenericInternalSourceLabel($sourceName) ? null : $sourceName;
    }

    private function isGenericInternalSourceLabel(string $value): bool
    {
        $normalized = $this->normalizeSourceLabel($value);

        return in_array($normalized, self::GENERIC_INTERNAL_SOURCE_LABELS, true);
    }

    private function normalizeSourceKey(mixed $value): string
    {
        $candidate = $this->sanitizeString($value);
        if ($candidate === null) {
            return '';
        }

        $url = $this->normalizeExternalUrl($candidate);
        if ($url !== null) {
            $host = $this->extractHostFromUrl($url);
            if ($host !== null) {
                return $host;
            }
        }

        return $this->normalizeSourceLabel($candidate);
    }

    private function normalizeSourceLabel(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        $normalized = strtr($normalized, [
            'á' => 'a',
            'à' => 'a',
            'â' => 'a',
            'ã' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'ô' => 'o',
            'õ' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
        ]);
        $normalized = preg_replace('/[^a-z0-9.\- ]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function extractHostFromUrl(mixed $value): ?string
    {
        $url = $this->normalizeExternalUrl($value);
        if ($url === null) {
            return null;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        return $host !== '' ? $host : null;
    }

    private function normalizeExternalUrl(mixed $value): ?string
    {
        $candidate = $this->sanitizeString($value);
        if ($candidate === null) {
            return null;
        }

        if (! preg_match('/^[a-z][a-z0-9+\-.]*:\/\//i', $candidate)) {
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}([\/?#:].*)?$/i', $candidate)) {
                $candidate = 'https://'.$candidate;
            } else {
                return null;
            }
        }

        $scheme = strtolower((string) parse_url($candidate, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $candidate;
    }

    private function sanitizeString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function resolveStoredPrompt(mixed $rawPayload): ?string
    {
        if (! is_array($rawPayload)) {
            return null;
        }

        $candidates = [
            Arr::get($rawPayload, 'input.analysis_prompt'),
            Arr::get($rawPayload, 'request.analysis_prompt'),
            Arr::get($rawPayload, 'analysis_prompt'),
        ];

        foreach ($candidates as $candidate) {
            $prompt = $this->sanitizeString($candidate);
            if ($prompt !== null) {
                return $prompt;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function resolveApprovedSourceFilters(string $customerUuid, string $analysisRunId): array
    {
        return SwotSourceGovernance::query()
            ->where('customer_uuid', $customerUuid)
            ->whereNull('deleted_at')
            ->where('status', 'approved')
            ->where(function ($query) use ($analysisRunId): void {
                $query->whereNull('analysis_run_id')
                    ->orWhere('analysis_run_id', $analysisRunId);
            })
            ->orderByDesc('is_priority')
            ->orderBy('source_name')
            ->get()
            ->map(function (SwotSourceGovernance $source): ?string {
                $name = $this->sanitizeSourceName($source->source_name)
                    ?? $this->extractHostFromUrl($source->source_url)
                    ?? $this->sanitizeString($source->source_key);
                if ($name === null) {
                    return null;
                }

                $url = $this->normalizeExternalUrl($source->source_url);

                return $url !== null ? sprintf('%s (%s)', $name, $url) : $name;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizePositiveInt(mixed $value, int $min = 1, int $max = 100): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && ! preg_match('/^\d+$/', $value)) {
            return null;
        }

        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $normalized = (int) $value;
        if ($normalized < $min) {
            return null;
        }

        return min($normalized, $max);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function assertStructuredAnswerShape(array $decoded): void
    {
        $summary = $this->sanitizeString($decoded['analysis_summary'] ?? null);
        if ($summary === null) {
            throw new RuntimeException('Brain answer JSON must contain non-empty analysis_summary.');
        }

        if (! is_array($decoded['factors'] ?? null)) {
            throw new RuntimeException('Brain answer JSON must contain factors object.');
        }
        if (! is_array($decoded['recommendations'] ?? null)) {
            throw new RuntimeException('Brain answer JSON must contain recommendations object.');
        }
        if (! is_array($decoded['action_plan'] ?? null)) {
            throw new RuntimeException('Brain answer JSON must contain action_plan array.');
        }
        if (! is_array($decoded['strategic_implications'] ?? null)) {
            throw new RuntimeException('Brain answer JSON must contain strategic_implications array.');
        }
        $strategicNote = $this->sanitizeString($decoded['strategic_note'] ?? null);
        if ($strategicNote === null) {
            throw new RuntimeException('Brain answer JSON must contain non-empty strategic_note.');
        }

        $factors = $decoded['factors'];
        foreach (['strengths', 'opportunities', 'weaknesses', 'threats'] as $key) {
            if (! is_array($factors[$key] ?? null)) {
                throw new RuntimeException(sprintf('Brain answer JSON must contain factors.%s array.', $key));
            }
        }

        $recommendations = $decoded['recommendations'];
        foreach (['short_term', 'mid_term', 'long_term'] as $key) {
            if (! is_array($recommendations[$key] ?? null)) {
                throw new RuntimeException(sprintf('Brain answer JSON must contain recommendations.%s array.', $key));
            }
        }

        foreach ($decoded['strategic_implications'] as $index => $group) {
            if (! is_array($group)) {
                throw new RuntimeException(sprintf(
                    'Brain answer JSON strategic_implications[%d] must be an object.',
                    $index
                ));
            }

            foreach (
                ['id', 'first_factor_label', 'first_factor_tone', 'second_factor_label', 'second_factor_tone', 'action_label'] as $field
            ) {
                $value = $this->sanitizeString($group[$field] ?? null);
                if ($value === null) {
                    throw new RuntimeException(sprintf(
                        'Brain answer JSON strategic_implications[%d].%s must be non-empty.',
                        $index,
                        $field
                    ));
                }
            }
            $firstTone = (string) $group['first_factor_tone'];
            $secondTone = (string) $group['second_factor_tone'];
            if (! in_array($firstTone, ['strength', 'opportunity', 'weakness', 'threat'], true)) {
                throw new RuntimeException(sprintf(
                    'Brain answer JSON strategic_implications[%d].first_factor_tone is invalid.',
                    $index
                ));
            }
            if (! in_array($secondTone, ['strength', 'opportunity', 'weakness', 'threat'], true)) {
                throw new RuntimeException(sprintf(
                    'Brain answer JSON strategic_implications[%d].second_factor_tone is invalid.',
                    $index
                ));
            }

            if (! is_array($group['items'] ?? null)) {
                throw new RuntimeException(sprintf(
                    'Brain answer JSON strategic_implications[%d].items must be an array.',
                    $index
                ));
            }

            foreach ($group['items'] as $itemIndex => $item) {
                if (! is_array($item)) {
                    throw new RuntimeException(sprintf(
                        'Brain answer JSON strategic_implications[%d].items[%d] must be an object.',
                        $index,
                        $itemIndex
                    ));
                }

                foreach (['id', 'title', 'factor_ref', 'scenario_ref'] as $field) {
                    $value = $this->sanitizeString($item[$field] ?? null);
                    if ($value === null) {
                        throw new RuntimeException(sprintf(
                            'Brain answer JSON strategic_implications[%d].items[%d].%s must be non-empty.',
                            $index,
                            $itemIndex,
                            $field
                        ));
                    }
                }
            }
        }
    }
}
