<header class="page-header">
    <div id="skipnav"><a href="#main-content">Skip navigation</a></div>
    <div class="inner-header items">
        <div class="site-title item itemwide">
          <a href="{{ route('home.index') }}">{{ config('app.name') }}</a>
        </div>
        <div class="toplogo-nyu item ">
            <a href="https://library.nyu.edu/" class="nyullogo" target="_blank" rel="noopener noreferrer">NYU Libraries</a>
        </div>
    </div>
    <div class="navhold">
        <div class="inner">
            <nav class="nav-links">
                <ul>
                    <li><a href="{{ route('home.index') }}">Home</a></li>
                    <li><a href="{{ route('about.index') }}">About</a></li>
                    <li><a href="{{ route('collectionindex.index') }}">Index of Topics</a></li>
                </ul>
            </nav>
            <div class="header-search">
                <form action="{{ route('search.index') }}" method="GET">
                    <input type="text" name="q" value="{{ request('q', '') }}" placeholder="Search..." class="header-search-input">
                    <button type="submit" class="header-search-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="8"></circle>
                            <path d="m21 21-4.35-4.35"></path>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</header>
