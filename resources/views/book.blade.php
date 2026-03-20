@extends('layouts.app')

@push('meta')
    <meta property="og:type" content="books.book">
    <meta property="og:image" content="{{ $thumbnail }}">
    <meta property="books:release_date" content="{{ $release_date }}">
    <script type="application/ld+json">
        @json($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
    </script>
@endpush

@section('title', $displayTitle)
@section('body_class', 'ispage books page')

@section('content')
    <x-page-shell>
            <h1 class="page-title">{{ $displayTitle }}</h1>
            <iframe
                class="viewer widget"
                data-sequence="{{ $page }}"
                data-identifier="{{ $identifier }}"
                title="{{ $displayTitle }}"
                src="{{ route('mirador.show', ['identifier' => $identifier, 'page' => $page, 'q' => request('q')]) }}"
                allowfullscreen
                mozallowfullscreen
                webkitallowfullscreen
            ></iframe>
    </x-page-shell>
@endsection
