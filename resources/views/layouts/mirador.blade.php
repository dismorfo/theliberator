<!doctype html>
<html lang="en">
<head>
  @stack('meta')
  <title>@yield('title')</title>
  @stack('styles')
</head>
  <body class="@yield('body_class')">
    @yield('content')
    @stack('scripts')
  </body>
</html>
