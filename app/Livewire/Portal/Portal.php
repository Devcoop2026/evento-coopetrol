<?php

namespace App\Livewire\Portal;

use App\Aplicacion\Configuracion\ConsultarDatosEvento;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Página del asociado. Dos módulos de inscripción y una consulta:
 *   PSE       simular -> preinscribirse -> pagar en línea -> registrar el CUS (desde la confirmación o la consulta)
 *   AGENCIA   simular -> registrar el pago hecho en la agencia en el mismo paso (crea la inscripción en revisión)
 *   Consulta  ver el estado, registrar el pago, modificar o cancelar.
 * Los módulos son componentes hijos que siguen montados al cambiar de vista (conservan lo digitado).
 */
#[Layout('layouts.portal')]
#[Title('Evento Fin de Año Coopetrol')]
class Portal extends Component
{
    public const MODULOS = [
        'PSE' => [
            'titulo' => 'Inscripción y pago por PSE',
            'desc' => 'Valide su identidad, simule el valor e inscríbase. Luego pague en línea por PSE y registre el número CUS de su transacción.',
        ],
        'AGENCIA' => [
            'titulo' => 'Inscripción y pago en agencia',
            'desc' => 'Valide su identidad, simule el valor y registre el pago que realizó en la agencia. Su inscripción se crea al enviar el pago.',
        ],
    ];

    /** 'inicio', 'inscribir' o 'consulta'. */
    public string $vista = 'inicio';

    public ?string $modulo = null;

    public function elegirModulo(string $codigo): void
    {
        if (! isset(self::MODULOS[$codigo])) {
            return;
        }
        $this->modulo = $codigo;
        $this->vista = 'inscribir';
        $this->dispatch('modulo-elegido', modulo: $codigo)->to(Inscripcion::class);
        $this->dispatch('desplazar', selector: '#vista-inscribir');
    }

    public function irAConsulta(): void
    {
        $this->vista = 'consulta';
        $this->dispatch('desplazar', selector: '#vista-mi');
    }

    /** Enlaces directos (p. ej. para compartir desde una agencia): #pse, #agencia o #consulta. */
    #[On('abrir-enlace')]
    public function abrirEnlace(string $enlace): void
    {
        match ($enlace) {
            '#pse' => $this->elegirModulo('PSE'),
            '#agencia' => $this->elegirModulo('AGENCIA'),
            '#consulta' => $this->irAConsulta(),
            default => null,
        };
    }

    /** "Ya pagué", "Ver mi inscripción": abre la consulta con las credenciales de la inscripción recién hecha. */
    #[On('ver-inscripcion')]
    public function verInscripcion(array $credenciales): void
    {
        $this->vista = 'consulta';
        $this->dispatch('consultar-inscripcion', credenciales: $credenciales)->to(Consulta::class);
        $this->dispatch('desplazar', selector: 'body');
    }

    public function render(ConsultarDatosEvento $datos): View
    {
        return view('livewire.portal.portal', ['evento' => $datos->ejecutar()]);
    }
}
