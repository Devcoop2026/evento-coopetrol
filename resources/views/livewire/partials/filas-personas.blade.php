{{-- Filas de la tabla Persona | Tipo | Valor. $personas: [{nombre, documento, tipo, valor, observacion?, sub?}] --}}
@use('App\Livewire\Formato')
@foreach ($personas as $p)
  <tr wire:key="persona-{{ $p['documento'] }}">
    <td>{{ $p['nombre'] }}<span class="sub">{{ $p['sub'] ?? Formato::subPersona($p) }}</span></td>
    <td><span class="chip chip--{{ strtolower($p['tipo']) }}">{{ Formato::ETIQUETA_TIPO[$p['tipo']] ?? $p['tipo'] }}</span></td>
    <td class="num">{{ Formato::pesos($p['valor']) }}</td>
  </tr>
@endforeach
