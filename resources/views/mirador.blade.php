@extends('layouts.mirador')

@push('styles')
    @if($cssFile)
        <link rel="stylesheet" href="/storage/mirador/{{ $cssFile }}">
    @endif
@endpush

@push('scripts')
    @if($jsFile)
        <script src="/storage/mirador/{{ $jsFile }}"></script>
    @endif
@endpush

@section('title', $displayTitle)
@section('body_class', 'mirador-page')

@section('content')
    <div
      id="mirador-app"
      dir="{{ $direction }}"
      data-identifier="{{ $identifier }}"
      data-type="{{ $type }}"
      data-language="{{ $language }}"
      data-sequence="{{ $sequence }}"
      data-manifest="{{ $manifest }}"
      data-search-query="{{ $searchQuery }}"
    ></div>
@endsection
