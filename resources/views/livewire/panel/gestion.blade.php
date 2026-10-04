@use('App\Livewire\Formato')
@use('Illuminate\Support\Js')
@php
  $celda = function (string $columna, array $fila) {
      $v = $fila[$columna] ?? null;
      return match ($columna) {
          'estado' => ['html' => true, 'clase' => $v === 'ACTIVO' ? 'confirmado' : 'inactivo', 'texto' => $v === 'ACTIVO' ? 'Activo' : 'Inactivo'],
          'rol' => ['html' => true, 'clase' => $v === 'ADMINISTRADOR' ? 'confirmado' : 'revision', 'texto' => $v === 'ADMINISTRADOR' ? 'Administrador' : 'Revisor'],
          'expedicion_registrada' => $v ? ['texto' => 'Registrada'] : ['alerta' => true, 'texto' => 'Falta'],
          'fecha_actualizacion', 'fecha_nacimiento' => ['texto' => Formato::fechaCorta($v)],
          'actualizado_en' => ['texto' => Formato::fechaHora($v)],
          default => ['texto' => $v ?? '—'],
      };
  };
  $editando = $formulario['clave'] ?? null;
@endphp
<section class="tarjeta">
  <div class="crud__cabecera">
    <div>
      <h2 class="tarjeta__titulo">{{ $d['titulo'] }}</h2>
      <p class="nota">{{ $d['nota'] }}</p>
    </div>
    <button type="button" class="boton boton--primario" wire:click="nuevo">+ Nuevo {{ $d['singular'] }}</button>
  </div>
  <form class="crud__busqueda" wire:submit="buscar">
    <input class="campo" type="search" placeholder="{{ $d['busqueda'] }}" aria-label="Buscar" wire:model="busqueda">
    <button type="submit" class="boton boton--secundario">Buscar</button>
  </form>
  @include('livewire.partials.mensaje', ['area' => 'crud', 'rol' => 'status'])
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr>@foreach ($d['columnas'] as [, $titulo])<th>{{ $titulo }}</th>@endforeach<th class="num">Acciones</th></tr></thead>
      <tbody>
        @forelse ($listado['filas'] as $fila)
          @php $clave = (string) $fila[$d['clave']]; @endphp
          <tr wire:key="fila-{{ $clave }}">
            @foreach ($d['columnas'] as [$columna])
              @php $c = $celda($columna, $fila); @endphp
              <td>
                @if (! empty($c['html']))
                  <span class="estado estado--{{ $c['clase'] }}">{{ $c['texto'] }}</span>
                @elseif (! empty($c['alerta']))
                  <span class="alerta">{{ $c['texto'] }}</span>
                @else
                  {{ $c['texto'] }}
                @endif
              </td>
            @endforeach
            <td class="num">
              <div class="crud__acciones">
                <button type="button" class="boton boton--linea boton--mini" wire:click="editar({{ Js::from($clave) }})">Editar</button>
                @if ($tipo === 'usuarios' && $clave === $propio)
                  <span class="sub">(usted)</span>
                @else
                  <button type="button" class="boton boton--peligro boton--mini" wire:click="eliminar({{ Js::from($clave) }})">Eliminar</button>
                @endif
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="{{ count($d['columnas']) + 1 }}" class="nota">No hay registros.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <div class="crud__pie">
    <span class="nota">{{ Formato::numero($listado['total']) }} registro(s)</span>
    <span class="crud__paginas">
      @if ($listado['paginas'] > 1)
        <button type="button" class="boton boton--linea boton--mini" wire:click="irAPagina({{ $listado['pagina'] - 1 }})" @disabled($listado['pagina'] <= 1)>‹ Anterior</button>
        <span class="nota"> Página {{ $listado['pagina'] }} de {{ $listado['paginas'] }} </span>
        <button type="button" class="boton boton--linea boton--mini" wire:click="irAPagina({{ $listado['pagina'] + 1 }})" @disabled($listado['pagina'] >= $listado['paginas'])>Siguiente ›</button>
      @endif
    </span>
  </div>

  @if ($formulario)
    <dialog class="modal modal--form" data-modal wire:ignore.self wire:key="formulario-{{ $editando ?? 'nuevo' }}" aria-labelledby="dlg-titulo">
      <form wire:submit="guardar" novalidate>
        <h2 class="modal__titulo" id="dlg-titulo">{{ $editando === null ? 'Nuevo' : 'Editar' }} {{ $d['singular'] }}</h2>
        <div class="form-registro__campos">
          @foreach ($d['campos'] as $c)
            @php
              $requerido = ! empty($c['requerido']) || (! empty($c['requeridoAlCrear']) && $editando === null);
              $soloLectura = ! empty($c['soloCrear']) && $editando !== null;
              $ayuda = $editando !== null ? ($c['ayudaEditar'] ?? null) : null;
            @endphp
            <div class="campo-adicional" wire:key="campo-{{ $c['id'] }}">
              <label class="campo__label" for="dlg-{{ $c['id'] }}">{{ $c['etiqueta'] }}{{ $requerido ? ' *' : '' }}</label>
              @if ($c['tipo'] === 'select')
                <select id="dlg-{{ $c['id'] }}" class="campo" @required($requerido) wire:model="valores.{{ $c['id'] }}"
                        @if (in_array("valores.{$c['id']}", $invalidos, true)) aria-invalid="true" @endif>
                  @if ($c['id'] === 'agencia')<option value="">Seleccione…</option>@endif
                  @foreach ($c['opciones'] as [$valor, $texto])
                    <option value="{{ $valor }}">{{ $texto }}</option>
                  @endforeach
                </select>
              @else
                <input id="dlg-{{ $c['id'] }}" type="{{ $c['tipo'] }}" @required($requerido) @readonly($soloLectura)
                       class="campo{{ $soloLectura ? ' campo--solo-lectura' : '' }}" wire:model="valores.{{ $c['id'] }}"
                       @if (! empty($c['validar'])) data-validar="{{ $c['validar'] }}" @endif
                       @if (! empty($c['max'])) maxlength="{{ $c['max'] }}" @endif
                       @if (! empty($c['autocomplete'])) autocomplete="{{ $c['autocomplete'] }}" @endif
                       @if (! $soloLectura && $loop->first) data-foco @endif
                       @if (in_array("valores.{$c['id']}", $invalidos, true)) aria-invalid="true" @endif>
              @endif
              @if ($ayuda)
                <small class="campo__ayuda">{{ $ayuda }}</small>
              @endif
            </div>
          @endforeach
        </div>
        @include('livewire.partials.mensaje', ['area' => 'formulario', 'objetivo' => 'guardar', 'espera' => 'Guardando…'])
        <div class="modal__acciones">
          <button type="button" class="boton boton--linea" wire:click="cerrarFormulario" data-cerrar-modal>Cancelar</button>
          <button type="submit" class="boton boton--primario" wire:loading.attr="disabled" wire:target="guardar">Guardar</button>
        </div>
      </form>
    </dialog>
  @endif

  <x-modal-confirmacion :confirmacion="$confirmacion" />
</section>
