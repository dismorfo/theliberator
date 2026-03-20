<?php

return [
    'endpoint' => env('VIEWER_ENDPOINT', 'https://sites.dlib.nyu.edu/viewer'),
    'cache_store' => env('VIEWER_CACHE_STORE', 'file'),
    'collection_code' => env('VIEWER_COLLECTION_CODE', 'theliberator'),
    'api_rows' => (int) env('VIEWER_API_ROWS', 100),
];
