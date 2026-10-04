<?php

namespace App\Livewire\Portal;

use App\Aplicacion\Panel\ConsultarCupos;
use App\Dominio\Padron\RepositorioPadron;
use App\Dominio\Padron\Tarifa;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** "Ver tarifas y cupos por evento": tarifas y cupos disponibles; se actualiza cada 30 segundos. */
class TablaTarifas extends Component
{
    #[On('cupos-actualizados')]
    public function actualizar(): void {}

    public function render(RepositorioPadron $padron, ConsultarCupos $cupos): View
    {
        return view('livewire.portal.tabla-tarifas', [
            'tarifas' => array_map(fn (Tarifa $t) => $t->aArreglo(), $padron->tarifas()),
            'cupos' => $cupos->porAgencia(),
        ]);
    }
}
