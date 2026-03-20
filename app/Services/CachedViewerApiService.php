<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class CachedViewerApiService
{
    public function __construct(
        private ViewerApiClient $viewerApiClient,
        private PublicationIssueTitleParser $titleParser,
    ) {}

    public function getBooks(): array
    {
        $cacheKey = $this->cacheKey('viewer.books.index');

        $documents = Cache::store($this->cacheStore())->remember($cacheKey, now()->addWeek(), function (): array {
            return $this->viewerApiClient->getObjects($this->collectionCode(), 500);
        });

        return collect($documents)
            ->filter(fn (mixed $doc): bool => is_array($doc))
            ->map(fn (array $doc): array => $this->normalizeBookIndexDocument($doc))
            ->sortBy(fn (array $doc): int => $this->sortableBookDate($doc))
            ->values()
            ->all();
    }

    public function getBook(string $identifier): ?array
    {
        $cacheKey = $this->cacheKey("viewer.book.{$identifier}");

        return Cache::store($this->cacheStore())->remember($cacheKey, now()->addWeek(), function () use ($identifier): ?array {
            $data = $this->viewerApiClient->getBook($identifier);

            return $data === null ? null : $this->normalizeBookDetail($data);
        });
    }

    private function cacheStore(): string
    {
        return (string) config('viewer.cache_store', 'file');
    }

    private function cacheKey(string $key): string
    {
        return sprintf('viewer-api:%s:v1', $key);
    }

    private function collectionCode(): string
    {
        return rtrim((string) config('viewer.collection_code'), '*:*');
    }

    public function cleanCache(): bool
    {
        return Cache::store($this->cacheStore())->flush();
    }

    private function normalizeBookIndexDocument(array $doc): array
    {
        $metadata = $this->titleParser->parse((string) ($doc['title'] ?? ''));
        $doc['title'] = $metadata->displayTitle;
        $doc['date_string'] = $metadata->dateString;

        return $doc;
    }

    private function normalizeBookDetail(array $data): array
    {
        $metadata = $this->titleParser->parse((string) ($data['title'] ?? ''));
        $data['title'] = $metadata->displayTitle;
        $data['date_string'] = $metadata->dateString;
        $data['release_date'] = $metadata->releaseDate;
        $data['thumbnail'] = is_string($data['thumbnail'] ?? null) ? $data['thumbnail'] : null;

        $iiif = $data['iiif'] ?? null;
        $image = is_array($iiif) ? ($iiif['image'] ?? null) : null;
        $items = is_array($image) ? ($image['items'] ?? null) : null;

        if (! is_array($items)) {
            $items = [];
        }

        $data['iiif'] = [
            'image' => [
                'items' => $items,
            ],
        ];

        return $data;
    }

    private function sortableBookDate(array $doc): int
    {
        $dateString = (string) ($doc['date_string'] ?? '');

        try {
            return Carbon::createFromFormat('F Y', $dateString)->startOfMonth()->timestamp;
        } catch (\Throwable) {
            return PHP_INT_MIN;
        }
    }
}
