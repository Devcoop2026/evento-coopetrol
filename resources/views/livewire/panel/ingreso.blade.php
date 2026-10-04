<div>
  <header class="barra">
    <div class="contenedor contenedor--ancho barra__int">
      <img src="{{ asset('img/logo-coopetrol.png') }}" alt="Coopetrol" class="barra__logo" width="207" height="70">
      <span class="barra__titulo">Panel de inscripciones</span>
    </div>
  </header>

  <main class="contenedor contenedor--ancho principal principal--admin">
    <form class="tarjeta login" wire:submit="ingresar" novalidate>
      <h1 class="tarjeta__titulo">Ingreso administradores</h1>
      <label for="l-usuario" class="campo__label">Usuario</label>
      <input id="l-usuario" class="campo" data-validar="usuario" maxlength="40" autocomplete="username" required autofocus wire:model="usuario">
      <label for="l-clave" class="campo__label espacio-arriba">Clave</label>
      <input id="l-clave" class="campo" type="password" autocomplete="current-password" required wire:model="clave">
      <button type="submit" class="boton boton--primario boton--ancho espacio-arriba" wire:loading.attr="disabled" wire:target="ingresar">Ingresar</button>
      @include('livewire.partials.mensaje', ['area' => 'login', 'objetivo' => 'ingresar', 'espera' => 'Ingresando…'])
    </form>
  </main>
</div>
