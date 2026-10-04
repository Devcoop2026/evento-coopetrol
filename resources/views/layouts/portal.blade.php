<!doctype html>
<html lang="es">
<head>
  @include('layouts.partials.cabeza')
  <title>{{ $title ?? 'Evento Fin de Año Coopetrol' }}</title>
  <meta name="description" content="Simule el valor de ingreso e inscríbase al evento de fin de año Coopetrol pagando por PSE o en una agencia.">
  <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
  <link rel="apple-touch-icon" href="{{ asset('img/icon.svg') }}">
</head>
<body data-sw="{{ asset('sw.js') }}">
  <header class="barra">
    <div class="contenedor barra__int">
      <img src="{{ asset('img/logo-coopetrol.png') }}" alt="Coopetrol - Especializada en Ahorro y Crédito" class="barra__logo" width="207" height="70">
    </div>
  </header>

  {{ $slot }}

  <footer class="pie">
    <div class="contenedor">Coopetrol · Especializada en Ahorro y Crédito</div>
  </footer>
</body>
</html>
