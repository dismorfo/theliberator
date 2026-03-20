<?php

use App\Http\Controllers\OCRSearchController;
use Illuminate\Support\Facades\Route;

Route::middleware('throttle:ocr-search')->group(function () {
    Route::get('/search/{identifier}', [OCRSearchController::class, 'search'])->name('ocr.search.index');
});
