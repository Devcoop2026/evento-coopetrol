<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Seguridad\IngresarAlPanel;
use App\Livewire\Concerns\ConMensajes;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Ingreso de los usuarios del panel (10 intentos cada 15 minutos por IP). */
#[Layout('layouts.panel')]
#[Title('Panel Evento Coopetrol')]
class Ingreso extends Component
{
    use ConMensajes;

    public string $usuario = '';

    public string $clave = '';

    public function ingresar(): void
    {
        $sesion = $this->intentar('login', fn () => app(IngresarAlPanel::class)->ejecutar($this->usuario, $this->clave, (string) request()->ip()));
        $this->clave = '';
        if ($sesion) {
            $this->redirectRoute('panel');
        }
    }

    public function render(): View
    {
        return view('livewire.panel.ingreso');
    }
}
