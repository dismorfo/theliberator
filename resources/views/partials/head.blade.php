<title>{{ trim($__env->yieldContent('title', $displayTitle ?? config('app.name'))) }}: NYU Libraries</title>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="description" content="{{ config('app.description') }}">
<meta property="og:site_name" content="{{ config('app.name') }}">
<meta property="og:title" content="{{ trim($__env->yieldContent('title', $displayTitle ?? config('app.name'))) }}">
{{-- <meta property="og:image" content="http://dlib.nyu.edu/files/books/lib000006/lib000006_thumb.jpg"> --}}
<meta property="og:url" content="{{ url()->current() }}">
<script src="https://ajax.googleapis.com/ajax/libs/webfont/1.5.10/webfont.js"></script>
<script>
    WebFont.load({
        google: {
            families: ['Open Sans:300,400,600']
        },
        timeout: 3000
    });
</script>

@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
    @vite(['resources/sass/style.scss', 'resources/js/app.js'])
@endif
