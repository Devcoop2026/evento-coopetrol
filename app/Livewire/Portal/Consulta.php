<?php

namespace App\Livewire\Portal;

use App\Aplicacion\Inscripciones\CancelarInscripcion;
use App\Aplicacion\Inscripciones\ConsultarInscripcion;
use App\Aplicacion\Inscripciones\ModificarInscripcion;
use App\Aplicacion\Inscripciones\RegistrarPago;
use App\Livewire\Concerns\ConConfirmacion;
use App\Livewire\Concerns\ConFormularioPago;
use App\Livewire\Concerns\ConMensajes;
use App\Livewire\Formato;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * "Consulte su inscripción": con documento y fecha de expedición el asociado ve el estado de su inscripción y, si está
 * pendiente de pago (preinscrita o con el soporte rechazado), puede registrar el pago, modificarla o cancelarla.
 */
class Consulta extends Component
{
    use ConConfirmacion, ConFormularioPago, ConMensajes;

    public const ESTADOS = [
        'PREINSCRITO' => ['texto' => 'Preinscrito · pendiente de pago', 'clase' => 'pendiente'],
        'EN_REVISION' => ['texto' => 'Soporte en revisión', 'clase' => 'revision'],
        'RECHAZADO' => ['texto' => 'Soporte rechazado', 'clase' => 'rechazado'],
        'CONFIRMADO' => ['texto' => 'Inscripción confirmada', 'clase' => 'confirmado'],
        'CANCELADO' => ['texto' => 'Cancelada', 'clase' => 'inactivo'],
        'ANULADO' => ['texto' => 'Anulada', 'clase' => 'inactivo'],
    ];

    #[Locked]
    public array $evento = [];

    public string $documento = '';

    public string $fechaExpedicion = '';

    /** Credenciales con que se consultó: {documento, fechaExpedicion, referencia}. */
    #[Locked]
    public ?array $credenciales = null;

    #[Locked]
    public ?array $inscripcion = null;

    // Modificación
    public bool $editando = false;

    public string $agenciaModificar = '';

    /** @var list<array{documento: string, nombre: string, tipo: string}> */
    public array $acompanantesModificar = [];

    public function mount(array $evento): void
    {
        $this->evento = $evento;
        $this->pago->reiniciar('PSE');
    }

    #[On('consultar-inscripcion')]
    public function abrir(array $credenciales): void
    {
        $this->documento = (string) ($credenciales['documento'] ?? '');
        $this->fechaExpedicion = (string) ($credenciales['fechaExpedicion'] ?? '');
        $this->consultar();
    }

    public function consultar(): void
    {
        $this->invalidos = array_values(array_filter([
            trim($this->documento) === '' ? 'documento' : null,
            $this->fechaExpedicion === '' ? 'fechaExpedicion' : null,
        ]));
        if ($this->invalidos) {
            $this->mensaje('consulta', 'Ingrese su número de documento y la fecha de expedición.', 'error');

            return;
        }
        $datos = ['documento' => trim($this->documento), 'fechaExpedicion' => $this->fechaExpedicion];
        $inscripcion = $this->intentar('consulta', fn () => $this->conIdentidad('consultar', $datos['documento'],
            fn () => app(ConsultarInscripcion::class)->ejecutar($datos)));
        if (! $inscripcion) {
            $this->inscripcion = $this->credenciales = null;
            $this->editando = false;

            return;
        }
        $this->credenciales = [...$datos, 'referencia' => $inscripcion['referencia']];
        $this->dispatch('recordar-documento', documento: $datos['documento']);
        $this->pago->reiniciar('PSE', $inscripcion['total']);
        $this->mostrar($inscripcion);
    }

    private function mostrar(array $inscripcion): void
    {
        $this->inscripcion = $inscripcion;
        $this->editando = false;
        $this->mensaje('estado');
    }

    // ---- Cancelar ----

    public function cancelar(): void
    {
        if (! $this->inscripcion) {
            return;
        }
        $this->pedirConfirmacion('cancelarInscripcion', [
            'titulo' => '¿Cancelar su preinscripción?',
            'mensaje' => 'Se liberarán sus cupos y otra persona podrá tomarlos. Podrá inscribirse de nuevo si aún hay cupos disponibles.',
            'detalles' => [['Referencia', $this->inscripcion['referencia']], ['Evento', $this->inscripcion['agencia']]],
            'aceptar' => 'Sí, cancelar',
            'cancelar' => 'No, conservarla',
            'tono' => 'peligro',
        ]);
    }

    protected function cancelarInscripcion(): void
    {
        $inscripcion = $this->intentar('estado', fn () => $this->conIdentidad('cancelar', $this->credenciales['documento'],
            fn () => app(CancelarInscripcion::class)->ejecutar($this->credenciales)));
        if ($inscripcion) {
            $this->mostrar($inscripcion);
            $this->dispatch('cupos-actualizados');
        }
    }

