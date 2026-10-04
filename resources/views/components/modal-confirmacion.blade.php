{{-- Diálogo de confirmación (ConConfirmacion). Se abre con showModal() al aparecer (public/js/interfaz.js). --}}
@props(['confirmacion'])
@if ($confirmacion)
  @php $iconos = ['normal' => '✓', 'peligro' => '!', 'aviso' => 'i']; @endphp
  <dialog class="modal" data-modal data-tono="{{ $confirmacion['tono'] }}" wire:ignore.self wire:key="modal-confirmacion"
          aria-labelledby="modal-titulo" aria-describedby="modal-mensaje">
    <div class="modal__icono" aria-hidden="true">{{ $iconos[$confirmacion['tono']] ?? '✓' }}</div>
    <h2 class="modal__titulo" id="modal-titulo">{{ $confirmacion['titulo'] }}</h2>
    <p class="modal__mensaje" id="modal-mensaje">{{ $confirmacion['mensaje'] }}</p>
    <dl class="modal__detalles">
      @foreach ($confirmacion['detalles'] as [$etiqueta, $valor])
        <dt>{{ $etiqueta }}</dt>
        <dd>{{ $valor }}</dd>
      @endforeach
    </dl>
    <div class="modal__acciones">
      <button type="button" class="boton boton--linea" wire:click="cancelarAccion" data-cerrar-modal
              @if ($confirmacion['tono'] === 'peligro') data-foco @endif>{{ $confirmacion['cancelar'] }}</button>
      <button type="button" class="boton {{ $confirmacion['tono'] === 'peligro' ? 'boton--peligro-lleno' : 'boton--primario' }}"
              wire:click="confirmarAccion" data-confirmar-modal>{{ $confirmacion['aceptar'] }}</button>
    </div>
  </dialog>
@endif
