@use('App\Livewire\Formato')
<section class="kpis" aria-label="Resumen" wire:poll.30s.visible>
  @foreach ($kpis as $titulo => $valor)
    <div class="kpi"><span class="kpi__valor">{{ Formato::numero($valor) }}</span><span class="kpi__label">{{ $titulo }}</span></div>
  @endforeach
</section>
