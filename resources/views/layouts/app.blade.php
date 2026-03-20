<!doctype html>
<html lang="en">
  <head>
    @include('partials.head', ['displayTitle' => $displayTitle ?? config('app.name')])
    @stack('meta')
    @stack('styles')
  </head>
  <body class="@yield('body_class')">
    @yield('content')
    @stack('scripts')
  </body>
</html>
