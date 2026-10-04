{{-- Autorización de tratamiento de datos (data/habeas_data.json). $modelo: propiedad de la casilla; $imagen: modelo de la casilla opcional de uso de imagen. --}}
@php $h = $evento['habeasData'] ?? []; @endphp
<div class="habeas">
  <details class="habeas__texto">
    <summary class="habeas__titulo">{{ ($h['titulo'] ?? 'Autorización para el tratamiento de datos personales') }} (Ley 1581 de 2012)</summary>
    <div class="habeas__cuerpo">
      @foreach (array_filter([...($h['texto'] ?? []), $h['declaracion_acompanantes'] ?? null, ...($h['imagen']['texto'] ?? [])]) as $parrafo)
        <p>{{ $parrafo }}</p>
      @endforeach
      @if (! empty($h['enlace_politica']))
        <p><a href="{{ $h['enlace_politica'] }}" target="_blank" rel="noopener">Consulte la Política de Tratamiento de Datos Personales</a></p>
      @endif
      <p class="nota">Versión {{ $h['version'] ?? '' }}</p>
    </div>
  </details>
  <label class="casilla">
    <input type="checkbox" id="{{ $id }}" required wire:model.live="{{ $modelo }}"
           @if (in_array($modelo, $invalidos, true)) aria-invalid="true" @endif>
    <span>{{ $h['texto_casilla'] ?? 'Acepto la autorización para el tratamiento de mis datos personales' }}</span>
  </label>
  @if (isset($imagen) && ! empty($h['imagen']['texto_casilla']))
    <label class="casilla casilla--opcional">
      <input type="checkbox" wire:model="{{ $imagen }}">
      <span>{{ $h['imagen']['texto_casilla'] }}</span>
    </label>
  @endif
</div>
