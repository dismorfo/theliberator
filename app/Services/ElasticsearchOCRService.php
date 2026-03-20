<?php

namespace App\Services;

use App\Services\Concerns\BuildsElasticsearchNames;
use Elastic\Elasticsearch\Client;
use RuntimeException;

class ElasticsearchOCRService
{
    use BuildsElasticsearchNames;

    private Client $client;

    private string $index;

    private string $normalizedAnalyzer = 'normalized_text_analyzer';

    public function __construct(?ElasticsearchClientFactory $clientFactory = null)
    {
        $factory = $clientFactory ?? app(ElasticsearchClientFactory::class);
        $this->client = $factory->make();

        $this->index = $this->buildIndexName('ocr');
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    public function getIndex(): string
    {
        return $this->index;
    }

    public function indexExists(): bool
    {
        return $this->client->indices()->exists(['index' => $this->index])->asBool();
    }

    public function createOCRIndex(): void
    {
        if ($this->indexExists()) {
            return;
        }

        $this->client->indices()->create($this->getIndexDefinition());
    }

    public function recreateOCRIndex(): void
    {
        if ($this->indexExists()) {
            $this->client->indices()->delete(['index' => $this->index]);
        }

        $this->client->indices()->create($this->getIndexDefinition());
    }

    public function search(string $identifier, string $query, int $perPage = 20, int $page = 1): array
    {
        $params = $this->buildSearchParams($identifier, $query, $perPage, $page);

        return $this->executeSearch($params);
    }

    public function bulk(array $params): void
    {
        $response = $this->client->bulk($params)->asArray();

        if (($response['errors'] ?? false) !== true) {
            return;
        }

        $failedItems = [];

        foreach ($response['items'] ?? [] as $item) {
            $indexResult = $item['index'] ?? null;
            if (! is_array($indexResult) || ! isset($indexResult['error'])) {
                continue;
            }

            $failedItems[] = sprintf(
                '%s: %s',
                $indexResult['_id'] ?? 'unknown',
                is_array($indexResult['error'])
                    ? (string) ($indexResult['error']['reason'] ?? $indexResult['error']['type'] ?? 'Unknown error')
                    : (string) $indexResult['error']
            );
        }

        throw new RuntimeException(
            'Bulk OCR indexing failed: '.implode('; ', array_slice($failedItems, 0, 5))
        );
    }

    public function normalizeText(string $text): string
    {
        $normalizedText = mb_strtolower($text);
        $normalizedText = preg_replace('/[^\pL\pN\s]+/u', ' ', $normalizedText) ?? $normalizedText;

        return trim(preg_replace('/\s+/', ' ', $normalizedText) ?? $normalizedText);
    }

    public function buildSearchParams(string $identifier, string $query, int $perPage = 20, int $page = 1): array
    {
        $normalizedQuery = $this->normalizeText($query);
        $queryTokens = $this->tokenize($normalizedQuery);
        $usesPhraseRecovery = count($queryTokens) > 1;

        $queryShould = [
            [
                'bool' => [
                    'filter' => [
                        ['term' => ['is_shingle' => false]],
                    ],
                    'must' => [
                        [
                            'bool' => [
                                'should' => $this->buildLineMatchClauses($query, $normalizedQuery, $queryTokens),
                                'minimum_should_match' => 1,
                            ],
                        ],
                    ],
                    'should' => [
                        [
                            'rank_feature' => [
                                'field' => 'confidence',
                                'boost' => 2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($usesPhraseRecovery) {
            $queryShould[] = [
                'bool' => [
                    'filter' => [
                        ['term' => ['is_shingle' => true]],
                    ],
                    'must' => [
                        [
                            'bool' => [
                                'should' => $this->buildShingleMatchClauses($normalizedQuery),
                                'minimum_should_match' => 1,
                            ],
                        ],
                    ],
                    'should' => [
                        [
                            'rank_feature' => [
                                'field' => 'confidence',
                                'boost' => 1,
                            ],
                        ],
                    ],
                ],
            ];
        }

        $params = [
            'index' => $this->index,
            'body' => [
                'from' => max(0, ($page - 1) * $perPage),
                'size' => $perPage,
                'track_total_hits' => true,
                'sort' => [
                    ['_score' => ['order' => 'desc']],
                ],
                'query' => [
                    'bool' => [
                        'filter' => [
                            ['term' => ['manifest' => $identifier]],
                        ],
                        'should' => $queryShould,
                        'minimum_should_match' => 1,
                    ],
                ],
                'rescore' => [
                    [
                        'window_size' => max(50, $perPage * 5),
                        'query' => [
                            'score_mode' => 'total',
                            'query_weight' => 0.7,
                            'rescore_query_weight' => 1.8,
                            'rescore_query' => [
                                'bool' => [
                                    'should' => $this->buildRescoreClauses($query, $normalizedQuery, $usesPhraseRecovery),
                                    'minimum_should_match' => 1,
                                ],
                            ],
                        ],
                    ],
                ],
                'highlight' => [
                    'fields' => [
                        'text' => [
                            'pre_tags' => ['[[MATCH]]'],
                            'post_tags' => ['[[/MATCH]]'],
                            'fragment_size' => 200,
                            'number_of_fragments' => 1,
                        ],
                    ],
                ],
            ],
        ];

        return $params;
    }

    public function executeSearch(array $params): array
    {
        $response = $this->client->search($params);
        $responseArray = $response->asArray();
        $hits = $responseArray['hits']['hits'];
        $total = $responseArray['hits']['total']['value'];
        $perPage = $params['body']['size'] ?? 20;
        $page = isset($params['body']['from']) ? (int) ($params['body']['from'] / $perPage) + 1 : 1;

        $results = array_map(function ($hit) {
            $source = $hit['_source'];
            $source['_id'] = $hit['_id'];
            $source['_score'] = $hit['_score'] ?? null;
            $source['_highlight'] = $hit['highlight'] ?? [];

            return $source;
        }, $hits);

        return [
            'results' => $results,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Debug utility for inspecting the full OCR index outside paginated search responses.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAllDocuments(): array
    {
        $params = [
            'index' => $this->index,
            'scroll' => '1m',
            'body' => [
                'query' => ['match_all' => (object) []],
                'size' => 1000,
                'sort' => ['_doc'],
            ],
        ];

        $response = $this->client->search($params)->asArray();
        $documents = [];
        $scrollId = $response['_scroll_id'] ?? null;

        while (true) {
            $hits = $response['hits']['hits'] ?? [];
            if ($hits === []) {
                break;
            }

            foreach ($hits as $hit) {
                $documents[] = $hit['_source'];
            }

            if ($scrollId === null) {
                break;
            }

            $response = $this->client->scroll([
                'scroll_id' => $scrollId,
                'scroll' => '1m',
            ])->asArray();
            $scrollId = $response['_scroll_id'] ?? $scrollId;
        }

        if ($scrollId !== null) {
            $this->client->clearScroll(['scroll_id' => [$scrollId]]);
        }

        return $documents;
    }

    public function deleteIndex(): void
    {
        if ($this->indexExists()) {
            $this->client->indices()->delete(['index' => $this->index]);
        }
    }

    private function getIndexDefinition(): array
    {
        return [
            'index' => $this->index,
            'body' => [
                'settings' => [
                    'number_of_shards' => 1,
                    'number_of_replicas' => 0,
                    'analysis' => [
                        'analyzer' => [
                            $this->normalizedAnalyzer => [
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => ['lowercase', 'asciifolding'],
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => [
                        'text' => [
                            'type' => 'text',
                            'analyzer' => 'standard',
                        ],
                        'text_normalized' => [
                            'type' => 'text',
                            'analyzer' => $this->normalizedAnalyzer,
                        ],
                        'confidence' => ['type' => 'rank_feature'],
                        'canvas' => ['type' => 'keyword'],
                        'manifest' => ['type' => 'keyword'],
                        'coords' => ['type' => 'keyword'],
                        'location_key' => ['type' => 'keyword'],
                        'is_shingle' => ['type' => 'boolean'],
                        'timestamp' => ['type' => 'date'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $queryTokens
     * @return array<int, array<string, mixed>>
     */
    private function buildLineMatchClauses(string $query, string $normalizedQuery, array $queryTokens): array
    {
        $significantQuery = $this->buildSignificantQuery($queryTokens);
        $clauses = [
            [
                'match_phrase' => [
                    'text' => [
                        'query' => $query,
                        'boost' => 20,
                    ],
                ],
            ],
            [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'boost' => 16,
                    ],
                ],
            ],
            [
                'match' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'operator' => 'and',
                        'boost' => 8,
                    ],
                ],
            ],
        ];

        if ($significantQuery !== null) {
            $clauses[] = [
                'match' => [
                    'text_normalized' => [
                        'query' => $significantQuery,
                        'operator' => 'and',
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 1,
                        'max_expansions' => 15,
                        'boost' => 4,
                    ],
                ],
            ];
        }

        if (count($queryTokens) > 1) {
            $clauses[] = [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'slop' => 1,
                        'boost' => 12,
                    ],
                ],
            ];
        }

        if ($significantQuery !== null && count($queryTokens) >= 3) {
            $clauses[] = [
                'match' => [
                    'text_normalized' => [
                        'query' => $significantQuery,
                        'operator' => 'or',
                        'minimum_should_match' => $this->minimumShouldMatchForTokens(count($this->tokenize($significantQuery))),
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 1,
                        'max_expansions' => 10,
                        'boost' => 2,
                    ],
                ],
            ];
        }

        return $clauses;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildShingleMatchClauses(string $normalizedQuery): array
    {
        return [
            [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'boost' => 12,
                    ],
                ],
            ],
            [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'slop' => 1,
                        'boost' => 8,
                    ],
                ],
            ],
            [
                'match_phrase_prefix' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'max_expansions' => 10,
                        'boost' => 4,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildRescoreClauses(string $query, string $normalizedQuery, bool $usesPhraseRecovery): array
    {
        $clauses = [
            [
                'match_phrase' => [
                    'text' => [
                        'query' => $query,
                        'boost' => 10,
                    ],
                ],
            ],
            [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'boost' => 8,
                    ],
                ],
            ],
            [
                'match_phrase' => [
                    'text_normalized' => [
                        'query' => $normalizedQuery,
                        'slop' => 1,
                        'boost' => 5,
                    ],
                ],
            ],
        ];

        if ($usesPhraseRecovery) {
            $clauses[] = [
                'bool' => [
                    'filter' => [
                        ['term' => ['is_shingle' => true]],
                    ],
                    'must' => [
                        [
                            'match_phrase' => [
                                'text_normalized' => [
                                    'query' => $normalizedQuery,
                                    'boost' => 6,
                                ],
                            ],
                        ],
                    ],
                ],
            ];
        }

        return $clauses;
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $normalizedQuery): array
    {
        if ($normalizedQuery === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalizedQuery)));
    }

    private function minimumShouldMatchForTokens(int $tokenCount): string
    {
        return match (true) {
            $tokenCount <= 3 => '100%',
            $tokenCount === 4 => '3<75%',
            default => '75%',
        };
    }

    /**
     * @param  list<string>  $queryTokens
     */
    private function buildSignificantQuery(array $queryTokens): ?string
    {
        $significantTokens = array_values(array_filter(
            $queryTokens,
            fn (string $token): bool => mb_strlen($token) >= 4
        ));

        if ($significantTokens === []) {
            $significantTokens = array_values(array_filter(
                $queryTokens,
                fn (string $token): bool => mb_strlen($token) >= 3
            ));
        }

        if ($significantTokens === []) {
            return null;
        }

        return implode(' ', $significantTokens);
    }

}
