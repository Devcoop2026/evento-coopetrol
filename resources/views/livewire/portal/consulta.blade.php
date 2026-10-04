@use('App\Livewire\Formato')
@use('App\Livewire\Portal\Consulta')
@php
  $i = $inscripcion;
  $estado = $i ? (Consulta::ESTADOS[$i['estado']] ?? ['texto' => $i['estado'], 'clase' => 'inactivo']) : null;
  if ($i) {
      $aviso = [
          'RECHAZADO' => 'Su soporte fue rechazado: '.($i['motivo'] ?: 'sin motivo').'. Corrija la información y cárguelo de nuevo.',
          'EN_REVISION' => 'Recibimos su soporte. El área encargada lo verificará y actualizará el estado de su inscripción.',
          'CONFIRMADO' => '¡Su inscripción está confirmada! Lo esperamos en el evento.',
          'ANULADO' => 'La inscripción fue anulada'.($i['motivo'] ? ": {$i['motivo']}" : '').'. Comuníquese con su agencia si tiene dudas.',
          'CANCELADO' => 'Usted canceló esta preinscripción. Puede inscribirse de nuevo si hay cupos.',
          'PREINSCRITO' => 'Preinscripción registrada. Su cupo se asigna cuando registre el pago, según disponibilidad.',
      ][$i['estado']] ?? null;
      $s = $i['soporte'];
      $textoSoporte = ! $s ? '—' : ($s['medioPago'] === 'AGENCIA'
          ? "Pago en agencia {$s['agenciaPago']}".($s['recibo'] ? " · recibo {$s['recibo']}" : '').' · '.Formato::fechaCorta($s['cargadoEn'])
          : "PSE · CUS {$s['cus']} · ".Formato::fechaCorta($s['cargadoEn']));
  }
@endphp
<div>
  <form class="tarjeta" wire:submit="consultar" novalidate>
    <h2 class="tarjeta__titulo">Consulte su inscripción</h2>
    <p class="ayuda">Ingrese su número de documento y la fecha de expedición para ver su inscripción, registrar el pago o modificarla.</p>
    <div class="identidad">
      <div>
        <label for="q-documento" class="campo__label">Número de documento</label>
        <input id="q-documento" class="campo" data-validar="numerico" data-recordar-documento maxlength="15" autocomplete="off" required
               wire:model="documento" @if (in_array('documento', $invalidos, true)) aria-invalid="true" @endif>
      </div>
      <div>
        <label for="q-expedicion" class="campo__label">Fecha de expedición</label>
        <input id="q-expedicion" class="campo" type="date" required wire:model="fechaExpedicion"
               @if (in_array('fechaExpedicion', $invalidos, true)) aria-invalid="true" @endif>
      </div>
      <button type="submit" class="boton boton--secundario" wire:loading.attr="disabled" wire:target="consultar,abrir">Consultar</button>
    </div>
    @include('livewire.partials.mensaje', ['area' => 'consulta', 'objetivo' => 'consultar,abrir', 'espera' => 'Consultando…'])
  </form>

  @if ($i)
    <article class="tarjeta" id="estado-inscripcion" aria-live="polite">
      <div class="estado__cabecera">
        <div>
          <span class="ficha__label">Referencia</span>
          <h2 class="referencia">{{ $i['referencia'] }}</h2>
        </div>
        <span class="estado estado--{{ $estado['clase'] }}">{{ $estado['texto'] }}</span>
      </div>
      @if ($aviso)
        <p class="aviso aviso--{{ $estado['clase'] }}">{{ $aviso }}</p>
      @endif
      <div class="ficha ficha--simple">
        <div><span class="ficha__label">Titular</span><strong>{{ $i['titular'] }}</strong></div>
        <div><span class="ficha__label">Evento (agencia)</span><strong>{{ $i['agencia'] }}{{ $i['agenciaAsociado'] && $i['agenciaAsociado'] !== $i['agencia'] ? " (asociado de {$i['agenciaAsociado']})" : '' }}</strong></div>
        <div><span class="ficha__label">Total a pagar</span><strong>{{ Formato::pesos($i['total']) }}</strong></div>
        <div><span class="ficha__label">Pago registrado</span><strong>{{ $textoSoporte }}</strong></div>
      </div>
      <table class="tabla">
        <thead><tr><th>Persona</th><th>Tipo</th><th class="num">Valor</th></tr></thead>
        <tbody>@include('livewire.partials.filas-personas', ['personas' => $i['personas']])</tbody>
      </table>
      @if ($i['editable'])
        <div class="acciones acciones--fila">
          <button type="button" class="boton boton--linea" wire:click="modificar">Modificar inscripción</button>
          <button type="button" class="boton boton--peligro" wire:click="cancelar" wire:loading.attr="disabled" wire:target="confirmarAccion">Cancelar preinscripción</button>
        </div>
      @endif
      @include('livewire.partials.mensaje', ['area' => 'estado', 'objetivo' => 'confirmarAccion', 'espera' => 'Cancelando…'])
    </article>

    @if ($editando)
      <form class="tarjeta" id="form-modificar" wire:submit="guardarModificacion" novalidate>
        <h2 class="tarjeta__titulo">Modificar inscripción</h2>
        <label for="m-agencia" class="campo__label">Evento de la agencia</label>
        <select id="m-agencia" class="campo" wire:model="agenciaModificar">
          @foreach ($evento['tarifas'] as $t)
            <option value="{{ $t['agencia'] }}">{{ $t['agencia'] }}</option>
          @endforeach
        </select>
        <p class="ayuda">El total se recalcula con las tarifas del evento elegido y se valida la disponibilidad de cupos. Deje la lista vacía si asistirá solo. Máximo <span class="max-acomp">{{ $evento['maxAcompanantes'] }}</span> acompañantes.</p>
        @include('livewire.partials.acompanantes', ['propiedad' => 'acompanantesModificar', 'lista' => $acompanantesModificar, 'quitar' => 'quitarAcompananteModificar'])
        <div class="acciones acciones--fila">
          @if (count($acompanantesModificar) < $evento['maxAcompanantes'])
            <button type="button" class="boton boton--linea" wire:click="agregarAcompananteModificar">+ Agregar acompañante</button>
          @endif
          <span class="espaciador"></span>
          <button type="button" class="boton boton--linea" wire:click="descartarModificacion">Descartar</button>
          <button type="submit" class="boton boton--primario" wire:loading.attr="disabled" wire:target="guardarModificacion">Guardar cambios</button>
        </div>
        @include('livewire.partials.mensaje', ['area' => 'modificar', 'objetivo' => 'guardarModificacion', 'espera' => 'Guardando…'])
      </form>
    @endif

    @if ($i['editable'])
      @include('livewire.partials.formulario-pago', ['enInscripcion' => false, 'accion' => 'enviarSoporte', 'total' => $i['total'], 'referencia' => $i['referencia']])
    @endif
  @endif

  <x-modal-confirmacion :confirmacion="$confirmacion" />
</div>