    // ---- Modificar acompañantes y evento ----

    public function modificar(): void
    {
        if (! $this->inscripcion) {
            return;
        }
        $this->agenciaModificar = $this->inscripcion['agencia'];
        $this->acompanantesModificar = array_values(array_map(fn (array $p) => [
            'documento' => $p['documento'], 'nombre' => $p['nombre'], 'tipo' => $p['tipo'] === 'COOPETROLITO' ? 'COOPETROLITO' : 'NO_ASOCIADO',
        ], array_filter($this->inscripcion['personas'], fn (array $p) => $p['tipo'] !== 'TITULAR')));
        $this->invalidos = [];
        $this->mensaje('modificar');
        $this->editando = true;
        $this->dispatch('desplazar', selector: '#form-modificar');
    }

    public function descartarModificacion(): void
    {
        $this->editando = false;
    }

    public function agregarAcompananteModificar(): void
    {
        if (count($this->acompanantesModificar) < $this->evento['maxAcompanantes']) {
            $this->acompanantesModificar[] = ['documento' => '', 'nombre' => '', 'tipo' => 'NO_ASOCIADO'];
        }
    }

    public function quitarAcompananteModificar(int $indice): void
    {
        unset($this->acompanantesModificar[$indice]);
        $this->acompanantesModificar = array_values($this->acompanantesModificar);
    }

    public function guardarModificacion(): void
    {
        if (! $this->credenciales) {
            return;
        }
        $invalidos = [];
        foreach ($this->acompanantesModificar as $i => $a) {
            if (! preg_match('/^\d{4,15}$/D', preg_replace('/[\s.,-]/u', '', $a['documento']))) {
                $invalidos[] = "acompanantesModificar.{$i}.documento";
            }
            if (count(array_filter(preg_split('/\s+/u', trim($a['nombre'])), fn ($p) => mb_strlen($p) >= 2)) < 2) {
                $invalidos[] = "acompanantesModificar.{$i}.nombre";
            }
        }
        $this->invalidos = $invalidos;
        if ($invalidos) {
            $this->mensaje('modificar', 'Cada acompañante debe tener número de documento (solo números) y nombres y apellidos completos.', 'error');

            return;
        }
        $anterior = $this->inscripcion['total'];
        $datos = [...$this->credenciales, 'agenciaEvento' => $this->agenciaModificar, 'acompanantes' => $this->acompanantesModificar];
        $inscripcion = $this->intentar('modificar', fn () => $this->conIdentidad('modificar', $this->credenciales['documento'],
            fn () => app(ModificarInscripcion::class)->ejecutar($datos)));
        if (! $inscripcion) {
            return;
        }
        $this->dispatch('cupos-actualizados');
        $this->pago->valorPagado = (string) $inscripcion['total'];
        $this->mostrar($inscripcion);
        $this->mensaje('estado', $anterior === $inscripcion['total']
            ? 'Cambios guardados.'
            : 'Cambios guardados. Nuevo total: '.Formato::pesos($inscripcion['total']).' (antes '.Formato::pesos($anterior).').', 'ok');
    }

    // ---- Registrar el pago ----

    public function enviarSoporte(): void
    {
        if (! $this->credenciales) {
            return;
        }
        $this->invalidos = $this->pago->obligatoriosVacios($this->evento['camposSoporte'], (bool) $this->comprobante);
        if ($this->invalidos) {
            $this->mensaje('soporte', 'Complete los campos obligatorios (*).', 'error');

            return;
        }
        if (! $this->pago->aceptaDatos) {
            $this->invalidos = ['pago.aceptaDatos'];
            $this->mensaje('soporte', 'Debe aceptar la autorización para el tratamiento de datos personales.', 'error');

            return;
        }
        $datos = [...$this->credenciales, ...$this->pago->datos($this->evento['camposSoporte'], $this->comprobante instanceof TemporaryUploadedFile ? $this->comprobante : null), 'autorizaDatos' => true];
        $inscripcion = $this->intentar('soporte', fn () => $this->conIdentidad('soporte', $this->credenciales['documento'],
            fn () => app(RegistrarPago::class)->ejecutar($datos)));
        if (! $inscripcion) {
            return;
        }
        $this->quitarArchivo();
        $this->pago->reiniciar('PSE', $inscripcion['total']);
        $this->dispatch('cupos-actualizados');
        $this->mostrar($inscripcion);
        $this->mensaje('soporte', 'Soporte enviado correctamente.', 'ok');
        $this->mensaje('estado', 'Soporte enviado correctamente.', 'ok');
        $this->dispatch('desplazar', selector: '#estado-inscripcion');
    }

    public function render(): View
    {
        return view('livewire.portal.consulta');
    }
}
