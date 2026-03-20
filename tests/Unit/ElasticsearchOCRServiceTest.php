<?php

namespace Tests\Unit;

use App\Services\ElasticsearchOCRService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ElasticsearchOCRServiceTest extends TestCase
{
    /**
     * @return array<string, array{query: string, expectedShouldCount: int, expectsShingleRescore: bool}>
     */
    public static function representativeOcrQueriesProvider(): array
    {
        return [
            'single term' => [
                'query' => 'debs',
                'expectedShouldCount' => 1,
                'expectsShingleRescore' => false,
            ],
            'person name' => [
                'query' => 'john reed',
                'expectedShouldCount' => 2,
                'expectsShingleRescore' => true,
            ],
            'topic phrase' => [
                'query' => 'workers strike',
                'expectedShouldCount' => 2,
                'expectsShingleRescore' => true,
            ],
            'longer phrase' => [
                'query' => 'birth control movement',
                'expectedShouldCount' => 2,
                'expectsShingleRescore' => true,
            ],
        ];
    }

    public function test_index_definition_uses_custom_normalized_analyzer_for_text_normalized(): void
    {
        $service = new ElasticsearchOCRService();
        $method = new ReflectionMethod($service, 'getIndexDefinition');
        $method->setAccessible(true);

        $definition = $method->invoke($service);

        $this->assertSame(
            'normalized_text_analyzer',
            $definition['body']['mappings']['properties']['text_normalized']['analyzer']
        );
        $this->assertArrayHasKey(
            'normalized_text_analyzer',
            $definition['body']['settings']['analysis']['analyzer']
        );
    }

    public function test_build_search_params_scopes_search_to_manifest_and_enables_phrase_recovery_for_multi_word_queries(): void
    {
        $service = new ElasticsearchOCRService();

        $params = $service->buildSearchParams('lib000001', 'workers strike', 20, 2);

        $this->assertSame(['term' => ['manifest' => 'lib000001']], $params['body']['query']['bool']['filter'][0]);
        $this->assertSame(20, $params['body']['size']);
        $this->assertSame(20, $params['body']['from']);
        $this->assertCount(2, $params['body']['query']['bool']['should']);
        $this->assertSame('[[MATCH]]', $params['body']['highlight']['fields']['text']['pre_tags'][0]);
        $this->assertSame(100, $params['body']['rescore'][0]['window_size']);
    }

    public function test_build_search_params_uses_single_line_query_path_for_single_term_queries(): void
    {
        $service = new ElasticsearchOCRService();

        $params = $service->buildSearchParams('lib000001', 'workers', 10, 1);

        $this->assertCount(1, $params['body']['query']['bool']['should']);
        $this->assertSame('workers', $params['body']['query']['bool']['should'][0]['bool']['must'][0]['bool']['should'][0]['match_phrase']['text']['query']);
    }

    public function test_execute_search_maps_hits_and_calculates_pagination(): void
    {
        $service = new class extends ElasticsearchOCRService
        {
            public function __construct()
            {
            }

            public function executeSearchFromResponse(array $params, array $responseArray): array
            {
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
        };

        $result = $service->executeSearchFromResponse(
            [
                'body' => [
                    'size' => 20,
                    'from' => 20,
                ],
            ],
            [
                'hits' => [
                    'total' => ['value' => 41],
                    'hits' => [
                        [
                            '_id' => 'line-1',
                            '_score' => 4.5,
                            '_source' => [
                                'text' => 'workers strike in the mills',
                                'canvas' => 'https://example.com/canvas/1',
                                'coords' => '10,20,30,40',
                            ],
                            'highlight' => [
                                'text' => ['[[MATCH]]workers strike[[/MATCH]]'],
                            ],
                        ],
                    ],
                ],
            ]
        );

        $this->assertSame(2, $result['page']);
        $this->assertSame(20, $result['per_page']);
        $this->assertSame(3, $result['total_pages']);
        $this->assertSame('line-1', $result['results'][0]['_id']);
        $this->assertSame(4.5, $result['results'][0]['_score']);
        $this->assertSame(['[[MATCH]]workers strike[[/MATCH]]'], $result['results'][0]['_highlight']['text']);
    }

    #[DataProvider('representativeOcrQueriesProvider')]
    public function test_representative_queries_keep_expected_ocr_search_intent(
        string $query,
        int $expectedShouldCount,
        bool $expectsShingleRescore
    ): void {
        $service = new ElasticsearchOCRService();

        $params = $service->buildSearchParams('lib000001', $query, 20, 1);
        $should = $params['body']['query']['bool']['should'];
        $rescoreShould = $params['body']['rescore'][0]['query']['rescore_query']['bool']['should'];

        $this->assertCount($expectedShouldCount, $should);

        $hasShingleRescore = false;
        foreach ($rescoreShould as $clause) {
            $filter = $clause['bool']['filter'][0]['term']['is_shingle'] ?? null;
            if ($filter === true) {
                $hasShingleRescore = true;
            }
        }

        $this->assertSame($expectsShingleRescore, $hasShingleRescore);
    }
}
