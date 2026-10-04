@use('App\Livewire\Formato')
@use('App\Livewire\Portal\Portal')
@php
  $nombre = ! empty($evento['evento']['nombre']) ? Formato::oracion($evento['evento']['nombre']) : 'Evento Fin de Año Coopetrol';
  $periodo = ! empty($evento['evento']['inscripciones'])
    ? Formato::oracion(preg_replace('/^INC?RIPCIONES/i', 'inscripciones', $evento['evento']['inscripciones']))
    : 'Inscripciones del 01 al 31 de octubre';
@endphp
<div>
  <section class="hero">
    <div class="contenedor">
      <p class="hero__etiqueta">{{ $periodo }}</p>
      <h1>{{ $nombre }}</h1>
      <p class="hero__texto">Simule cuánto debe pagar por su ingreso y el de sus acompañantes e inscríbase. Su cupo se asigna al registrar el pago. Los acompañantes pagan el 100% del valor del evento. <strong>Elija cómo realizará el pago:</strong></p>
      <div class="modulos" role="group" aria-label="Módulos de inscripción y pago">
        <button type="button" class="modulo" data-modulo="PSE" aria-controls="vista-inscribir" wire:click="elegirModulo('PSE')"
                aria-pressed="{{ $vista === 'inscribir' && $modulo === 'PSE' ? 'true' : 'false' }}">
          <span class="modulo__icono" aria-hidden="true">
            <svg viewBox="0 0 24 24"><rect x="2.5" y="5" width="19" height="14" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M2.5 9.5h19M6.5 15h4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </span>
          <span class="modulo__texto">
            <strong class="modulo__titulo">Inscripción y pago por PSE</strong>
            <small class="modulo__desc">Inscríbase, pague en línea y registre el número CUS de su transacción.</small>
          </span>
          <span class="modulo__flecha" aria-hidden="true">→</span>
        </button>
        <button type="button" class="modulo" data-modulo="AGENCIA" aria-controls="vista-inscribir" wire:click="elegirModulo('AGENCIA')"
                aria-pressed="{{ $vista === 'inscribir' && $modulo === 'AGENCIA' ? 'true' : 'false' }}">
          <span class="modulo__icono" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M3 10 12 4l9 6M5 10v9m14-9v9M9 19v-5h6v5M3 19.5h18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          <span class="modulo__texto">
            <strong class="modulo__titulo">Inscripción y pago en agencia</strong>
            <small class="modulo__desc">¿Pagó en caja o en efectivo en una agencia de Coopetrol? Inscríbase y registre el pago en un solo paso.</small>
          </span>
          <span class="modulo__flecha" aria-hidden="true">→</span>
        </button>
      </div>
      <button type="button" class="enlace-consulta{{ $vista === 'consulta' ? ' enlace-consulta--activo' : '' }}" aria-controls="vista-mi"
              wire:click="irAConsulta">¿Ya se inscribió? <strong>Consulte su inscripción</strong>, registre el pago o modifíquela →</button>
    </div>
  </section>

  <main class="contenedor principal">
    <p class="tarjeta inicio" @if ($vista !== 'inicio') hidden @endif>Elija arriba un módulo para comenzar: <strong>pago por PSE</strong> si pagará en línea,
      o <strong>pago en agencia</strong> si ya pagó (o pagará) en caja o en efectivo en una agencia de Coopetrol.</p>

    <section id="vista-inscribir" class="vista" aria-labelledby="titulo-modulo" @if ($vista !== 'inscribir') hidden @endif>
      <livewire:portal.inscripcion :evento="$evento" wire:key="inscripcion" />
    </section>

    <section id="vista-mi" class="vista" aria-label="Consultar mi inscripción" @if ($vista !== 'consulta') hidden @endif>
      <livewire:portal.consulta :evento="$evento" wire:key="consulta" />
    </section>

    <details class="tarjeta tarifas">
      <summary>Ver tarifas y cupos por evento</summary>
      <livewire:portal.tabla-tarifas wire:key="tarifas" />
    </details>
  </main>
</div>
