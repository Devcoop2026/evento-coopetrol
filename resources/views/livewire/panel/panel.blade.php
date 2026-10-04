@use('App\Livewire\Panel\Panel')
<div>
  <header class="barra">
    <div class="contenedor contenedor--ancho barra__int">
      <img src="{{ asset('img/logo-coopetrol.png') }}" alt="Coopetrol" class="barra__logo" width="207" height="70">
      <span class="barra__titulo">Panel de inscripciones</span>
      <span class="espaciador"></span>
      <span class="barra__usuario">{{ $usuario->nombre }} · {{ $usuario->rol->etiqueta() }}</span>
      <button type="button" class="boton boton--linea" wire:click="salir">Salir</button>
    </div>
  </header>

  <main class="contenedor contenedor--ancho principal principal--admin">
    <div>
      @foreach ($avisos as $aviso)
        <p class="aviso aviso--rechazado">{{ $aviso }}</p>
      @endforeach
    </div>
    <livewire:panel.resumen-cupos wire:key="kpis" />

    <nav class="pestanas-admin" role="tablist" aria-label="Secciones del panel">
      @foreach (Panel::VISTAS as $clave => [$titulo, $soloAdmin])
        @if (! $soloAdmin || $administrador)
          <button type="button" role="tab" aria-selected="{{ $vista === $clave ? 'true' : 'false' }}" wire:click="mostrar('{{ $clave }}')">{{ $titulo }}</button>
        @endif
      @endforeach
    </nav>

    @switch($vista)
      @case('asociados')
      @case('coopetrolitos')
        <livewire:panel.gestion :tipo="$vista" :key="'gestion-'.$vista" />
        @break
      @case('usuarios')
        <livewire:panel.gestion tipo="usuarios" :key="'gestion-usuarios'" />
        <livewire:panel.auditoria :key="'auditoria'" />
        @break
      @case('cupos')
        @if ($administrador)
          <details class="tarjeta" id="bases" open>
            <summary class="resumen-titulo">Bases de asociados y Coopetrolitos</summary>
            <p class="nota">Los datos se guardan directamente en la base de datos del servidor; no se almacenan archivos.
              Cada carga <strong>reemplaza la base completa</strong> y queda registrada con su usuario y fecha.</p>
            <div class="bases">
              <livewire:panel.base tipo="asociados" :key="'base-asociados'" />
              <livewire:panel.base tipo="coopetrolitos" :key="'base-coopetrolitos'" />
            </div>
          </details>
        @endif
        <livewire:panel.cupos :key="'cupos'" />
        @break
      @default
        <livewire:panel.inscripciones :key="'inscripciones'" />
    @endswitch
  </main>
</div>
