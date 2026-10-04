{{--
  Formulario de pago (FormularioPago), en dos lugares:
    $enInscripcion = true   módulo "pago en agencia", debajo del resumen: solo pago en agencia; crea la inscripción con el pago
                            (la autorización de datos ya se aceptó en el paso 1)
    $enInscripcion = false  "Consulte su inscripción": elige el medio (PSE o agencia) y registra el pago de una inscripción existente
  Otros parámetros: $evento (ConsultarDatosEvento), $total, $referencia (null en el módulo agencia), $accion (método al enviar).
--}}
@use('App\Livewire\Formato')
@use('App\Livewire\Concerns\ConFormularioPago')
@php
  $medio = $enInscripcion ? 'AGENCIA' : $pago->medioPago;
  $invalido = fn ($campo) => in_array($campo, $invalidos, true) ? 'aria-invalid=true' : '';
  $tipos = ['texto' => 'text', 'numero' => 'text', 'fecha' => 'date', 'correo' => 'email', 'telefono' => 'tel', 'placa' => 'text'];
  $definicion = $evento['camposSoporte'] ?? [];
  $tipoArchivo = \App\Livewire\Formularios\FormularioPago::tipoArchivo($comprobante);
  $hoy = \App\Dominio\Compartido\Valores::fechaColombia(app(\App\Dominio\Compartido\Reloj::class)->ahora());
