<?php

namespace App\Services;

use App\Services\Concerns\BuildsElasticsearchNames;
use Elastic\Elasticsearch\Client;
use Illuminate\Support\Str;
use RuntimeException;

class ElasticsearchService
{
    use BuildsElasticsearchNames;

    private Client $client;

    private string $index;

    private string $analyzer;

    public function __construct(?ElasticsearchClientFactory $clientFactory = null)
    {
        $factory = $clientFactory ?? app(ElasticsearchClientFactory::class);
        $this->client = $factory->make();

        $this->index = $this->buildIndexName('books');
        $this->analyzer = $this->buildAnalyzerName();
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

    public function createIndex(): void
    {
        if ($this->indexExists()) {
            return;
        }

        $this->client->indices()->create($this->getIndexDefinition());
    }

    public function recreateIndex(): void
    {
        if ($this->indexExists()) {
            $this->client->indices()->delete(['index' => $this->index]);
        }

        $this->client->indices()->create($this->getIndexDefinition());
    }

    public function indexDocument(array $document): void
    {
        $this->client->index([
            'index' => $this->index,
            'id' => $document['identifier'],
            'body' => $document,
        ]);
    }

    public function bulkIndex(array $documents): void
    {
        $params = ['body' => []];

        foreach ($documents as $doc) {
            $params['body'][] = [
                'index' => [
                    '_index' => $this->index,
                    '_id' => $doc['identifier'],
                ],
            ];
            $params['body'][] = $doc;
        }

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
            'Bulk book indexing failed: '.implode('; ', array_slice($failedItems, 0, 5))
        );
    }

    public function search(string $query, int $perPage = 20, int $page = 1, array $filters = []): array
    {
        $params = $this->buildSearchParams($query, $perPage, $page, $filters);

        $response = $this->client->search($params);
        $responseArray = $response->asArray();
        $hits = $responseArray['hits']['hits'];
        $total = $responseArray['hits']['total']['value'];
        $resolvedPerPage = max(1, (int) ($params['body']['size'] ?? $perPage));
        $resolvedPage = isset($params['body']['from'])
            ? ((int) ($params['body']['from'] / $resolvedPerPage) + 1)
            : 1;

        $results = array_map(function ($hit) {
            $source = $hit['_source'];
            $source['_highlight'] = $hit['highlight'] ?? [];
            $source['match_page'] = 1;
            $source['match_source'] = $this->inferMatchSource($source['_highlight']);
            $source['match_source_label'] = $this->matchSourceLabel($source['match_source']);
            $source['matched_phrase'] = $this->extractMatchedPhrase($source['_highlight']);

            if (isset($hit['inner_hits']['ocr_pages']['hits']['hits'][0])) {
                $ocrPageHit = $hit['inner_hits']['ocr_pages']['hits']['hits'][0];
                $source['match_page'] = (int) ($ocrPageHit['_source']['page'] ?? 1);
                if (isset($ocrPageHit['highlight']['ocr_pages.text'])) {
                    $source['_highlight']['ocr_pages'] = $ocrPageHit['highlight']['ocr_pages.text'];
                    $source['match_source'] = 'ocr';
                    $source['match_source_label'] = $this->matchSourceLabel('ocr');
                    $source['matched_phrase'] = $this->extractMatchedPhrase([
                        'ocr_pages' => $ocrPageHit['highlight']['ocr_pages.text'],
                    ]);
                }
            }

            return $source;
        }, $hits);

        $facets = $this->extractFacets($responseArray);

        return [
            'results' => $results,
            'total' => $total,
            'page' => $resolvedPage,
            'per_page' => $resolvedPerPage,
            'total_pages' => (int) ceil($total / $resolvedPerPage),
            'facets' => $facets,
        ];
    }

