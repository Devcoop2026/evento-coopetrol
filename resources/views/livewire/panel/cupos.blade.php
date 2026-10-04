@use('Illuminate\Support\Js')
<details class="tarjeta" open wire:poll.30s.visible>
  <summary class="resumen-titulo">Cupos por evento (agencia)</summary>
  <p class="nota">El cupo se ocupa cuando el asociado <strong>registra el pago</strong> (en revisión o confirmado); las preinscripciones sin pago se muestran como "Preinscritos". Solo los asociados y Coopetrolitos ocupan cupo; los invitados se muestran como referencia.
    @if ($administrador) Edite el cupo y presione Guardar; deje el campo vacío para volver al valor del Excel. @endif Se actualiza automáticamente cada 30 segundos.</p>
  @include('livewire.partials.mensaje', ['area' => 'cupos', 'rol' => 'status'])
  <div class="tabla-scroll">
    <table class="tabla">
      <thead><tr><th>Agencia</th><th class="num">Cupo (Excel)</th><th>Cupo vigente</th><th class="num">Ocupados</th><th class="num">Confirmados</th><th class="num">Disponibles</th><th class="num">Preinscritos sin pago</th><th class="num">Invitados</th></tr></thead>
      <tbody>
        @foreach ($cupos as $c)
          <tr wire:key="cupo-{{ $c['agencia'] }}">
            <td>{{ $c['agencia'] }}</td>
            <td class="num">{{ $c['cupos_excel'] }}</td>
            <td>
              @if ($administrador)
                <div class="cupo-editor">
                  <input class="campo campo--cupo" type="number" min="{{ $c['ocupados'] }}" step="1" inputmode="numeric"
                         placeholder="{{ $c['cupos_excel'] }}" aria-label="Cupo vigente de {{ $c['agencia'] }}"
                         wire:model="ajustes.{{ \App\Livewire\Panel\Cupos::clave($c['agencia']) }}" wire:keydown.enter="ajustar({{ Js::from($c['agencia']) }})">
                  <button type="button" class="boton boton--linea boton--mini" wire:click="ajustar({{ Js::from($c['agencia']) }})"
                          wire:loading.attr="disabled" wire:target="ajustar">Guardar</button>
                  @if ($c['cupos_ajustados'] !== null)
                    <span class="sub">Ajustado</span>
                  @endif
                </div>
              @else
                <span>{{ $c['cupos'] }}{{ $c['cupos_ajustados'] !== null ? ' (ajustado)' : '' }}</span>
              @endif
            </td>
            <td class="num">{{ $c['ocupados'] }}</td>
            <td class="num">{{ $c['confirmados'] }}</td>
            <td class="num{{ $c['disponibles'] <= 0 ? ' agotado' : '' }}">{{ $c['disponibles'] }}</td>
            <td class="num">{{ $c['pendientes'] }}</td>
            <td class="num">{{ $c['invitados'] }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>
</details>
