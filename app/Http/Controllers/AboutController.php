<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class AboutController extends Controller
{
    public function index(): View
    {
        $displayTitle = 'About: ' . config('app.name');

        return view('about', [
            'displayTitle' => $displayTitle
        ]);

    }
}
