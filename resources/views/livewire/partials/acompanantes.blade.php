{{-- Lista de acompañantes. Parámetros: $propiedad (arreglo del componente), $lista, $max, $agregar y $quitar (acciones). --}}
<div class="acompanantes">
  @foreach ($lista as $i => $a)
    <div class="acompanante" wire:key="{{ $propiedad }}-{{ $i }}">
      <span class="acompanante__num">{{ $i + 1 }}</span>
      <input class="campo" name="acomp-documento-{{ $i }}" data-validar="numerico" maxlength="15" placeholder="N.º de documento"
             aria-label="Número de documento del acompañante" required wire:model="{{ $propiedad }}.{{ $i }}.documento"
             @if (in_array("{$propiedad}.{$i}.documento", $invalidos, true)) aria-invalid="true" @endif>
      <input class="campo" name="acomp-nombre-{{ $i }}" data-validar="texto" maxlength="100" placeholder="Nombres y apellidos"
             aria-label="Nombres y apellidos completos del acompañante" autocomplete="off" required wire:model="{{ $propiedad }}.{{ $i }}.nombre"
             @if (in_array("{$propiedad}.{$i}.nombre", $invalidos, true)) aria-invalid="true" @endif>
      <select class="campo" name="acomp-tipo-{{ $i }}" aria-label="Tipo de acompañante" wire:model="{{ $propiedad }}.{{ $i }}.tipo">
        <option value="NO_ASOCIADO">No asociado</option>
        <option value="COOPETROLITO">Coopetrolito</option>
      </select>
      <button type="button" class="boton-icono" aria-label="Quitar acompañante" wire:click="{{ $quitar }}({{ $i }})">×</button>
    </div>
  @endforeach
</div>
