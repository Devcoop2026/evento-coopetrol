<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Panel\AvisosPanel;
use App\Aplicacion\Seguridad\AutenticadorPanel;
use App\Livewire\Concerns\ConPermisos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Panel de administración. Pestañas: inscripciones (revisión de pagos), cupos y cargas masivas, y para el
 * ADMINISTRADOR la gestión de asociados, Coopetrolitos y usuarios (con el registro de cambios).
 */
#[Layout('layouts.panel')]
#[Title('Panel Evento Coopetrol')]
class Panel extends Component
{
    use ConPermisos;

    public const VISTAS = [
        'inscripciones' => ['Inscripciones', false],
        'asociados' => ['Asociados', true],
        'coopetrolitos' => ['Coopetrolitos', true],
        'usuarios' => ['Usuarios del panel', true],
        'cupos' => ['Cupos y cargas masivas', false],
    ];

    public string $vista = 'inscripciones';

    public function mostrar(string $vista): void
    {
        if (! isset(self::VISTAS[$vista]) || (self::VISTAS[$vista][1] && ! $this->esAdministrador())) {
            return;
        }
        $this->vista = $vista;
    }

    public function salir(AutenticadorPanel $autenticador): void
    {
        $autenticador->cerrar();
        $this->redirectRoute('panel.ingreso');
    }

    public function render(AvisosPanel $avisos): View
    {
        return view('livewire.panel.panel', [
            'usuario' => $this->usuario(),
            'administrador' => $this->esAdministrador(),
            'avisos' => $avisos->ejecutar($this->rol()),
        ]);
    }
}
