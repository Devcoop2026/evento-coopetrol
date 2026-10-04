@use('App\Livewire\Formato')
@use('App\Livewire\Panel\Inscripciones')
@php
  $chip = fn ($estado) => Inscripciones::ESTADOS[$estado] ?? [$estado, 'inactivo'];
@endphp
<section class="tarjeta" wire:poll.30s.visible>
  <form class="filtros" wire:submit="filtrar">
    <div>
      <label for="f-estado" class="campo__label">Estado</label>
      <select id="f-estado" class="campo" wire:model.live="estado">
        <option value="">Todos</option>
        <option value="EN_REVISION">En revisión</option>
        <option value="PREINSCRITO">Preinscrito</option>
        <option value="RECHAZADO">Rechazado</option>
        <option value="CONFIRMADO">Confirmado</option>
        <option value="CANCELADO">Cancelado</option>
        <option value="ANULADO">Anulado</option>
      </select>
    </div>
    <div>
      <label for="f-agencia" class="campo__label">Agencia</label>
      <select id="f-agencia" class="campo" wire:model.live="agencia">
        <option value="">Todas</option>
        @foreach ($agencias as $a)
          <option value="{{ $a }}">{{ $a }}</option>
        @endforeach
      </select>
    </div>
    <div class="filtros__busqueda">
      <label for="f-q" class="campo__label">Buscar</label>
      <input id="f-q" class="campo" placeholder="Referencia, documento o nombre" wire:model="busqueda">
    </div>
    <button type="submit" class="boton boton--secundario">Filtrar</button>
    <a class="boton boton--linea" href="{{ route('panel.exportar') }}">Exportar a Excel</a>
  </form>
  <div class="tabla-scroll">
    <table class="tabla tabla--clic">
      <thead><tr><th>Referencia</th><th>Titular</th><th>Evento</th><th class="num">Personas</th><th class="num">Total</th><th>Estado</th><th>Actualizada</th></tr></thead>
      <tbody>
        @foreach ($filas as $f)
          @php
            [$texto, $clase] = $chip($f['estado']);
          @endphp
          <tr tabindex="0" wire:key="ins-{{ $f['id'] }}" wire:click="abrir({{ $f['id'] }})" wire:keydown.enter="abrir({{ $f['id'] }})"
              @class(['fila--resaltada' => $resaltada === $f['referencia']])>
            <td><span class="referencia">{{ $f['referencia'] }}</span></td>
            <td>{{ $f['nombre_titular'] }}<span class="sub">Doc. {{ $f['documento_titular'] }}</span></td>
            <td>{{ $f['agencia'] }}</td>
            <td class="num">{{ $f['personas'] }}</td>
            <td class="num">{{ Formato::pesos($f['total']) }}</td>
            <td><span class="estado estado--{{ $clase }}">{{ $texto }}</span>
              @if ($f['alerta'])
                <span class="sub alerta">⚠ Valor no coincide</span>
              @endif
            </td>
            <td>{{ Formato::fechaHora($f['actualizada_en']) }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
  <p class="nota">{{ $filas ? count($filas).' inscripción(es)'.(count($filas) === 1000 ? ' (se muestran las 1.000 más recientes)' : '').'.' : 'No hay inscripciones con estos filtros.' }}</p>

  @if ($detalle)
    @php
      $d = $detalle;
      [$textoEstado, $claseEstado] = $chip($d['estado']);
      $alerta = $d['soportes'][0]['alerta'] ?? null;
      $dato = fn ($etiqueta, $valor) => ['etiqueta' => $etiqueta, 'valor' => $valor ?? '—'];
      $ficha = [
          $dato('Titular', $d['nombre_titular']), $dato('Documento', $d['documento_titular']), $dato('Evento (agencia)', $d['agencia']),
          $dato('Agencia del asociado', $d['agencia_asociado']), $dato('Total', Formato::pesos($d['total'])), $dato('Creada', Formato::fechaHora($d['creada_en'])),
          $dato('Revisado por', $d['revisado_por'] ? $d['revisado_por'].' · '.Formato::fechaHora($d['revisado_en']) : null),
          $dato('Motivo', $d['motivo']),
          $dato('Autorización de datos', $d['autorizacion_en'] ? 'v'.$d['autorizacion_version'].' · '.Formato::fechaHora($d['autorizacion_en']) : null),
          $dato('Uso de imagen', $d['autorizacion_imagen'] ? 'Autorizado' : 'No autorizado'),
      ];
    @endphp
    <dialog class="detalle" data-modal data-cerrar-fondo wire:ignore.self wire:key="detalle-{{ $d['id'] }}" aria-labelledby="d-referencia">
      <div class="detalle__cabecera">
        <div>
          <span class="ficha__label">Referencia</span>
          <h2 class="referencia" id="d-referencia">{{ $d['referencia'] }}</h2>
        </div>
        <span class="estado estado--{{ $claseEstado }}">{{ $textoEstado }}</span>
        <button type="button" class="boton-icono" aria-label="Cerrar" wire:click="cerrar" data-cerrar-modal>×</button>
      </div>
      <div class="ficha ficha--simple">
        @foreach ($ficha as $x)
          <div><span class="ficha__label">{{ $x['etiqueta'] }}</span><strong>{{ $x['valor'] }}</strong></div>
        @endforeach
      </div>
      @if ($alerta)
        <p class="aviso aviso--rechazado">Atención: {{ preg_replace_callback('/\d+/', fn ($m) => Formato::pesos((int) $m[0]), $alerta) }}</p>
      @endif
      <h3 class="subtitulo">Personas</h3>
      <table class="tabla">
        <thead><tr><th>Nombre</th><th>Documento</th><th>Tipo</th><th class="num">Valor</th></tr></thead>
        <tbody>
          @foreach ($d['personas'] as $p)
            <tr wire:key="dp-{{ $p['documento'] }}">
              <td>{{ $p['nombre'] }}</td><td>{{ $p['documento'] }}</td>
              <td><span class="chip chip--{{ strtolower($p['tipo']) }}">{{ Formato::ETIQUETA_TIPO[$p['tipo']] ?? $p['tipo'] }}</span></td>
              <td class="num">{{ Formato::pesos($p['valor']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <h3 class="subtitulo">Soportes de pago</h3>
      <div>
        @forelse ($d['soportes'] as $i => $s)
          @php
            $url = route('panel.comprobante', $s['id']);
            $enAgencia = $s['medio_pago'] === 'AGENCIA';
            $datos = $enAgencia
              ? [$dato('Medio de pago', 'En agencia / efectivo'), $dato('Agencia de pago', $s['agencia_pago']), $dato('Recibo de caja', $s['recibo'] ?: 'No indicado')]
              : array_filter([$dato('Medio de pago', 'PSE'), $dato('CUS', $s['cus']), $s['banco'] ? $dato('Banco', $s['banco']) : null]);
            $datos = [...$datos, $dato('Fecha pago', Formato::fechaCorta($s['fecha_pago'])), $dato('Valor pagado', Formato::pesos($s['valor_pagado'])),
              $dato('Cargado', Formato::fechaHora($s['cargado_en']))];
            foreach ($s['campos'] as $campo => $valor) { $datos[] = $dato($etiquetas[$campo] ?? $campo, $valor); }
          @endphp
          <div class="soporte{{ $i ? ' soporte--anterior' : '' }}" wire:key="soporte-{{ $s['id'] }}">
            @if ($enAgencia && $i === 0)
              <p class="aviso aviso--pendiente">Pago registrado en agencia: verifique con caja o tesorería de la agencia antes de aprobar.</p>
            @endif
            <div class="ficha ficha--simple">
              @foreach ($datos as $x)
                <div><span class="ficha__label">{{ $x['etiqueta'] }}</span><strong>{{ $x['valor'] }}</strong></div>
              @endforeach
            </div>
            @if ($i === 0 && $s['tiene_archivo'])
              @if ($s['tipo_archivo'] === 'application/pdf')
                <iframe src="{{ $url }}" title="Soporte PDF" class="soporte__vista"></iframe>
              @else
                <img src="{{ $url }}" alt="Soporte de pago" class="soporte__vista">
              @endif
            @endif
            @if ($s['tiene_archivo'])
              <a href="{{ $url }}" target="_blank" rel="noopener" class="enlace">Abrir {{ $s['nombre_original'] ?: 'soporte' }} en otra pestaña</a>
            @else
              <p class="nota">Sin comprobante adjunto.</p>
            @endif
            @if ($i === 1)
              <p class="nota">Soportes anteriores (rechazados):</p>
            @endif
          </div>
        @empty
          <p class="nota">Aún no se ha cargado soporte de pago.</p>
        @endforelse
      </div>
      @if ($acciones)
        <div class="revision">
          <label for="d-motivo" class="campo__label">Motivo (obligatorio para rechazar o anular)</label>
          <textarea id="d-motivo" class="campo" rows="2" maxlength="500" wire:model="motivo"></textarea>
          <div class="acciones acciones--fila">
            @if (in_array('APROBAR', $acciones, true))
              <button type="button" class="boton boton--primario" wire:click="pedirRevision('APROBAR')">Aprobar pago</button>
            @endif
            @if (in_array('RECHAZAR', $acciones, true))
              <button type="button" class="boton boton--linea" wire:click="pedirRevision('RECHAZAR')">Rechazar soporte</button>
            @endif
            <span class="espaciador"></span>
            @if (in_array('ANULAR', $acciones, true))
              <button type="button" class="boton boton--peligro" wire:click="pedirRevision('ANULAR')">Anular inscripción</button>
            @endif
          </div>
          @include('livewire.partials.mensaje', ['area' => 'revision', 'objetivo' => 'confirmarAccion', 'espera' => 'Guardando…'])
        </div>
      @endif
    </dialog>
  @endif

  <x-modal-confirmacion :confirmacion="$confirmacion" />
</section>
