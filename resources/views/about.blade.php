@extends('layouts.app')

@section('title', $displayTitle)
@section('body_class', 'ispage pages page')

@section('content')
    <x-page-shell>
            <h1 class="page-title">About</h1>
            <div class="content">
                <p>Started in 1918 by Max Eastman and his sister Crystal Eastman to continue the work of The Masses and provide a platform for publishing John Reed's reporting on the Bolshevik Revolution, The Liberator continued the political and labor writing of its predecessor, as well as its emphasis on art, poetry, and fiction.</p>
                <p>In fall 1922 the Communist Party took over publication of the magazine, and two years later it merged with other Party periodicals, becoming the Workers' Monthly. This digital edition reproduces the holdings of the Tamiment Library &amp; Robert F. Wagner Labor Archives at New York University. To contact the library, please email <a href="mailto:special.collections@nyu.edu">special.collections@nyu.edu</a>.</p>
            </div>
    </x-page-shell>
@endsection
