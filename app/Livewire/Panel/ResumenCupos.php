<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Panel\ConsultarCupos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Indicadores del panel: cupos totales, ocupados, confirmados y disponibles (se actualizan cada 30 segundos). */
class ResumenCupos extends Component
{
    #[On('cupos-actualizados')]
    public function actualizar(): void {}

    public function render(ConsultarCupos $consultar): View
    {
        $cupos = collect($consultar->ejecutar());

        return view('livewire.panel.resumen-cupos', ['kpis' => [
            'Cupos totales' => $cupos->sum('cupos'),
            'Ocupados' => $cupos->sum('ocupados'),
            'Confirmados' => $cupos->sum('confirmados'),
            'Disponibles' => $cupos->sum('disponibles'),
        ]]);
    }
}
