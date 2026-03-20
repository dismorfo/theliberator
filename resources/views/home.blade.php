@extends('layouts.app')

@section('title', $displayTitle)
@section('body_class', 'ishome isnode page home')

@section('content')
    <x-page-shell>
            <div class="items">
                <div class="intro item itemwide">
                    <p>Started in 1918 by Max Eastman and his sister Crystal Eastman to continue the work of The Masses and provide a platform for publishing John Reed's reporting on the Bolshevik Revolution, The Liberator continued the political and labor writing of its predecessor, as well as its emphasis on art, poetry, and fiction.
                        <a href=" {{ route('about.index') }}" class="readmore" aria-label="Read more about The Liberator">READ&nbsp;MORE…</a>
                    </p>
                </div>
                <div class="item itemspecial">
                    <div class="newsitem">
                        <div class="meta">
                            <div>
                                <span class="md_label">Title:</span>
                                <h1 class="md_title">The Liberator</h1>
                            </div>
                            <div>
                                <span class="md_label">Publisher:</span> <span>Liberator Publishing Co., New York</span>
                            </div>
                            <div class="md_subjects">
                                <span class="md_label">Subject:</span> Socialism -- Periodicals, Socialism -- United States -- Periodicals, Communism -- Periodicals, Communism -- United States -- Periodicals
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="items all-thumbs">
                @foreach ($documents as $item)
                    <article class="item">
                        <div class="card">
                            <a href="/{{ $item['type'] }}/{{ $item['identifier'] }}/">
                                <div class="thumbs" role="presentation">
                                    <img src="/images/{{ $item['identifier'] }}.jpg" width="328" height="401" class="imgload" loading="lazy" alt="">
                                </div>
                                <div class="meta">
                                    <h1 class="md_title" aria-label="{{ $item['title'] }}">{{ $item['date_string'] }}</h1>
                                </div>
                            </a>
                        </div>
                    </article>
                @endforeach
                <article class="item empty-article" aria-hidden="true">
                    <div class="card">&nbsp;</div>
                </article>
                <article class="item empty-article" aria-hidden="true">
                    <div class="card">&nbsp;</div>
                </article>
                <article class="item empty-article" aria-hidden="true">
                    <div class="card">&nbsp;</div>
                </article>
            </div>
    </x-page-shell>
@endsection
