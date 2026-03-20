<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class ViewerApiClient
{
    public function getObjects(string $collectionCode, int $rows): array
    {
        $response = $this->httpClient()->get($this->endpoint().'/api/v1/objects', [
            'collection' => $collectionCode,
            'rows' => $rows,
        ]);

        if (! $response->successful()) {
            return [];
        }

        $data = $response->json();

        return is_array($data['response']['docs'] ?? null) ? $data['response']['docs'] : [];
    }

    public function getBook(string $identifier): ?array
    {
        $response = $this->httpClient()->get($this->endpoint().'/api/v1/books/'.$identifier);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) && $data !== [] ? $data : null;
    }

    public function getJson(string $url): ?array
    {
        $response = $this->httpClient()->get($url);

        if (! $response->successful()) {
            return null;
        }

        $data = $response->json();

        return is_array($data) ? $data : null;
    }

    public function getPresentationManifest(string $identifier, string $type = 'books'): ?array
    {
        return $this->getJson(
            sprintf('%s/api/presentation/%s/%s/manifest.json', $this->endpoint(), $type, $identifier)
        );
    }

    private function endpoint(): string
    {
        return rtrim((string) config('viewer.endpoint'), '/');
    }

    private function httpClient(): PendingRequest
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 200, throw: false);
    }
}
