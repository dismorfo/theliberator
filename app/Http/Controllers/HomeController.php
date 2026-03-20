<?php

namespace App\Http\Controllers;

use App\Services\CachedViewerApiService;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __construct(
        private CachedViewerApiService $viewerApi,
    ) {}

    public function index(): View
    {
        $documents = $this->viewerApi->getBooks();

        $displayTitle = config('app.name');

        return view('home', [
            'displayTitle' => $displayTitle,
            'documents' => $documents
        ]);
    }
}
