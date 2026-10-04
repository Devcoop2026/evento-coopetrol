<?php

namespace App\Livewire\Portal;

use App\Aplicacion\Panel\ConsultarCupos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Valores y cupos disponibles del evento elegido; se actualiza cada 30 segundos y tras cada inscripción. */
class ContadorCupos extends Component
{
    public string $agencia = '';

    public int $valorAsociado = 0;

    public int $valorInvitado = 0;

    #[On('cupos-actualizados')]
    public function actualizar(): void {}

    public function render(ConsultarCupos $cupos): View
    {
        return view('livewire.portal.contador-cupos', ['cupo' => $cupos->porAgencia()[$this->agencia] ?? null]);
    }
}
