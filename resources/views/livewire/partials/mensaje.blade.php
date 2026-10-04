{{-- Mensaje de estado de un área (ConMensajes). `espera`: texto mientras corre la acción `objetivo`. --}}
@php $m = $mensajes[$area] ?? null; @endphp
<p class="mensaje{{ $m && $m['tipo'] ? ' mensaje--'.$m['tipo'] : '' }}" role="{{ $rol ?? 'alert' }}" aria-live="polite">
  @isset($objetivo)
    <span wire:loading wire:target="{{ $objetivo }}">{{ $espera ?? 'Procesando…' }}</span>
    <span wire:loading.remove wire:target="{{ $objetivo }}">{{ $m['texto'] ?? '' }}</span>
  @else
    {{ $m['texto'] ?? '' }}
  @endisset
</p>
