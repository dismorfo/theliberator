<?php

namespace App\Http\Controllers;

use App\Http\Requests\OCRSearchRequest;
use App\Services\ElasticsearchOCRService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OCRSearchController extends Controller
{
    public function __construct(
        private ElasticsearchOCRService $elasticsearch
    ) {}

    public function search(OCRSearchRequest $request, string $identifier): JsonResponse
    {
        $validated = $request->validated();
        $query = $validated['q'];
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? 20);

        try {
            $response = $this->elasticsearch->search($identifier, $query, $perPage, $page);

            return $this->formatIIIFResponse($response);
        } catch (\Exception $e) {
            Log::error('OCR search failed.', [
                'identifier' => $identifier,
                'query' => $query,
                'page' => $page,
                'per_page' => $perPage,
                'exception' => $e,
            ]);

            return response()->json(['error' => 'OCR search failed.'], 500);
        }
    }

    protected function formatIIIFResponse(array $esResponse): JsonResponse
    {
        $page = max(1, (int) ($esResponse['page'] ?? 1));
        $perPage = max(1, (int) ($esResponse['per_page'] ?? 20));
        $totalPages = max(1, (int) ($esResponse['total_pages'] ?? 1));

        $results = [
            '@context' => 'http://iiif.io/api/search/1/context.json',
            '@id' => request()->fullUrl(),
            '@type' => 'sc:AnnotationList',
            'resources' => [],
            'hits' => [],
            'within' => [
                '@type' => 'sc:Layer',
                'total' => $esResponse['total'] ?? 0,
                'first' => request()->fullUrlWithQuery(['page' => 1]),
                'last' => request()->fullUrlWithQuery(['page' => $totalPages]),
            ],
            'startIndex' => ($page - 1) * $perPage,
        ];

        $hits = $esResponse['results'] ?? [];
        $seenHits = [];

        foreach ($hits as $hit) {
            if (! $this->isRenderableHit($hit)) {
                continue;
            }

            $highlight = $hit['_highlight']['text'][0] ?? null;
            $dedupeKey = implode('|', [
                $hit['canvas'],
                $hit['coords'],
                $hit['is_shingle'] ? 'shingle' : 'line',
                is_string($highlight) ? $highlight : $hit['text'],
            ]);

            if (isset($seenHits[$dedupeKey])) {
                continue;
            }

            $seenHits[$dedupeKey] = true;

            $id = isset($hit['_id']) ? 'search-anno-'.$hit['_id'] : uniqid('search-anno-', true);

            $results['resources'][] = [
                '@id' => $id,
                '@type' => 'oa:Annotation',
                'motivation' => 'sc:painting',
                'resource' => [
                    '@type' => 'cnt:ContentAsText',
                    'chars' => $hit['text'],
                ],
                'on' => $hit['canvas'].'#xywh='.$hit['coords'],
            ];

            $before = '';
            $after = '';
            $match = $hit['text'];
            if (is_string($highlight)) {
                $hl = $highlight;
                if (preg_match('/^(.*)\[\[MATCH\]\](.*)\[\[\/MATCH\]\](.*)$/us', $hl, $m)) {
                    $before = $m[1];
                    $match = $m[2];
                    $after = $m[3];
                }
            }

            $results['hits'][] = [
                '@type' => 'search:Hit',
                'annotations' => [$id],
                'before' => trim(strip_tags(str_replace(['[[MATCH]]', '[[/MATCH]]'], '', $before))),
                'after' => trim(strip_tags(str_replace(['[[MATCH]]', '[[/MATCH]]'], '', $after))),
                'match' => trim($match),
            ];
        }

        return response()->json($results);
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    protected function isRenderableHit(array $hit): bool
    {
        if (! isset($hit['text'], $hit['canvas'], $hit['coords'])) {
            return false;
        }

        $text = trim((string) $hit['text']);
        if ($text === '') {
            return false;
        }

        return preg_match('/^\d+,\d+,\d+,\d+$/', (string) $hit['coords']) === 1;
    }
}
