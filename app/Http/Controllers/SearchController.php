<?php

namespace App\Http\Controllers;

use App\Services\ElasticsearchService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SearchController extends Controller
{
    public function __construct(
        private ElasticsearchService $elasticsearch
    ) {}

    public function index(Request $request): View
    {
        $query = $request->get('q', '');
        $page = max(1, (int) $request->get('page', 1));
        $perPage = 20;

        $filters = [
            'type' => $request->get('type'),
            'metadata' => [],
        ];

        $metadataParams = $request->get('metadata', []);
        if (is_array($metadataParams)) {
            foreach ($metadataParams as $key => $value) {
                $filters['metadata'][$key] = $value;
            }
        }

        $results = [];
        $pagination = null;
        $facets = null;

        if (! empty($query)) {
            $searchResults = $this->elasticsearch->search($query, $perPage, $page, $filters);
            $results = $searchResults['results'];
            $pagination = [
                'total' => $searchResults['total'],
                'page' => $searchResults['page'],
                'per_page' => $searchResults['per_page'],
                'total_pages' => $searchResults['total_pages'],
            ];
            $facets = $searchResults['facets'];
        }

        return view('search', [
            'displayTitle' => $query !== '' ? 'Search results for "'.$query.'": '.config('app.name') : 'Search: '.config('app.name'),
            'query' => $query,
            'results' => $results,
            'pagination' => $pagination,
            'facets' => $facets,
            'filters' => $filters,
        ]);
    }
}
