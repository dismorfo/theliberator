<?php

namespace App\Http\Controllers;

use App\Services\ViewerApiClient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use RuntimeException;

class BookViewerController extends Controller
{
    public function __construct(
        private ViewerApiClient $viewerApiClient,
    ) {}

    public function show(Request $request, string $identifier, int $page): View|JsonResponse
    {

        try {

            $basePath = storage_path("app/public/mirador");

            $cssFiles = glob("$basePath/mirador.*.css");
            if ($cssFiles) {
                usort($cssFiles, function (string $a, string $b): int {
                    return (filemtime($b) ?: 0) - (filemtime($a) ?: 0);
                });
            }

            $jsFiles = glob("$basePath/mirador.*.js");
            if ($jsFiles) {
                usort($jsFiles, function (string $a, string $b): int {
                    return (filemtime($b) ?: 0) - (filemtime($a) ?: 0);
                });
            }

            $jsFileName = $jsFiles ? basename($jsFiles[0]) : null;

            $cssFileName = $cssFiles ? basename($cssFiles[0]) : null;

            return view('mirador', [
                'displayTitle' => $identifier,
                'appid' => 'mirador-app',
                'identifier' => $identifier,
                'type' => 'books',
                'direction' => 'ltr',
                'language' => 'en',
                'sequence' => $page,
                'manifest' => route('iif.presentation.manifest', ['identifier' => $identifier]),
                'searchQuery' => (string) $request->query('q', ''),
                'cssFile' => $cssFileName,
                'jsFile' => $jsFileName,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to render viewer.', ['exception' => $e]);

            return response()->json(['error' => 'Failed to load viewer.'], 500);
        }
    }

    public function manifest(string $identifier): JsonResponse
    {
        $type = 'books'; // @TODO: Viewer API should support request of presentation manifest without having to pass the type.

        try {

            $manifest = $this->viewerApiClient->getPresentationManifest($identifier, $type);

            if ($manifest === null) {
                throw new RuntimeException('Viewer manifest response was not valid JSON.');
            }

            // PartOf does not look good in Mirador, so remove it.
            unset($manifest['partOf']);

            // Our summary is not useful, so remove it.
            unset($manifest['summary']);

            // Inject the search service
            $manifest['service'] = [
                [
                    '@context' => 'http://iiif.io/api/search/1/context.json',
                    '@id' => '/api/search',
                    'id' => route('ocr.search.index', ['identifier' => $identifier]),
                    'type' => 'SearchService1',
                    'profile' => 'http://iiif.io/api/search/1/search',
                    'label' => 'OCR Search'
                ]
            ];

            return response()->json($manifest);
        } catch (\Exception $e) {
            Log::error('Failed to proxy manifest.', ['exception' => $e]);

            return response()->json(['error' => 'Failed to proxy manifest.'], 500);
        }
    }


}
