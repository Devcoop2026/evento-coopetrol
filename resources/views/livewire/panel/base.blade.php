@use('App\Livewire\Formato')
<article class="base">
  <header class="base__cabecera">
    <div>
      <h3 class="subtitulo">{{ $tipo === 'asociados' ? 'Asociados' : 'Coopetrolitos' }}</h3>
      <p class="nota">{{ $tipo === 'asociados'
        ? 'Columnas: Cedula, Nombre, Agencia, Asociado, Ultima actualizacion de datos, Fecha expedicion, Fecha nacimiento (opcional).'
        : 'Columnas: Documento, Nombre, Cedula asociado, Fecha nacimiento (opcional). Cargue primero la base de asociados.' }}</p>
    </div>
    <a class="boton boton--linea boton--mini" href="{{ route('panel.plantilla', $tipo) }}">Descargar plantilla</a>
  </header>
  <p class="base__estado">{{ Formato::numero($estado['total']) }} registros{{ $estado['ultimaCarga']
    ? ' · última carga: '.Formato::fechaHora($estado['ultimaCarga']['cargada_en']).' por '.$estado['ultimaCarga']['usuario'].' ('.($estado['ultimaCarga']['archivo'] ?: 'sin nombre').')'
    : ' · sin cargas desde el panel' }}</p>
  <div class="zona-archivo">
    <input class="solo-lector" type="file" id="base-{{ $tipo }}" wire:model="archivo"
           accept=".xlsx,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv">
    <label for="base-{{ $tipo }}" class="zona-archivo__vacia">
      <svg class="zona-archivo__icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
      <span class="zona-archivo__texto"><strong>Arrastre aquí el Excel o CSV</strong> o <span class="zona-archivo__enlace">selecciónelo</span></span>
      <span class="zona-archivo__ayuda">.xlsx o .csv · máximo 20 MB · se valida antes de guardar</span>
    </label>
  </div>
  @if ($resumen)
    <div class="base__vista">
      <div class="ficha ficha--simple">
        @foreach ($datos as [$k, $v])
          <div><span class="ficha__label">{{ $k }}</span><strong>{{ $v }}</strong></div>
        @endforeach
      </div>
      @foreach ($resumen['advertencias'] as $advertencia)
        <p class="aviso aviso--pendiente">{{ $advertencia }}</p>
      @endforeach
      @if ($resumen['errores'])
        <details class="base__errores">
          <summary>Ver filas omitidas ({{ $resumen['omitidas'] }}{{ $resumen['omitidas'] > count($resumen['errores']) ? ', se muestran '.count($resumen['errores']) : '' }})</summary>
          <ul>@foreach ($resumen['errores'] as $error)<li>{{ $error }}</li>@endforeach</ul>
        </details>
      @endif
      @if (! empty($resumen['porAgencia']))
        <details class="base__errores">
          <summary>Ver asociados por agencia</summary>
          <ul>@foreach ($resumen['porAgencia'] as [$agencia, $n])<li>{{ $agencia }}: {{ Formato::numero($n) }}</li>@endforeach</ul>
        </details>
      @endif
      <div class="acciones acciones--fila">
        <button type="button" class="boton boton--primario" wire:click="pedirReemplazo" wire:loading.attr="disabled" wire:target="confirmarAccion">Reemplazar base de {{ $etiqueta }}</button>
        <button type="button" class="boton boton--linea" wire:click="descartar">Descartar</button>
      </div>
    </div>
  @endif
  @include('livewire.partials.mensaje', ['area' => 'base', 'rol' => 'status', 'objetivo' => 'archivo,confirmarAccion', 'espera' => 'Procesando archivo…'])
  <x-modal-confirmacion :confirmacion="$confirmacion" />
</article>
