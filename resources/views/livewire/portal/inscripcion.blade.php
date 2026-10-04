@use('App\Livewire\Formato')
@php
  $bloqueado = $identidad ? '' : 'data-bloqueado';
  $max = $evento['maxAcompanantes'];
  $r = $simulacion['resultado'] ?? null;
@endphp
<div>
  <form id="formulario" class="tarjeta" wire:submit="simular" novalidate>
    <div class="modulo-activo">
      <h2 class="modulo-activo__titulo" id="titulo-modulo">{{ $titulo['titulo'] }}</h2>
      <p class="modulo-activo__desc">{{ $titulo['desc'] }}</p>
    </div>
    <ol class="pasos">
      <li class="paso">
        <h2 class="paso__titulo"><span class="paso__num">1</span> Identifíquese como asociado</h2>
        <div class="identidad">
          <div>
            <label for="documento" class="campo__label">Número de documento</label>
            <input id="documento" class="campo" data-validar="numerico" maxlength="15" autocomplete="off" placeholder="Ej: 1001" required
                   wire:model="documento" wire:keydown.enter.prevent="validar" @if (in_array('documento', $invalidos, true)) aria-invalid="true" @endif>
          </div>
          <div>
            <label for="expedicion" class="campo__label">Fecha de expedición del documento</label>
            <input id="expedicion" class="campo" type="date" required wire:model="fechaExpedicion" wire:keydown.enter.prevent="validar"
                   @if (in_array('fechaExpedicion', $invalidos, true)) aria-invalid="true" @endif>
          </div>
          <button type="button" class="boton boton--secundario" wire:click="validar" wire:loading.attr="disabled" wire:target="validar">Validar</button>
        </div>
        @include('livewire.partials.habeas', ['id' => 'acepta-datos', 'modelo' => 'aceptaDatos', 'imagen' => 'aceptaImagen'])
        @include('livewire.partials.mensaje', ['area' => 'asociado', 'objetivo' => 'validar', 'espera' => 'Validando…', 'rol' => 'status'])
        @if ($asociado)
          <div class="ficha">
            <div><span class="ficha__label">Asociado</span><strong>{{ $asociado['nombre'] }}</strong></div>
            <div><span class="ficha__label">Su agencia</span><strong>{{ $asociado['agencia'] }}</strong></div>
            <div><span class="ficha__label">Datos actualizados</span><strong>{{ Formato::fechaCorta($asociado['fechaActualizacion']) }}</strong></div>
          </div>
        @endif
      </li>

      <li class="paso" {{ $bloqueado }}>
        <h2 class="paso__titulo"><span class="paso__num">2</span> ¿A qué evento asistirá?</h2>
        <label for="agencia-evento" class="campo__label">Evento de la agencia</label>
        <select id="agencia-evento" class="campo" wire:model.live="agenciaEvento" @disabled(! $identidad)>
          @foreach ($evento['tarifas'] as $t)
            <option value="{{ $t['agencia'] }}">{{ $t['agencia'] }}</option>
          @endforeach
        </select>
        <p class="ayuda">Los valores y los cupos corresponden al evento de la agencia seleccionada. Solo los asociados ocupan cupo; los invitados no.</p>
        @if ($tarifa)
          <livewire:portal.contador-cupos :agencia="$tarifa['agencia']" :valor-asociado="$tarifa['valor_asociado']"
                                          :valor-invitado="$tarifa['valor_invitado']" :key="'cupos-'.$tarifa['agencia']" />
        @else
          <div class="ficha">
            <div><span class="ficha__label">Valor asociado</span><strong></strong></div>
            <div><span class="ficha__label">Valor invitado</span><strong></strong></div>
            <div><span class="ficha__label">Cupos disponibles</span><strong class="contador"></strong></div>
          </div>
        @endif
      </li>

      <li class="paso" {{ $bloqueado }}>
        <h2 class="paso__titulo"><span class="paso__num">3</span> ¿Cómo asistirá?</h2>
        <div class="opciones" role="radiogroup" aria-label="Modalidad de ingreso">
          <label class="opcion">
            <input type="radio" name="modalidad" value="SOLO" wire:model.live="modalidad" @disabled(! $identidad)>
            <span class="opcion__caja"><strong>Solo</strong><small>Ingreso únicamente del asociado</small></span>
          </label>
          <label class="opcion">
            <input type="radio" name="modalidad" value="ACOMPAÑADO" wire:model.live="modalidad" @disabled(! $identidad)>
            <span class="opcion__caja"><strong>Acompañado</strong><small>Con familiares o invitados</small></span>
          </label>
        </div>
        @if ($modalidad === 'ACOMPAÑADO')
          <div>
            <p class="ayuda">Todos los acompañantes deben registrar <strong>número de documento</strong> y <strong>nombres y apellidos completos</strong>, además del tipo. Si el documento es de un asociado, se aplica automáticamente la tarifa de asociado. Los <strong>Coopetrolitos</strong> (si están en el programa) pagan tarifa de asociado y se validan contra la base de Coopetrolitos. Máximo <span class="max-acomp">{{ $max }}</span>.</p>
            @include('livewire.partials.acompanantes', ['propiedad' => 'acompanantes', 'lista' => $acompanantes, 'quitar' => 'quitarAcompanante'])
            @if (count($acompanantes) < $max)
              <button type="button" class="boton boton--linea" wire:click="agregarAcompanante">+ Agregar acompañante</button>
            @endif
          </div>
        @endif
      </li>

      <li class="paso" {{ $bloqueado }}>
        <h2 class="paso__titulo"><span class="paso__num">4</span> Calcule su valor a pagar</h2>
        <button type="submit" class="boton boton--primario boton--ancho" wire:loading.attr="disabled" wire:target="simular">Simular valor</button>
        @include('livewire.partials.mensaje', ['area' => 'simulacion', 'objetivo' => 'simular', 'espera' => 'Calculando…'])
      </li>
    </ol>
  </form>

  @if ($r)
    <aside class="tarjeta resultado" id="resultado" aria-live="polite">
      <h2>Resumen de su simulación</h2>
      <table class="tabla">
        <thead><tr><th>Persona</th><th>Tipo</th><th class="num">Valor</th></tr></thead>
        <tbody>
          @include('livewire.partials.filas-personas', ['personas' => [
            ['nombre' => $r['asociado']['nombre'], 'documento' => $r['asociado']['documento'], 'tipo' => 'TITULAR', 'valor' => $r['titular']['valor'], 'sub' => "Asociado · Evento {$r['agenciaEvento']}"],
            ...$r['acompanantes'],
          ]])
        </tbody>
        <tfoot><tr><th colspan="2">Total a pagar</th><th class="num">{{ Formato::pesos($r['resumen']['total']) }}</th></tr></tfoot>
      </table>
      <p class="nota">{{ $r['modalidad'] === 'SOLO'
        ? 'Ingreso individual del asociado.'
        : "{$r['resumen']['personas']} personas: titular, {$r['resumen']['acompanantesAsociados']} asociado(s), {$r['resumen']['coopetrolitos']} Coopetrolito(s) y {$r['resumen']['invitados']} no asociado(s)." }}</p>
      @if ($modulo !== 'AGENCIA')
        <div class="acciones">
          <button type="button" class="boton boton--primario boton--ancho" wire:click="preinscribir" wire:loading.attr="disabled"
                  wire:target="preinscribir,confirmarAccion">Inscribirme y pagar por PSE</button>
        </div>
        @include('livewire.partials.mensaje', ['area' => 'preinscripcion', 'objetivo' => 'confirmarAccion', 'espera' => 'Registrando preinscripción…'])
        @if ($tieneInscripcionExistente)
          <button type="button" class="boton boton--primario" wire:click="verInscripcion">Ver mi inscripción y pagar</button>
        @endif
        <p class="nota">La preinscripción fija el valor calculado, pero <strong>no separa cupo</strong>: el cupo se asigna cuando registre su pago, según disponibilidad. Podrá modificar sus acompañantes hasta que registre el pago.</p>
      @else
        <p class="nota">Registre abajo el pago que realizó en la agencia. Al enviarlo se crea su inscripción y se asigna el cupo, según disponibilidad, mientras el área encargada verifica el pago.</p>
      @endif
    </aside>

    @if ($modulo === 'AGENCIA')
      @include('livewire.partials.formulario-pago', ['enInscripcion' => true, 'accion' => 'registrarPagoAgencia', 'total' => $r['resumen']['total'], 'referencia' => null])
      @if ($tieneInscripcionExistente)
        <button type="button" class="boton boton--primario" wire:click="verInscripcion">Ver mi inscripción y pagar</button>
      @endif
    @endif
  @endif

  @if ($confirmada)
    <aside class="tarjeta confirmacion" id="confirmacion" aria-live="polite">
      @if ($modulo === 'AGENCIA')
        <p class="confirmacion__etiqueta">¡Inscripción y pago registrados!</p>
      @else
        <p class="confirmacion__etiqueta">¡Preinscripción registrada!</p>
      @endif
      <h2>Su referencia es <span class="referencia">{{ $confirmada['referencia'] }}</span></h2>
      @if ($modulo === 'AGENCIA')
        <p class="aviso aviso--revision">Su pago quedó <strong>en revisión</strong> y su cupo asignado. El área encargada verificará el pago con la agencia y le confirmará la inscripción. Consulte el estado en cualquier momento con su documento y fecha de expedición.</p>
      @else
        <p class="aviso aviso--pendiente">Su cupo <strong>aún no está asegurado</strong>: se asigna al registrar el pago, según la disponibilidad del evento. Pague y registre el pago lo antes posible.</p>
      @endif
      <p>Total a pagar: <strong>{{ Formato::pesos($confirmada['total']) }}</strong> · Personas: <strong>{{ count($confirmada['personas']) }}</strong></p>
      @if ($modulo === 'AGENCIA')
        <button type="button" class="boton boton--linea" wire:click="verInscripcion">Ver mi inscripción</button>
      @else
        <ol class="instrucciones">
          <li>Pague el valor total por <strong>PSE</strong> con el botón <strong>Pagar por PSE</strong> (escriba la referencia en la descripción).</li>
          <li>Guarde el comprobante y el <strong>número CUS</strong> de la transacción.</li>
          <li>Registre el pago con el botón <strong>Ya pagué: registrar el pago</strong> (o más tarde en <strong>Consulte su inscripción</strong>).</li>
        </ol>
        <div class="pago-pse">
          <a class="boton boton--pse" href="{{ $evento['enlacePago'] }}" target="_blank" rel="noopener noreferrer">Pagar por PSE</a>
          <p class="pago-pse__nota">Se abre en una pestaña nueva. Pague <strong>{{ Formato::pesos($confirmada['total']) }}</strong> y escriba la referencia
            <strong class="referencia">{{ $confirmada['referencia'] }}</strong> en la descripción del pago.
            <button type="button" class="boton-texto" data-copiar="{{ $confirmada['referencia'] }}">Copiar referencia</button></p>
        </div>
        <button type="button" class="boton boton--linea" wire:click="verInscripcion">Ya pagué: registrar el pago</button>
      @endif
    </aside>
  @endif

  <x-modal-confirmacion :confirmacion="$confirmacion" />
</div>
