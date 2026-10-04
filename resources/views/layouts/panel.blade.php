<!doctype html>
<html lang="es">
<head>
  @include('layouts.partials.cabeza')
  <title>{{ $title ?? 'Panel Evento Coopetrol' }}</title>
  <meta name="robots" content="noindex, nofollow">
</head>
<body class="admin">
  {{ $slot }}
</body>
</html>
