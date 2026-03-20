<?php

namespace App\Http\Controllers;

use App\Services\CachedViewerApiService;
use Illuminate\View\View;

class BookController extends Controller
{
    public function __construct(
        private CachedViewerApiService $viewerApi,
    ) {}

    public function index(string $identifier, int $page): View
    {
        $data = $this->viewerApi->getBook($identifier);

        if ($data === null) {
            abort(404, 'Book not found');
        }

        $sequenceCount = count($data['iiif']['image']['items']);

        if ($page > $sequenceCount || $page < 1 || empty($sequenceCount)) {
          abort(404, 'Page not found');
        }

        $displayTitle = (string) ($data['title'] ?? '');
        $release_date = (string) ($data['release_date'] ?? '');

        $thumbnail = $data['thumbnail'] ?? null;

        $schema = [
            "@context" => "http://schema.org/",
            "@type" => "PublicationIssue",
            "name" => $displayTitle,
            "url" => url()->current(),
            "image" => $thumbnail,
            "datePublished" => $release_date,
            "inLanguage" => [
                [
                    "@type" => "Language",
                    "name" => "English"
                ]
            ],
            "publisher" => [
                "@type" => "Organization",
                "name" => config('app.publisher')
            ],
            "description" => config('app.description'),
            "about" => config('app.about'),
            "provider" => config('app.provider'),
        ];

        $result = array_merge($data, [
            'body_class' => 'book',
            'displayTitle' => $displayTitle,
            'identifier' => $identifier,
            'page' => $page,
            'thumbnail' => $thumbnail,
            'release_date' => $release_date,
            'schema' => $schema,
        ]);

        return view('book', $result);

    }
}
