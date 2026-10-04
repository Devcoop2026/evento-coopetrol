@use('App\Livewire\Formato')
<div wire:poll.30s.visible>
  <div class="ficha">
    <div><span class="ficha__label">Valor asociado</span><strong>{{ Formato::pesos($valorAsociado) }}</strong></div>
    <div><span class="ficha__label">Valor invitado</span><strong>{{ Formato::pesos($valorInvitado) }}</strong></div>
    <div><span class="ficha__label">Cupos disponibles</span>
      <strong class="contador{{ ($cupo['disponibles'] ?? null) === 0 ? ' agotado' : '' }}">{{ $cupo ? Formato::numero($cupo['disponibles']).' de '.Formato::numero($cupo['cupos']) : '—' }}</strong></div>
  </div>
  @if (($cupo['disponibles'] ?? null) === 0)
    <p class="aviso aviso--rechazado">Lo sentimos, se ha superado el límite de cupos disponibles para este evento.</p>
  @endif
</div>
