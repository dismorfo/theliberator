<?php

use App\Http\Controllers\AboutController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookViewerController;
use App\Http\Controllers\CollectionIndexController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home.index');

Route::get('/search', [SearchController::class, 'index'])->name('search.index');

Route::get('/about', [AboutController::class, 'index'])->name('about.index');

Route::get('/collectionindex', [CollectionIndexController::class, 'index'])->name('collectionindex.index');

Route::redirect('/book/{identifier}', '/book/{identifier}/1', 301);

Route::get('/book/{identifier}/{page}', [BookController::class, 'index'])->name('book.index');

Route::redirect('/mirador/{identifier}', '/mirador/{identifier}/1', 301);

Route::get('/mirador/{identifier}/{page}', [BookViewerController::class, 'show'])->name('mirador.show');

Route::get('/manifest/presentation/{identifier}.json', [BookViewerController::class, 'manifest'])->name('iif.presentation.manifest');
