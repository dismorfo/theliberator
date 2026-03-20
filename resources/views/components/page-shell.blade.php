<div class="contain-header">
    @include('partials.header')
</div>
<div class="contain-main">
    <main id="main-content">
        {{ $slot }}
    </main>
</div>
<div class="contain-footer">
    @include('partials.footer')
</div>
