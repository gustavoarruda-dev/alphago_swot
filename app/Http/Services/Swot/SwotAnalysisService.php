<?php

namespace App\Http\Services\Swot;

use App\Models\SwotAnalysis;
use App\Models\SwotCard;
use App\Models\SwotCardItem;
use App\Models\SwotSourceGovernance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SwotAnalysisService
{
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
        if ($analysisRunId !== null) {
            $brainFilters['analysis_run_id'] = $analysisRunId;
        }

        $brainResponse = $this->brainClient->ask([
            'question' => $prompt,
            'customer_uuid' => $customerUuid,
            'filters' => $brainFilters,
        ]);

        $answer = $brainResponse['answer'] ?? null;
        if (! is_string($answer) || trim($answer) === '') {
            throw new RuntimeException('Brain returned an empty SWOT answer.');
        }

        $structuredAnswer = $this->normalizeStructuredAnswer($answer);
        $analysisTitle = $this->sanitizeString($payload['analysis_title'] ?? null) ?? 'Análise SWOT';

        $analysis = DB::transaction(function () use (
            $customerUuid,
            $analysisTitle,
            $trendAnalysisRunId,
            $normalizedFilters,
            $brainResponse,
            $structuredAnswer
        ): SwotAnalysis {
            $analysis = SwotAnalysis::query()
                ->where('customer_uuid', $customerUuid)
                ->orderByDesc('generated_at')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if (! $analysis) {
                $analysis = new SwotAnalysis();
                $analysis->customer_uuid = $customerUuid;
            }

            $analysis->fill([
                'trend_analysis_run_id' => $trendAnalysisRunId,
                'status' => 'generated',
                'analysis_title' => $analysisTitle,
                'analysis_summary' => $structuredAnswer['analysis_summary'],
                'brain_conversation_id' => $this->sanitizeString($brainResponse['conversation_id'] ?? null),
                'filters' => $normalizedFilters,
                'raw_ai_payload' => [
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

        $card->items()->create([
            'item_key' => null,
            'title' => $this->sanitizeString($payload['title'] ?? null) ?? 'Novo fator',
            'description' => $this->sanitizeString($payload['description'] ?? null),
            'tag' => $this->sanitizeString($payload['tag'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'impact' => $this->sanitizeString($payload['impact'] ?? null),
            'dimension' => $this->sanitizeString($payload['dimension'] ?? null),
            'sort_order' => $nextOrder,
            'metadata' => [
                'origin' => 'manual',
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

        $item->fill(array_filter([
            'title' => $this->sanitizeString($payload['title'] ?? null),
            'description' => $this->sanitizeString($payload['description'] ?? null),
            'tag' => $this->sanitizeString($payload['tag'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'impact' => $this->sanitizeString($payload['impact'] ?? null),
            'dimension' => $this->sanitizeString($payload['dimension'] ?? null),
        ], static fn ($value) => $value !== null));

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

        $item->fill(array_filter([
            'title' => $this->sanitizeString($payload['title'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
            'period' => $this->sanitizeString($payload['period_label'] ?? $payload['period'] ?? null),
        ], static fn ($value) => $value !== null));

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

        $item->fill(array_filter([
            'title' => $this->sanitizeString($payload['strategic_action'] ?? $payload['title'] ?? null),
            'swot_link' => $this->normalizeExternalUrl($payload['swot_link'] ?? null),
            'period' => $this->sanitizeString($payload['period'] ?? null),
            'kpi' => $this->sanitizeString($payload['kpi'] ?? null),
            'owner' => $this->sanitizeString($payload['owner'] ?? null),
            'priority' => $this->sanitizeString($payload['priority'] ?? null),
        ], static fn ($value) => $value !== null));

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
                    'swot_link' => $this->normalizeExternalUrl($item['swot_link'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeString($item['source_name'] ?? null),
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
                    'swot_link' => $this->normalizeExternalUrl($item['swot_link'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeString($item['source_name'] ?? null),
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
                    'swot_link' => $this->normalizeExternalUrl($item['swot_link'] ?? null),
                    'period' => $this->sanitizeString($item['period'] ?? null),
                    'kpi' => $this->sanitizeString($item['kpi'] ?? null),
                    'owner' => $this->sanitizeString($item['owner'] ?? null),
                    'priority' => $this->sanitizeString($item['priority'] ?? null),
                    'sort_order' => $index + 1,
                    'metadata' => [
                        'origin' => 'ai',
                        'source_name' => $this->sanitizeString($item['source_name'] ?? null),
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

        $topFactorsLimit = $this->normalizePositiveInt($options['top_factors_limit'] ?? null);
        $bottomFactorsLimit = $this->normalizePositiveInt($options['bottom_factors_limit'] ?? null);
        $recommendationsLimit = $this->normalizePositiveInt($options['recommendations_limit'] ?? null);

        $factors = [
            'strengths' => [],
            'opportunities' => [],
            'weaknesses' => [],
            'threats' => [],
        ];
        $factorCounts = [
            'strengths' => 0,
            'opportunities' => 0,
            'weaknesses' => 0,
            'threats' => 0,
        ];

        foreach (self::FACTOR_CARD_DEFINITIONS as $quadrant => $definition) {
            /** @var SwotCard|null $card */
            $card = $cards->firstWhere('card_key', $definition['card_key']);
            if (! $card) {
                continue;
            }

            $items = $card->items
                ->whereNull('deleted_at')
                ->sortBy('sort_order')
                ->values()
                ->map(function (SwotCardItem $item): array {
                    return [
                        'id' => $item->uuid,
                        'title' => $item->title,
                        'description' => $item->description,
                        'tag' => $item->tag,
                        'priority' => $item->priority,
                        'impact' => $item->impact,
                        'dimension' => $item->dimension,
                        'source_url' => $this->normalizeExternalUrl($item->swot_link),
                        'source_name' => $this->sanitizeString(Arr::get($item->metadata ?? [], 'source_name')),
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
        $recommendationCounts = [
            'short_term' => 0,
            'mid_term' => 0,
            'long_term' => 0,
        ];

        foreach (self::RECOMMENDATION_CARD_DEFINITIONS as $bucket => $definition) {
            /** @var SwotCard|null $card */
            $card = $cards->firstWhere('card_key', $definition['card_key']);
            if (! $card) {
                continue;
            }

            $items = $card->items
                ->whereNull('deleted_at')
                ->sortBy('sort_order')
                ->values()
                ->map(function (SwotCardItem $item): array {
                    return [
                        'id' => $item->uuid,
                        'title' => $item->title,
                        'priority' => $item->priority,
                        'period_label' => $item->period,
                        'source_url' => $this->normalizeExternalUrl($item->swot_link),
                        'source_name' => $this->sanitizeString(Arr::get($item->metadata ?? [], 'source_name')),
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
            if (! $card) {
                continue;
            }

            $actionPlan[] = [
                'id' => $card->uuid,
                'area_key' => $areaKey,
                'title' => $card->title,
                'items' => $card->items
                    ->whereNull('deleted_at')
                    ->sortBy('sort_order')
                    ->values()
                    ->map(function (SwotCardItem $item): array {
                        return [
                            'id' => $item->uuid,
                            'strategic_action' => $item->title,
                            'swot_link' => $this->normalizeExternalUrl($item->swot_link),
                            'source_name' => $this->sanitizeString(Arr::get($item->metadata ?? [], 'source_name')),
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
                'recommendations' => $recommendations,
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

        $normalized = $this->stripMarkdownCodeFence($trimmed);
        $decoded = json_decode($normalized, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Brain answer must be valid JSON.');
        }

        return $decoded;
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

            $title = $this->sanitizeString($item['title'] ?? null);
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
                'source_name' => $this->sanitizeString($item['source_name'] ?? $item['source'] ?? null),
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
                'source_name' => $this->sanitizeString($item['source_name'] ?? $item['source'] ?? null),
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
                    'source_name' => $this->sanitizeString($item['source_name'] ?? $item['source'] ?? null),
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
