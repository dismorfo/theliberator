@extends('layouts.app')

@section('title', $displayTitle)
@section('body_class', 'ispage pages page')

@section('content')
    <x-page-shell>
                <div class="items">
                    <div class="item itemwide intro">
                        @if ($query && $pagination && $pagination['total'] > 0)
                            <p class="search-results-summary">
                                Found {{ $pagination['total'] }} results for "{{ $query }}"
                            </p>
                        @endif
                        <div class="search-results-list">
                            @if ($query && $pagination && $pagination['total'] > 0)
                                @foreach ($results as $item)
                                    <article class="search-result-item">
                                        <a class="search-result-link" href="{{ route('book.index', ['identifier' => $item['identifier'], 'page' => $item['match_page'] ?? 1, 'q' => $item['matched_phrase'] ?? $query]) }}">
                                            <div class="search-result-thumb" role="presentation">
                                                <img src="/images/{{ $item['identifier'] }}.jpg" width="328" height="401" class="imgload" loading="lazy" alt="{{ $item['title'] }}">
                                            </div>
                                            <div class="search-result-body">
                                                <div class="search-result-kicker">
                                                    <span class="search-result-source">{{ $item['match_source_label'] ?? 'Book match' }}</span>
                                                    <span class="search-result-identifier">{{ $item['identifier'] }}</span>
                                                </div>
                                                <h2 class="md_title search-result-title">
                                                    {!! $item['_highlight']['title'][0] ?? $item['title'] !!}
                                                </h2>
                                                <p class="md_date search-result-date">
                                                    {!! $item['_highlight']['date_string'][0] ?? $item['date_string'] !!}
                                                </p>
                                                @if (isset($item['_highlight']['ocr_pages']))
                                                    <div class="md_snippet search-result-snippet">
                                                        <div class="md_page-link search-result-page">Match on page {{ $item['match_page'] ?? 1 }}</div>
                                                        @foreach (array_slice($item['_highlight']['ocr_pages'], 0, 1) as $snippet)
                                                            ...{!! $snippet !!}...
                                                        @endforeach
                                                    </div>
                                                @elseif (isset($item['_highlight']['index_entries']))
                                                    <div class="md_snippet search-result-snippet">
                                                        @foreach (array_slice($item['_highlight']['index_entries'], 0, 1) as $snippet)
                                                            ...{!! $snippet !!}...
                                                        @endforeach
                                                    </div>
                                                @elseif (isset($item['_highlight']['author_text']) || isset($item['_highlight']['subject_text']))
                                                    <div class="md_snippet search-result-snippet">
                                                        @foreach (array_slice(array_merge($item['_highlight']['author_text'] ?? [], $item['_highlight']['subject_text'] ?? []), 0, 1) as $snippet)
                                                            ...{!! $snippet !!}...
                                                        @endforeach
                                                    </div>
                                                @endif
                                            </div>
                                        </a>
                                    </article>
                                @endforeach
                            @elseif ($query)
                                <div class="item itemwide no-results">
                                    <h3>No results found for "{{ $query }}"</h3>
                                    <p>Try different keywords or check for typos.</p>
                                </div>
                            @endif
                        </div>

                        @if ($query && $pagination && $pagination['total_pages'] > 1)
                            <div class="pagination-wrapper">
                                <div class="pagination-nav">
                                    @if ($pagination['page'] > 1)
                                        <a href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] - 1]) }}">Previous</a>
                                    @endif
                                    <span>Page {{ $pagination['page'] }} of {{ $pagination['total_pages'] }}</span>
                                    @if ($pagination['page'] < $pagination['total_pages'])
                                        <a href="{{ request()->fullUrlWithQuery(['page' => $pagination['page'] + 1]) }}">Next</a>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
    </x-page-shell>
@endsection
