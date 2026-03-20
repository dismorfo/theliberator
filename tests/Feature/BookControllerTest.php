<?php

namespace Tests\Feature;

use App\Services\CachedViewerApiService;
use Mockery;
use Tests\TestCase;

class BookControllerTest extends TestCase
{
    public function test_book_page_uses_normalized_release_date_and_schema_shape(): void
    {
        $service = Mockery::mock(CachedViewerApiService::class);
        $service->shouldReceive('getBook')
            ->once()
            ->with('lib000001')
            ->andReturn([
                'title' => 'The Liberator, January 1911',
                'release_date' => '1911-01-01',
                'thumbnail' => 'https://example.com/thumb.jpg',
                'iiif' => [
                    'image' => [
                        'items' => [
                            ['id' => 'page-1'],
                        ],
                    ],
                ],
            ]);

        $this->instance(CachedViewerApiService::class, $service);

        $response = $this->get('/book/lib000001/1');

        $response->assertOk();
        $response->assertSee('The Liberator, January 1911');
        $response->assertSee('1911-01-01', false);
        $response->assertSee('"@type": "PublicationIssue"', false);
        $response->assertSee('"datePublished": "1911-01-01"', false);
        $response->assertDontSee('"schema"', false);
    }

    public function test_book_page_returns_404_when_viewer_payload_has_no_pages(): void
    {
        $service = Mockery::mock(CachedViewerApiService::class);
        $service->shouldReceive('getBook')
            ->once()
            ->with('lib000001')
            ->andReturn([
                'title' => 'The Liberator, January 1911',
                'release_date' => '1911-01-01',
                'thumbnail' => null,
                'iiif' => [
                    'image' => [
                        'items' => [],
                    ],
                ],
            ]);

        $this->instance(CachedViewerApiService::class, $service);

        $this->get('/book/lib000001/1')->assertNotFound();
    }
}
