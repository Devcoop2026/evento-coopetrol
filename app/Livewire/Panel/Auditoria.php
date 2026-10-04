<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Gestion\ConsultarAuditoria;
use App\Livewire\Concerns\ConPermisos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/** Registro de cambios: últimos 100 cambios manuales hechos desde el panel (solo ADMINISTRADOR). */
class Auditoria extends Component
{
    use ConPermisos;

    public function mount(): void
    {
        $this->exigirAdministrador('ver auditoría');
    }

    #[On('auditoria-actualizada')]
    public function actualizar(): void {}

    public function render(ConsultarAuditoria $consultar): View
    {
        return view('livewire.panel.auditoria', ['filas' => $consultar->ejecutar(100)]);
    }
}
