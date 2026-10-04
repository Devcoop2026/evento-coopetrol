@use('App\Livewire\Formato')
<div class="tabla-scroll" wire:poll.30s.visible>
  <table class="tabla">
    <thead><tr><th>Evento (agencia)</th><th class="num">Cupos</th><th class="num">Disponibles</th><th class="num">Valor invitado</th><th class="num">Valor asociado</th></tr></thead>
    <tbody>
      @foreach ($tarifas as $t)
        @php $c = $cupos[$t['agencia']] ?? null; @endphp
        <tr wire:key="tarifa-{{ $t['agencia'] }}">
          <td>{{ $t['agencia'] }}</td>
          <td class="num">{{ Formato::numero($c['cupos'] ?? $t['cupos']) }}</td>
          <td class="num{{ ($c['disponibles'] ?? null) === 0 ? ' agotado' : '' }}">{{ $c ? Formato::numero($c['disponibles']) : '—' }}</td>
          <td class="num">{{ Formato::pesos($t['valor_invitado']) }}</td>
          <td class="num">{{ Formato::pesos($t['valor_asociado']) }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
</div>
