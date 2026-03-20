<?php

namespace Tests\Feature;

use App\Services\ElasticsearchOCRService;
use Mockery;
use Tests\TestCase;

class OCRSearchControllerTest extends TestCase
{
    public function test_ocr_search_returns_iiif_annotations_and_deduplicates_hits(): void
    {
        $service = Mockery::mock(ElasticsearchOCRService::class);
        $service->shouldReceive('search')
            ->once()
            ->with('lib000001', 'workers strike', 20, 1)
            ->andReturn([
                'results' => [
                    [
                        '_id' => 'line-1',
                        'text' => 'workers strike in the mills',
                        'canvas' => 'https://example.com/canvas/1',
                        'coords' => '10,20,30,40',
                        'is_shingle' => false,
                        '_highlight' => [
                            'text' => ['Before [[MATCH]]workers strike[[/MATCH]] after'],
                        ],
                    ],
                    [
                        '_id' => 'line-2',
                        'text' => 'workers strike in the mills',
                        'canvas' => 'https://example.com/canvas/1',
                        'coords' => '10,20,30,40',
                        'is_shingle' => false,
                        '_highlight' => [
                            'text' => ['Before [[MATCH]]workers strike[[/MATCH]] after'],
                        ],
                    ],
                    [
                        '_id' => 'bad-hit',
                        'text' => '',
                        'canvas' => 'https://example.com/canvas/1',
                        'coords' => '10,20,30,40',
                        'is_shingle' => false,
                        '_highlight' => [],
                    ],
                ],
                'total' => 3,
                'page' => 1,
                'per_page' => 20,
                'total_pages' => 1,
            ]);

        $this->instance(ElasticsearchOCRService::class, $service);

        $response = $this->getJson('/api/search/lib000001?q=workers+strike');

        $response->assertOk()
            ->assertJsonPath('@type', 'sc:AnnotationList')
            ->assertJsonPath('within.total', 3)
            ->assertJsonPath('startIndex', 0)
            ->assertJsonCount(1, 'resources')
            ->assertJsonCount(1, 'hits')
            ->assertJsonPath('resources.0.on', 'https://example.com/canvas/1#xywh=10,20,30,40')
            ->assertJsonPath('hits.0.before', 'Before')
            ->assertJsonPath('hits.0.match', 'workers strike')
            ->assertJsonPath('hits.0.after', 'after');
    }

    public function test_ocr_search_validates_request_parameters(): void
    {
        $response = $this->getJson('/api/search/lib000001?q=a&per_page=100&page=0');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['q', 'per_page', 'page']);
    }

    public function test_ocr_search_returns_server_error_when_backend_search_fails(): void
    {
        $service = Mockery::mock(ElasticsearchOCRService::class);
        $service->shouldReceive('search')
            ->once()
            ->andThrow(new \RuntimeException('Search exploded'));

        $this->instance(ElasticsearchOCRService::class, $service);

        $response = $this->getJson('/api/search/lib000001?q=workers+strike');

        $response->assertStatus(500)
            ->assertJson([
                'error' => 'OCR search failed.',
            ]);
    }
}