@endphp
<form class="tarjeta" id="form-soporte" wire:submit="{{ $accion }}" novalidate>
  <h2 class="tarjeta__titulo">{{ $enInscripcion ? 'Registre el pago realizado en la agencia' : 'Registrar el pago' }}</h2>
  <p class="ayuda">Total a pagar: <strong>{{ Formato::pesos($total) }}</strong>.
    {{ $enInscripcion
      ? 'Indique la agencia y la fecha en que pagó. El número de recibo de caja y la foto del comprobante son opcionales.'
      : 'Puede pagar por PSE o directamente en cualquier agencia de Coopetrol (efectivo u otro medio) indicando su referencia.' }}</p>

  @unless ($enInscripcion)
    <fieldset class="medio-pago">
      <legend class="campo__label">¿Cómo realizó el pago? *</legend>
      <div class="opciones">
        <label class="opcion">
          <input type="radio" name="medio-pago" value="PSE" wire:model.live="pago.medioPago">
          <span class="opcion__caja"><strong>Por PSE</strong><small>Pago en línea con CUS y comprobante</small></span>
        </label>
        <label class="opcion">
          <input type="radio" name="medio-pago" value="AGENCIA" wire:model.live="pago.medioPago">
          <span class="opcion__caja"><strong>En agencia o en efectivo</strong><small>Pago directo en una agencia de Coopetrol</small></span>
        </label>
      </div>
    </fieldset>
  @endunless

  @if ($medio === 'PSE')
    <div class="pago-pse">
      <a class="boton boton--pse" href="{{ $evento['enlacePago'] }}" target="_blank" rel="noopener noreferrer">Pagar por PSE</a>
      <p class="pago-pse__nota">Se abre en una pestaña nueva. Pague <strong>{{ Formato::pesos($total) }}</strong> y escriba la referencia
        <strong class="referencia">{{ $referencia }}</strong> en la descripción del pago.
        <button type="button" class="boton-texto" data-copiar="{{ $referencia }}">Copiar referencia</button></p>
    </div>
    <div class="rejilla">
      <div>
        <label for="s-cus" class="campo__label">Número CUS de la transacción *</label>
        <input id="s-cus" class="campo" data-validar="numerico" maxlength="20" autocomplete="off" required wire:model="pago.cus" {{ $invalido('cus') }}>
      </div>
    </div>
  @else
    @unless ($enInscripcion)
      <p class="aviso aviso--pendiente">Acérquese a cualquier agencia de Coopetrol con la referencia <strong class="referencia">{{ $referencia }}</strong>
        y pague <strong>{{ Formato::pesos($total) }}</strong>. Luego registre aquí el pago; el área encargada lo verificará con la agencia.</p>
    @endunless
    <div class="rejilla">
      <div>
        <label for="s-agencia-pago" class="campo__label">Agencia donde pagó *</label>
        <select id="s-agencia-pago" class="campo" required wire:model="pago.agenciaPago" {{ $invalido('agenciaPago') }}>
          <option value="">Seleccione…</option>
          @foreach ($evento['agenciasPago'] as $agencia)
            <option value="{{ $agencia }}">{{ $agencia }}</option>
          @endforeach
        </select>
      </div>
      <div>
        <label for="s-recibo" class="campo__label">Número de recibo de caja (opcional)</label>
        <input id="s-recibo" class="campo" data-validar="alfanumerico" autocomplete="off" maxlength="30" wire:model="pago.recibo">
      </div>
    </div>
  @endif

  <div class="rejilla">
    <div>
      <label for="s-fecha" class="campo__label">Fecha del pago *</label>
      <input id="s-fecha" class="campo" type="date" max="{{ $hoy }}" required wire:model="pago.fechaPago" {{ $invalido('fechaPago') }}>
    </div>
    <div>
      <label for="s-valor" class="campo__label">Valor pagado *</label>
      <input id="s-valor" class="campo" data-validar="numerico" maxlength="10" autocomplete="off" required wire:model="pago.valorPagado" {{ $invalido('valorPagado') }}>
    </div>
  </div>

  <div class="rejilla">
    @foreach ($pago->camposVisibles($definicion) as $c)
      @php
        $tieneDependientes = collect($definicion)->contains(fn ($x) => ($x['mostrarSi']['campo'] ?? null) === $c['id']);
        $ancho = empty($c['mostrarSi']) && ($c['tipo'] ?? '') === 'texto' && ($c['max'] ?? 200) >= 300;
        $clases = 'campo-adicional'.($tieneDependientes ? ' campo-adicional--pregunta' : '').(! empty($c['mostrarSi']) ? ' campo-adicional--dependiente' : '').($ancho ? ' campo-adicional--ancho' : '');
      @endphp
      <div class="{{ $clases }}" wire:key="campo-{{ $c['id'] }}">
        <label class="campo__label" for="ad-{{ $c['id'] }}">{{ $c['etiqueta'] }}{{ ! empty($c['requerido']) ? ' *' : '' }}</label>
        @if (($c['tipo'] ?? '') === 'seleccion')
          <select id="ad-{{ $c['id'] }}" class="campo" @required(! empty($c['requerido'])) wire:model.live="pago.campos.{{ $c['id'] }}" {{ $invalido('campo.'.$c['id']) }}>
            <option value="">Seleccione…</option>
            @foreach ($c['opciones'] ?? [] as $opcion)
              <option value="{{ $opcion }}">{{ $opcion }}</option>
            @endforeach
          </select>
        @else
          <input id="ad-{{ $c['id'] }}" type="{{ $tipos[$c['tipo'] ?? 'texto'] ?? 'text' }}"
                 class="campo{{ ($c['tipo'] ?? '') === 'placa' ? ' campo--mayus' : '' }}"
                 @required(! empty($c['requerido'])) wire:model="pago.campos.{{ $c['id'] }}" {{ $invalido('campo.'.$c['id']) }}
                 @if (($c['tipo'] ?? '') === 'numero') inputmode="decimal" @endif
                 @if (($c['tipo'] ?? '') === 'placa') maxlength="7" autocomplete="off" placeholder="ABC123" data-validar="alfanumerico" @endif
                 @if (($c['tipo'] ?? '') === 'telefono') maxlength="15" data-validar="numerico" @endif
                 @if (! empty($c['max'])) maxlength="{{ $c['max'] }}" @endif>
        @endif
        @if (! empty($c['ayuda']))
          <small class="campo__ayuda">{{ $c['ayuda'] }}</small>
        @endif
      </div>
    @endforeach
  </div>

  <span class="campo__label" id="s-archivo-titulo">Comprobante de pago <span>{{ $medio === 'PSE' ? '*' : '(opcional: foto del recibo)' }}</span></span>
  <div class="zona-archivo{{ $comprobante ? ' zona-archivo--lista' : '' }}">
    <input id="s-archivo" class="solo-lector" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"
           aria-labelledby="s-archivo-titulo" aria-describedby="s-archivo-ayuda" wire:model="comprobante" {{ $invalido('archivo') }}>
    @if ($tipoArchivo)
      <div class="zona-archivo__elegido">
        @if ($tipoArchivo?->esImagen())
          <img class="zona-archivo__miniatura" src="{{ $comprobante->temporaryUrl() }}" alt="">
        @else
          <span class="zona-archivo__tipo" aria-hidden="true">{{ $tipoArchivo ? strtoupper($tipoArchivo->extension()) : 'PDF' }}</span>
        @endif
        <span class="zona-archivo__datos">
          <strong class="zona-archivo__nombre">{{ $comprobante->getClientOriginalName() }}</strong>
          <span class="zona-archivo__peso">{{ strtoupper($tipoArchivo?->extension() ?? '') }} · {{ ConFormularioPago::peso($comprobante->getSize()) }}</span>
        </span>
        <label for="s-archivo" class="boton boton--linea boton--mini">Cambiar</label>
        <button type="button" class="boton-icono" aria-label="Quitar archivo" wire:click="quitarArchivo">×</button>
      </div>
    @else
      <label for="s-archivo" class="zona-archivo__vacia">
        <svg class="zona-archivo__icono" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0-4 4m4-4 4 4M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="zona-archivo__texto"><strong>Arrastre aquí su comprobante</strong> o <span class="zona-archivo__enlace">selecciónelo desde su equipo</span></span>
        <span class="zona-archivo__ayuda" id="s-archivo-ayuda">PDF, JPG o PNG · máximo {{ $evento['soporteMaxMb'] }} MB</span>
      </label>
    @endif
  </div>
  @include('livewire.partials.mensaje', ['area' => 'archivo', 'objetivo' => 'comprobante', 'espera' => 'Cargando archivo…'])

  @unless ($enInscripcion)
    @include('livewire.partials.habeas', ['id' => 'acepta-datos-soporte', 'modelo' => 'pago.aceptaDatos'])
  @endunless

  <button type="submit" class="boton boton--primario boton--ancho" wire:loading.attr="disabled" wire:target="{{ $accion }},confirmarAccion,comprobante">
    {{ $enInscripcion ? 'Inscribirme y registrar el pago' : 'Enviar soporte' }}</button>
  @include('livewire.partials.mensaje', ['area' => 'soporte', 'objetivo' => $accion.',confirmarAccion', 'espera' => $enInscripcion ? 'Registrando inscripción y pago…' : 'Enviando soporte…'])
</form>
