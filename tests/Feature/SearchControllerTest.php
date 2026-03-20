<?php

namespace Tests\Feature;

use App\Services\ElasticsearchService;
use Mockery;
use Tests\TestCase;

class SearchControllerTest extends TestCase
{
    public function test_search_page_passes_query_pagination_and_filters_to_service(): void
    {
        $service = Mockery::mock(ElasticsearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with(
                'workers strike',
                20,
                2,
                [
                    'type' => 'books',
                    'metadata' => [
                        'author' => 'John Reed',
                    ],
                ]
            )
            ->andReturn([
                'results' => [
                    [
                        'identifier' => 'lib000001',
                        'title' => 'The Liberator, January 1911',
                        'date_string' => 'January 1911',
                        'match_page' => 4,
                        'match_source_label' => 'OCR match',
                        'matched_phrase' => 'workers strike',
                        '_highlight' => [
                            'ocr_pages' => ['...<em class="search-highlight">workers strike</em>...'],
                        ],
                    ],
                ],
                'total' => 23,
                'page' => 2,
                'per_page' => 20,
                'total_pages' => 2,
                'facets' => [
                    'types' => [
                        ['key' => 'books', 'count' => 23],
                    ],
                    'metadata' => [
                        'author' => [
                            ['value' => 'John Reed', 'count' => 5],
                        ],
                    ],
                ],
            ]);

        $this->instance(ElasticsearchService::class, $service);

        $response = $this->get('/search?q=workers+strike&page=2&type=books&metadata[author]=John+Reed');

        $response->assertOk();
        $response->assertViewHas('displayTitle', 'Search results for "workers strike": '.config('app.name'));
        $response->assertViewHas('query', 'workers strike');
        $response->assertSeeText('Found 23 results for');
        $response->assertSeeText('workers strike');
        $response->assertSee('The Liberator, January 1911');
        $response->assertSee('January 1911');
        $response->assertSeeText('Match on page 4');
    }

    public function test_search_page_does_not_hit_service_for_blank_query(): void
    {
        $service = Mockery::mock(ElasticsearchService::class);
        $service->shouldNotReceive('search');

        $this->instance(ElasticsearchService::class, $service);

        $response = $this->get('/search');

        $response->assertOk();
        $response->assertViewHas('displayTitle', 'Search: '.config('app.name'));
        $response->assertDontSee('Found ');
    }

    public function test_search_page_renders_no_results_state(): void
    {
        $service = Mockery::mock(ElasticsearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->andReturn([
                'results' => [],
                'total' => 0,
                'page' => 1,
                'per_page' => 20,
                'total_pages' => 0,
                'facets' => [
                    'types' => [],
                    'metadata' => [],
                ],
            ]);

        $this->instance(ElasticsearchService::class, $service);

        $response = $this->get('/search?q=nonexistent');

        $response->assertOk();
        $response->assertViewHas('query', 'nonexistent');
        $response->assertSeeText('No results found for');
        $response->assertSeeText('nonexistent');
    }

    public function test_search_page_preserves_result_order_for_ranking_sensitive_queries(): void
    {
        $service = Mockery::mock(ElasticsearchService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('john reed', 20, 1, [
                'type' => null,
                'metadata' => [],
            ])
            ->andReturn([
                'results' => [
                    [
                        'identifier' => 'lib000010',
                        'title' => 'The Liberator, March 1918',
                        'date_string' => 'March 1918',
                        'match_page' => 1,
                        'match_source_label' => 'Title match',
                        'matched_phrase' => 'John Reed',
                        '_highlight' => [
                            'title' => ['The Liberator, <em class="search-highlight">John Reed</em> Issue'],
                        ],
                    ],
                    [
                        'identifier' => 'lib000011',
                        'title' => 'The Liberator, April 1918',
                        'date_string' => 'April 1918',
                        'match_page' => 8,
                        'match_source_label' => 'Metadata match',
                        'matched_phrase' => 'John Reed',
                        '_highlight' => [
                            'author_text' => ['<em class="search-highlight">John Reed</em>'],
                        ],
                    ],
                ],
                'total' => 2,
                'page' => 1,
                'per_page' => 20,
                'total_pages' => 1,
                'facets' => [
                    'types' => [],
                    'metadata' => [],
                ],
            ]);

        $this->instance(ElasticsearchService::class, $service);

        $response = $this->get('/search?q=john+reed');

        $response->assertOk();
        $content = $response->getContent();

        $firstPosition = strpos($content, 'The Liberator, March 1918');
        $secondPosition = strpos($content, 'The Liberator, April 1918');

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($secondPosition);
        $this->assertLessThan($secondPosition, $firstPosition);
    }
}
