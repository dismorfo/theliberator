<?php

namespace App\Console\Commands;

use App\Services\CollectionIndexParser;
use App\Services\ElasticsearchService;
use App\Services\PublicationIssueTitleParser;
use App\Services\ViewerApiClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\View;

class IndexBooksCommand extends Command
{
    protected $signature = 'books:index {--recreate : Recreate the index before indexing}';

    protected $description = 'Index all books from the external API to Elasticsearch';

    public function __construct(
        private ElasticsearchService $elasticsearch,
        private CollectionIndexParser $indexParser,
        private PublicationIssueTitleParser $titleParser,
        private ViewerApiClient $viewerApiClient,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $this->info('Rendering collection index from local view...');

            $parsedIndex = $this->indexParser->parse(View::make('collectionindex', [
                'displayTitle' => config('app.name'),
            ])->render());
            $this->info('Parsed '.count($parsedIndex).' dates from collection index.');

            $this->info('Fetching books from external API...');

            $documents = $this->viewerApiClient->getObjects($this->collectionCode(), $this->apiRows());

            if ($documents === []) {
                $this->error('Failed to fetch books from API');

                return Command::FAILURE;
            }

            $this->info('Found '.count($documents).' books.');

            if ($this->option('recreate')) {
                $this->info('Recreating index...');
                $this->elasticsearch->recreateIndex();
            } else {
                if (! $this->elasticsearch->indexExists()) {
                    $this->elasticsearch->createIndex();
                } else {
                    $this->warn('Index already exists, skipping creation. Use --recreate to recreate.');
                }
            }

            $this->info('Fetching metadata for each book...');

            $bar = $this->output->createProgressBar(count($documents));

            $bar->start();

            $indexedDocuments = [];

            foreach ($documents as $doc) {
                $metadataData = isset($doc['uri']) ? $this->viewerApiClient->getJson((string) $doc['uri']) : null;

                $dateString = $this->titleParser->extractDateString((string) ($doc['title'] ?? ''));

                $doc['date_string'] = $dateString;

                if ($metadataData !== null) {
                    $rawMetadata = $metadataData['metadata'] ?? [];

                    $flattenedMetadata = [];
                    foreach ($rawMetadata as $key => $meta) {
                        if (! $meta || ! isset($meta['value'])) {
                            continue;
                        }
                        $values = is_array($meta['value']) ? $meta['value'] : [($meta['value'] ?? '')];
                        foreach ($values as $value) {
                            $flattenedMetadata[] = [
                                'key' => $key,
                                'value' => is_array($value) ? ($value['name'] ?? json_encode($value)) : $value,
                                'label' => $meta['label'] ?? $key,
                            ];
                        }
                    }

                    // Add parsed index entries as metadata
                    if (isset($parsedIndex[$dateString])) {
                        foreach ($parsedIndex[$dateString] as $entry) {
                            $flattenedMetadata[] = [
                                'key' => 'index_entry',
                                'value' => $entry,
                                'label' => 'Index Entry',
                            ];
                        }
                    }

                    $doc['metadata'] = $flattenedMetadata;
                    $doc['author_text'] = $this->extractMetadataText($flattenedMetadata, 'author');
                    $doc['subject_text'] = $this->extractMetadataText($flattenedMetadata, 'subject');
                    $doc['publisher_text'] = $this->extractMetadataText($flattenedMetadata, 'publisher');
                    $doc['contributor_text'] = $this->extractMetadataText($flattenedMetadata, 'contributor');
                    $doc['index_entries'] = $this->extractMetadataValues($flattenedMetadata, 'index_entry');
                } else {
                    $doc['metadata'] = [];
                    $doc['author_text'] = '';
                    $doc['subject_text'] = '';
                    $doc['publisher_text'] = '';
                    $doc['contributor_text'] = '';
                    $doc['index_entries'] = [];
                }

                $ocrData = $this->loadOcrPages($doc['identifier']);
                $doc['ocr_pages'] = $ocrData['pages'];
                $doc['ocr_text'] = $ocrData['full_text'];

                $indexedDocuments[] = $doc;
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();

            $this->info('Indexing '.count($indexedDocuments).' books...');

            $chunks = array_chunk($indexedDocuments, 50);
            foreach ($chunks as $chunk) {
                $this->elasticsearch->bulkIndex($chunk);
            }

            $this->info('Successfully indexed '.count($indexedDocuments).' books!');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    /**
     * @param  array<int, array{key: string, value: string, label: string}>  $metadata
     * @return array<int, string>
     */
    private function extractMetadataValues(array $metadata, string $key): array
    {
        return array_values(array_map(
            fn (array $item): string => $item['value'],
            array_filter($metadata, fn (array $item): bool => $item['key'] === $key)
        ));
    }

    /**
     * @param  array<int, array{key: string, value: string, label: string}>  $metadata
     */
    private function extractMetadataText(array $metadata, string $key): string
    {
        return implode("\n", $this->extractMetadataValues($metadata, $key));
    }

    /**
     * @return array{pages: array<int, array{page: int, text: string}>, full_text: string}
     */
    private function loadOcrPages(string $identifier): array
    {
        $ocrFiles = glob(storage_path("app/public/ocr/{$identifier}/*.txt")) ?: [];
        sort($ocrFiles);

        $pages = [];
        $fullText = [];

        foreach ($ocrFiles as $file) {
            $text = file_get_contents($file);
            if (! is_string($text)) {
                continue;
            }

            $normalizedText = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
            if ($normalizedText === '') {
                continue;
            }

            preg_match('/_n0*(\d+)_ocr\.txt$/', basename($file), $matches);
            $page = (int) ($matches[1] ?? 1);

            $pages[] = [
                'page' => $page,
                'text' => $normalizedText,
            ];
            $fullText[] = $normalizedText;
        }

        return [
            'pages' => $pages,
            'full_text' => implode("\n\n", $fullText),
        ];
    }

    private function collectionCode(): string
    {
        return rtrim((string) config('viewer.collection_code'), '*:*');
    }

    private function apiRows(): int
    {
        return max(1, (int) config('viewer.api_rows', 100));
    }
}
