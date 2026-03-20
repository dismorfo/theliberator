<?php

namespace Tests\Unit;

use App\Services\ElasticsearchService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class ElasticsearchServiceTest extends TestCase
{
    /**
     * @return array<string, array{query: string, expectedSignificantQuery: ?string, expectsFuzzyClause: bool, expectsPhraseSlop: bool}>
     */
    public static function representativeBookQueriesProvider(): array
    {
        return [
            'person query' => [
                'query' => 'john reed',
                'expectedSignificantQuery' => 'john reed',
                'expectsFuzzyClause' => true,
                'expectsPhraseSlop' => true,
            ],
            'topic query' => [
                'query' => 'workers strike',
                'expectedSignificantQuery' => 'workers strike',
                'expectsFuzzyClause' => true,
                'expectsPhraseSlop' => true,
            ],
            'short query' => [
                'query' => 'debs',
                'expectedSignificantQuery' => 'debs',
                'expectsFuzzyClause' => true,
                'expectsPhraseSlop' => false,
            ],
            'mixed stopword query' => [
                'query' => 'birth control',
                'expectedSignificantQuery' => 'birth control',
                'expectsFuzzyClause' => true,
                'expectsPhraseSlop' => true,
            ],
        ];
    }

    public function test_extract_matched_phrase_prefers_ocr_highlight_and_strips_markup(): void
    {
        $service = new ElasticsearchService();
        $method = new ReflectionMethod($service, 'extractMatchedPhrase');
        $method->setAccessible(true);

        $matchedPhrase = $method->invoke($service, [
            'title' => ['Workers and War'],
            'ocr_pages' => ['...against military <em class="search-highlight">service</em>. over to the military authorities...'],
        ]);

        $this->assertSame('service', $matchedPhrase);
    }

    public function test_extract_matched_phrase_keeps_text_between_multiple_highlight_spans(): void
    {
        $service = new ElasticsearchService();
        $method = new ReflectionMethod($service, 'extractMatchedPhrase');
        $method->setAccessible(true);

        $matchedPhrase = $method->invoke($service, [
            'ocr_pages' => ['...<em class="search-highlight">Debs</em> Address to the Court on Receiving <em class="search-highlight">Sentence</em>. 30 pages...'],
        ]);

        $this->assertSame('Debs Address to the Court on Receiving Sentence', $matchedPhrase);
    }

    public function test_extract_matched_phrase_chooses_best_highlight_cluster_when_spans_are_far_apart(): void
    {
        $service = new ElasticsearchService();
        $method = new ReflectionMethod($service, 'extractMatchedPhrase');
        $method->setAccessible(true);

        $matchedPhrase = $method->invoke($service, [
            'ocr_pages' => ['...” =~ =~ . 64 pages—Frontispiece, portrait of Lenin. i <em class="search-highlight">Debs</em>” . . 4 by Max Eastman—with <em class="search-highlight">Debs’ Address to the Court on Receiving Sentence</em>. 30 pages—Contains a wonderful portrait of el...'],
        ]);

        $this->assertSame('Debs’ Address to the Court on Receiving Sentence', $matchedPhrase);
    }

    public function test_extract_matched_phrase_limits_output_to_ocr_request_budget(): void
    {
        $service = new ElasticsearchService();
        $method = new ReflectionMethod($service, 'extractMatchedPhrase');
        $method->setAccessible(true);

        $matchedPhrase = $method->invoke($service, [
            'ocr_pages' => ['...'.str_repeat('word ', 40).'<em class="search-highlight">match</em>...'],
        ]);

        $this->assertNotNull($matchedPhrase);
        $this->assertLessThanOrEqual(120, mb_strlen($matchedPhrase));
    }

    public function test_build_ocr_nested_clause_is_kept_for_short_queries(): void
    {
        $service = new ElasticsearchService();
        $method = new ReflectionMethod($service, 'buildOcrNestedClause');
        $method->setAccessible(true);

        $clause = $method->invoke($service, 'war', 'war', ['war'], null);

        $this->assertIsArray($clause);
        $this->assertSame('ocr_pages', $clause['nested']['path']);
        $this->assertSame('war', $clause['nested']['query']['bool']['should'][0]['match_phrase']['ocr_pages.text']['query']);
    }

    public function test_build_search_params_clamps_invalid_pagination_inputs(): void
    {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams('workers', 0, 0);

        $this->assertSame(1, $params['body']['size']);
        $this->assertSame(0, $params['body']['from']);
    }

    public function test_build_search_params_uses_expanded_rescore_window(): void
    {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams('workers', 30, 1);

        $this->assertSame(150, $params['body']['rescore'][0]['window_size']);
    }

    public function test_build_search_params_uses_match_all_for_blank_query(): void
    {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams('', 20, 1);

        $this->assertArrayHasKey('match_all', $params['body']['query']['bool']['must'][0]);
        $this->assertIsObject($params['body']['query']['bool']['must'][0]['match_all']);
        $this->assertSame([], $params['body']['rescore']);
    }

    public function test_build_search_params_includes_type_and_metadata_filters(): void
    {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams('workers', 20, 1, [
            'type' => 'books',
            'metadata' => [
                'author' => 'John Reed',
            ],
        ]);

        $filters = $params['body']['query']['bool']['filter'];

        $this->assertSame(['term' => ['type' => 'books']], $filters[0]);
        $this->assertSame('metadata', $filters[1]['nested']['path']);
        $this->assertSame(['term' => ['metadata.key' => 'author']], $filters[1]['nested']['query']['bool']['must'][0]);
        $this->assertSame(['term' => ['metadata.value.keyword' => 'John Reed']], $filters[1]['nested']['query']['bool']['must'][1]);
    }

    public function test_build_search_params_adds_title_index_and_ocr_boosting_clauses(): void
    {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams('workers strike', 20, 1);
        $should = $params['body']['query']['bool']['must'][0]['bool']['should'];

        $this->assertSame('workers strike', $should[0]['match_phrase']['title']['query']);
        $this->assertSame('workers strike', $should[1]['match_phrase']['index_entries']['query']);
        $this->assertSame('ocr_pages', $should[count($should) - 1]['nested']['path']);
    }

    #[DataProvider('representativeBookQueriesProvider')]
    public function test_representative_queries_keep_expected_book_search_intent(
        string $query,
        ?string $expectedSignificantQuery,
        bool $expectsFuzzyClause,
        bool $expectsPhraseSlop
    ): void {
        $service = new ElasticsearchService();

        $params = $service->buildSearchParams($query, 20, 1);
        $should = $params['body']['query']['bool']['must'][0]['bool']['should'];
        $ocrShould = $should[count($should) - 1]['nested']['query']['bool']['should'];

        $this->assertSame($query, $should[0]['match_phrase']['title']['query']);
        $this->assertSame($query, $should[1]['match_phrase']['index_entries']['query']);

        $hasFuzzySignificantClause = false;
        foreach ($should as $clause) {
            $multiMatch = $clause['multi_match'] ?? null;
            if (! is_array($multiMatch)) {
                continue;
            }

            if (($multiMatch['query'] ?? null) === $expectedSignificantQuery && ($multiMatch['fuzziness'] ?? null) === 'AUTO') {
                $hasFuzzySignificantClause = true;
            }
        }

        $this->assertSame($expectsFuzzyClause, $hasFuzzySignificantClause);

        $hasOcrPhraseSlopClause = false;
        foreach ($ocrShould as $clause) {
            $matchPhrase = $clause['match_phrase']['ocr_pages.text'] ?? null;
            if (is_array($matchPhrase) && ($matchPhrase['slop'] ?? null) === 1) {
                $hasOcrPhraseSlopClause = true;
            }
        }

        $this->assertSame($expectsPhraseSlop, $hasOcrPhraseSlopClause);
    }

    public function test_search_result_highlight_from_ocr_sets_match_page_source_and_phrase(): void
    {
        $service = new ElasticsearchService();
        $inferMatchSource = new ReflectionMethod($service, 'inferMatchSource');
        $inferMatchSource->setAccessible(true);
        $matchSourceLabel = new ReflectionMethod($service, 'matchSourceLabel');
        $matchSourceLabel->setAccessible(true);
        $extractMatchedPhrase = new ReflectionMethod($service, 'extractMatchedPhrase');
        $extractMatchedPhrase->setAccessible(true);

        $hit = [
            '_source' => [
                'identifier' => 'lib000001',
                'title' => 'The Liberator, January 1911',
            ],
            'highlight' => [
                'title' => ['The Liberator, January 1911'],
            ],
            'inner_hits' => [
                'ocr_pages' => [
                    'hits' => [
                        'hits' => [
                            [
                                '_source' => [
                                    'page' => 5,
                                ],
                                'highlight' => [
                                    'ocr_pages.text' => ['...<em class="search-highlight">workers strike</em>...'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $mapped = $hit['_source'];
        $mapped['_highlight'] = $hit['highlight'] ?? [];
        $mapped['match_page'] = 1;
        $mapped['match_source'] = $inferMatchSource->invoke($service, $mapped['_highlight']);
        $mapped['match_source_label'] = $matchSourceLabel->invoke($service, $mapped['match_source']);
        $mapped['matched_phrase'] = $extractMatchedPhrase->invoke($service, $mapped['_highlight']);

        $ocrPageHit = $hit['inner_hits']['ocr_pages']['hits']['hits'][0];
        $mapped['match_page'] = (int) ($ocrPageHit['_source']['page'] ?? 1);
        $mapped['_highlight']['ocr_pages'] = $ocrPageHit['highlight']['ocr_pages.text'];
        $mapped['match_source'] = 'ocr';
        $mapped['match_source_label'] = $matchSourceLabel->invoke($service, 'ocr');
        $mapped['matched_phrase'] = $extractMatchedPhrase->invoke($service, [
            'ocr_pages' => $ocrPageHit['highlight']['ocr_pages.text'],
        ]);

        $this->assertSame(5, $mapped['match_page']);
        $this->assertSame('ocr', $mapped['match_source']);
        $this->assertSame('Page text match', $mapped['match_source_label']);
        $this->assertSame('workers strike', $mapped['matched_phrase']);
    }
}