    public function buildSearchParams(string $query, int $perPage = 20, int $page = 1, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $filter = [];
        $should = [];
        $trimmedQuery = trim($query);
        $normalizedQuery = $this->normalizeText($trimmedQuery);

        if ($trimmedQuery !== '') {
            $queryTokens = $this->tokenize($normalizedQuery);
            $should[] = [
                'match_phrase' => [
                    'title' => [
                        'query' => $trimmedQuery,
                        'boost' => 18,
                    ],
                ],
            ];

            $should[] = [
                'match_phrase' => [
                    'index_entries' => [
                        'query' => $trimmedQuery,
                        'boost' => 12,
                    ],
                ],
            ];

            $should[] = [
                'multi_match' => [
                    'query' => $trimmedQuery,
                    'fields' => [
                        'title^10',
                        'date_string^5',
                        'author_text^5',
                        'subject_text^4',
                        'publisher_text^3',
                        'contributor_text^3',
                        'index_entries^6',
                    ],
                    'type' => 'cross_fields',
                    'operator' => 'and',
                    'boost' => 7,
                ],
            ];

            $significantQuery = $this->buildSignificantQuery($queryTokens);
            if ($significantQuery !== null) {
                $should[] = [
                    'multi_match' => [
                        'query' => $significantQuery,
                        'fields' => [
                            'title^7',
                            'author_text^4',
                            'subject_text^3',
                            'index_entries^5',
                        ],
                        'type' => 'best_fields',
                        'operator' => 'and',
                        'fuzziness' => 'AUTO',
                        'prefix_length' => 1,
                        'boost' => 4,
                    ],
                ];
            }

            $ocrNestedClause = $this->buildOcrNestedClause($trimmedQuery, $normalizedQuery, $queryTokens, $significantQuery);
            if ($ocrNestedClause !== null) {
                $should[] = $ocrNestedClause;
            }
        }

        if (! empty($filters['type'])) {
            $filter[] = ['term' => ['type' => $filters['type']]];
        }

        if (! empty($filters['metadata'])) {
            foreach ($filters['metadata'] as $key => $value) {
                $filter[] = [
                    'nested' => [
                        'path' => 'metadata',
                        'query' => [
                            'bool' => [
                                'must' => [
                                    ['term' => ['metadata.key' => $key]],
                                    ['term' => ['metadata.value.keyword' => $value]],
                                ],
                            ],
                        ],
                    ],
                ];
            }
        }

        return [
            'index' => $this->index,
            'body' => [
                'query' => [
                    'bool' => [
                        'must' => $should ? [['bool' => ['should' => $should, 'minimum_should_match' => 1]]] : [['match_all' => (object) []]],
                        'filter' => $filter,
                    ],
                ],
                'rescore' => $trimmedQuery !== '' ? [
                    [
                        'window_size' => max(100, $perPage * 5),
                        'query' => [
                            'score_mode' => 'total',
                            'query_weight' => 0.8,
                            'rescore_query_weight' => 2.2,
                            'rescore_query' => [
                                'bool' => [
                                    'should' => [
                                        [
                                            'match_phrase' => [
                                                'title' => [
                                                    'query' => $trimmedQuery,
                                                    'boost' => 16,
                                                ],
                                            ],
                                        ],
                                        [
                                            'match_phrase' => [
                                                'index_entries' => [
                                                    'query' => $trimmedQuery,
                                                    'boost' => 10,
                                                ],
                                            ],
                                        ],
                                        [
                                            'nested' => [
                                                'path' => 'ocr_pages',
                                                'score_mode' => 'max',
                                                'query' => [
                                                    'match_phrase' => [
                                                        'ocr_pages.text' => [
                                                            'query' => $trimmedQuery,
                                                            'boost' => 4,
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'minimum_should_match' => 1,
                                ],
                            ],
                        ],
                    ],
                ] : [],
                'highlight' => [
                    'pre_tags' => ['<em class="search-highlight">'],
                    'post_tags' => ['</em>'],
                    'fields' => [
                        'title' => (object) [],
                        'date_string' => (object) [],
                        'index_entries' => ['number_of_fragments' => 1],
                        'author_text' => ['number_of_fragments' => 1],
                        'subject_text' => ['number_of_fragments' => 1],
                    ],
                ],
                'aggs' => [
                    'types' => [
                        'terms' => ['field' => 'type', 'size' => 20],
                    ],
                    'metadata_keys' => [
                        'nested' => ['path' => 'metadata'],
                        'aggs' => [
                            'by_key' => [
                                'terms' => ['field' => 'metadata.key', 'size' => 50],
                                'aggs' => [
                                    'by_value' => [
                                        'terms' => ['field' => 'metadata.value.keyword', 'size' => 50],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'size' => $perPage,
                'from' => ($page - 1) * $perPage,
                'track_total_hits' => true,
            ],
        ];
    }

    private function extractFacets(array $response): array
    {
        $facets = [
            'types' => [],
            'metadata' => [],
        ];

        if (isset($response['aggregations']['types']['buckets'])) {
            foreach ($response['aggregations']['types']['buckets'] as $bucket) {
                $facets['types'][] = [
                    'key' => $bucket['key'],
                    'count' => $bucket['doc_count'],
                ];
            }
        }

        if (isset($response['aggregations']['metadata_keys']['by_key']['buckets'])) {
            foreach ($response['aggregations']['metadata_keys']['by_key']['buckets'] as $keyBucket) {
                $key = $keyBucket['key'];
                if (! isset($facets['metadata'][$key])) {
                    $facets['metadata'][$key] = [];
                }
                if (isset($keyBucket['by_value']['buckets'])) {
                    foreach ($keyBucket['by_value']['buckets'] as $valueBucket) {
                        $facets['metadata'][$key][] = [
                            'value' => $valueBucket['key'],
                            'count' => $valueBucket['doc_count'],
                        ];
                    }
                }
            }
        }

        return $facets;
    }

    /**
     * Debug utility for inspecting the full book index outside paginated search responses.
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
                            $this->analyzer => [
                                'type' => 'custom',
                                'tokenizer' => 'standard',
                                'filter' => ['lowercase', 'asciifolding'],
                            ],
                        ],
                    ],
                ],
                'mappings' => [
                    'properties' => [
                        'nid' => ['type' => 'keyword'],
                        'identifier' => ['type' => 'keyword'],
                        'title' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                            'fields' => [
                                'keyword' => ['type' => 'keyword'],
                            ],
                        ],
                        'type' => ['type' => 'keyword'],
                        'date_string' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'author_text' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'subject_text' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'publisher_text' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'contributor_text' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'index_entries' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'ocr_text' => [
                            'type' => 'text',
                            'analyzer' => $this->analyzer,
                        ],
                        'ocr_pages' => [
                            'type' => 'nested',
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'text' => [
                                    'type' => 'text',
                                    'analyzer' => $this->analyzer,
                                ],
                            ],
                        ],
                        'metadata' => [
                            'type' => 'nested',
                            'properties' => [
                                'key' => ['type' => 'keyword'],
                                'value' => [
                                    'type' => 'text',
                                    'analyzer' => $this->analyzer,
                                    'fields' => [
                                        'keyword' => ['type' => 'keyword'],
                                    ],
                                ],
                                'label' => ['type' => 'keyword'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function normalizeText(string $text): string
    {
        $normalizedText = mb_strtolower($text);
        $normalizedText = preg_replace('/[^\pL\pN\s]+/u', ' ', $normalizedText) ?? $normalizedText;

        return trim(preg_replace('/\s+/', ' ', $normalizedText) ?? $normalizedText);
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

    /**
     * @param  list<string>  $queryTokens
     * @return array<string, mixed>|null
     */
    private function buildOcrNestedClause(string $query, string $normalizedQuery, array $queryTokens, ?string $significantQuery): ?array
    {
        $significantTokens = $significantQuery !== null ? $this->tokenize($significantQuery) : [];
        $should = [
            [
                'match_phrase' => [
                    'ocr_pages.text' => [
                        'query' => $query,
                        'boost' => 6,
                    ],
                ],
            ],
        ];

        if (count($queryTokens) > 1) {
            $should[] = [
                'match_phrase' => [
                    'ocr_pages.text' => [
                        'query' => $normalizedQuery,
                        'slop' => 1,
                        'boost' => 4,
                    ],
                ],
            ];
        }

        if ($significantQuery !== null) {
            $should[] = [
                'match' => [
                    'ocr_pages.text' => [
                        'query' => $significantQuery,
                        'operator' => 'and',
                        'minimum_should_match' => count($significantTokens) > 1 ? '100%' : '1',
                        'boost' => 2,
                    ],
                ],
            ];
        }

        return [
            'nested' => [
                'path' => 'ocr_pages',
                'score_mode' => 'max',
                'query' => [
                    'bool' => [
                        'should' => $should,
                        'minimum_should_match' => 1,
                    ],
                ],
                'inner_hits' => [
                    'size' => 1,
                    'highlight' => [
                        'pre_tags' => ['<em class="search-highlight">'],
                        'post_tags' => ['</em>'],
                        'fields' => [
                            'ocr_pages.text' => [
                                'fragment_size' => 180,
                                'number_of_fragments' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $highlight
     */
    private function inferMatchSource(array $highlight): string
    {
        if (isset($highlight['title'])) {
            return 'title';
        }

        if (isset($highlight['index_entries'])) {
            return 'index';
        }

        if (isset($highlight['author_text']) || isset($highlight['subject_text']) || isset($highlight['date_string'])) {
            return 'metadata';
        }

        return 'book';
    }

    private function matchSourceLabel(string $source): string
    {
        return match ($source) {
            'title' => 'Title match',
            'index' => 'Collection index',
            'metadata' => 'Metadata match',
            'ocr' => 'Page text match',
            default => 'Book match',
        };
    }

    /**
     * @param  array<string, mixed>  $highlight
     */
    private function extractMatchedPhrase(array $highlight): ?string
    {
        $priorityFields = [
            'ocr_pages',
            'title',
            'index_entries',
            'author_text',
            'subject_text',
            'date_string',
        ];

        foreach ($priorityFields as $field) {
            $fragments = $highlight[$field] ?? null;
            if (! is_array($fragments) || ! isset($fragments[0]) || ! is_string($fragments[0])) {
                continue;
            }

            $phrase = $this->sanitizeMatchedPhrase($fragments[0]);
            if ($phrase !== null) {
                return $phrase;
            }
        }

        return null;
    }

    private function sanitizeMatchedPhrase(string $fragment): ?string
    {
        $highlightedText = $this->extractHighlightedText($fragment);
        $text = html_entity_decode(strip_tags($highlightedText ?? $fragment), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/^\.\.\.\s*|\s*\.\.\.$/u', '', $text) ?? $text;
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if ($text === '') {
            return null;
        }

        return Str::limit($text, 120, '');
    }

    private function buildAnalyzerName(): string
    {
        return $this->buildIndexName('analyzer');
    }

    private function extractHighlightedText(string $fragment): ?string
    {
        if (! preg_match_all('/<em\b[^>]*>(.*?)<\/em>/isu', $fragment, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $spans = array_values(array_filter(
            $matches[0],
            fn (mixed $match): bool => is_array($match) && isset($match[0], $match[1])
        ));

        if ($spans === []) {
            return null;
        }

        $clusters = [];
        $currentCluster = [];

        foreach ($spans as $index => $span) {
            if ($currentCluster === []) {
                $currentCluster[] = $span;
                continue;
            }

            $previousSpan = $currentCluster[count($currentCluster) - 1];
            $gapStart = $previousSpan[1] + strlen($previousSpan[0]);
            $gapLength = $span[1] - $gapStart;
            $gap = $gapLength > 0 ? substr($fragment, $gapStart, $gapLength) : '';

            if ($this->shouldJoinHighlightCluster($gap)) {
                $currentCluster[] = $span;
                continue;
            }

            $clusters[] = $currentCluster;
            $currentCluster = [$span];
        }

        if ($currentCluster !== []) {
            $clusters[] = $currentCluster;
        }

        $bestCandidate = null;
        $bestScore = null;

        foreach ($clusters as $cluster) {
            $firstSpan = $cluster[0];
            $lastSpan = $cluster[count($cluster) - 1];
            $start = $firstSpan[1];
            $length = ($lastSpan[1] + strlen($lastSpan[0])) - $start;

            if ($length <= 0) {
                continue;
            }

            $candidate = substr($fragment, $start, $length);
            if ($candidate === false || $candidate === '') {
                continue;
            }

            $plainText = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
            if ($plainText === '') {
                continue;
            }

            $score = (count($cluster) * 1000) + mb_strlen($plainText);
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $bestCandidate = $candidate;
            }
        }

        return $bestCandidate;
    }

    private function shouldJoinHighlightCluster(string $gap): bool
    {
        if (str_contains($gap, '...') || str_contains($gap, '…') || str_contains($gap, '||')) {
            return false;
        }

        if (preg_match('/\d/u', $gap) === 1) {
            return false;
        }

        $symbolCount = preg_match_all('/[^\pL\pN\s]/u', html_entity_decode(strip_tags($gap), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (is_int($symbolCount) && $symbolCount > 2) {
            return false;
        }

        $normalizedGap = html_entity_decode(strip_tags($gap), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalizedGap = trim(preg_replace('/[^\pL\pN]+/u', ' ', $normalizedGap) ?? $normalizedGap);

        if ($normalizedGap === '') {
            return true;
        }

        $wordCount = count(array_filter(explode(' ', $normalizedGap)));

        return $wordCount <= 6;
    }
}
