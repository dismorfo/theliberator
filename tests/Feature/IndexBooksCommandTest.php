<?php

namespace Tests\Feature;

use App\Console\Commands\IndexBooksCommand;
use App\Services\CollectionIndexParser;
use App\Services\ElasticsearchService;
use App\Services\PublicationIssueTitleParser;
use App\Services\ViewerApiClient;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use Illuminate\Support\Facades\View;

class IndexBooksCommandTest extends TestCase
{
    public function test_books_index_command_reports_failure_when_bulk_indexing_fails(): void
    {
        View::shouldReceive('make')
            ->once()
            ->andReturn(new class
            {
                public function render(): string
                {
                    return '';
                }
            });

        $elasticsearch = Mockery::mock(ElasticsearchService::class);
        $elasticsearch->shouldReceive('indexExists')->once()->andReturn(false);
        $elasticsearch->shouldReceive('createIndex')->once();
        $elasticsearch->shouldReceive('bulkIndex')->once()->andThrow(new RuntimeException('Bulk book indexing failed'));

        $indexParser = Mockery::mock(CollectionIndexParser::class);
        $indexParser->shouldReceive('parse')->once()->andReturn([]);

        $viewerApiClient = Mockery::mock(ViewerApiClient::class);
        $viewerApiClient->shouldReceive('getObjects')->once()->andReturn([
            [
                'identifier' => 'lib000001',
                'title' => 'The Liberator, January 1911',
                'uri' => 'https://example.com/object/lib000001',
            ],
        ]);
        $viewerApiClient->shouldReceive('getJson')->once()->andReturn([
            'metadata' => [],
        ]);

        $command = new IndexBooksCommand(
            $elasticsearch,
            $indexParser,
            app(PublicationIssueTitleParser::class),
            $viewerApiClient,
        );

        $this->app->instance(IndexBooksCommand::class, $command);

        $this->artisan('books:index')
            ->expectsOutput('Rendering collection index from local view...')
            ->expectsOutput('Parsed 0 dates from collection index.')
            ->expectsOutput('Fetching books from external API...')
            ->expectsOutput('Found 1 books.')
            ->expectsOutput('Fetching metadata for each book...')
            ->expectsOutput('Indexing 1 books...')
            ->expectsOutput('Bulk book indexing failed')
            ->assertExitCode(1);
    }
}
