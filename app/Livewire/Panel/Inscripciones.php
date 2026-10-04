<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Aplicacion\Panel\ConsultarCupos;
use App\Aplicacion\Panel\DetalleInscripcion;
use App\Aplicacion\Panel\ListarInscripciones;
use App\Aplicacion\Panel\RevisarInscripcion;
use App\Dominio\Inscripcion\AccionRevision;
use App\Dominio\Inscripcion\EstadoInscripcion;
use App\Livewire\Concerns\ConConfirmacion;
use App\Livewire\Concerns\ConMensajes;
use App\Livewire\Concerns\ConPermisos;
use App\Livewire\Formato;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Revisión de pagos: listado con filtros y detalle de cada inscripción con sus personas y soportes. El revisor aprueba
 * o rechaza el pago; anular es solo del administrador. Tras cada acción se vuelve al listado actualizado.
 */
class Inscripciones extends Component
{
    use ConConfirmacion, ConMensajes, ConPermisos;

    public const ESTADOS = [
        'PREINSCRITO' => ['Preinscrito', 'pendiente'], 'EN_REVISION' => ['En revisión', 'revision'], 'RECHAZADO' => ['Rechazado', 'rechazado'],
        'CONFIRMADO' => ['Confirmado', 'confirmado'], 'CANCELADO' => ['Cancelado', 'inactivo'], 'ANULADO' => ['Anulado', 'inactivo'],
    ];

    private const TEXTOS = [
        'APROBAR' => ['titulo' => '¿Aprobar el pago?', 'mensaje' => 'Confirma que el pago fue verificado. La inscripción quedará confirmada.', 'aceptar' => 'Sí, aprobar', 'tono' => 'normal'],
        'RECHAZAR' => ['titulo' => '¿Rechazar el soporte?', 'mensaje' => 'El asociado verá el motivo y podrá cargar un nuevo soporte.', 'aceptar' => 'Sí, rechazar', 'tono' => 'aviso'],
        'ANULAR' => ['titulo' => '¿Anular la inscripción?', 'mensaje' => 'Se liberarán sus cupos. Esta acción no se puede deshacer.', 'aceptar' => 'Sí, anular', 'tono' => 'peligro'],
    ];

    private const RESULTADOS = [
        'APROBAR' => ['titulo' => 'Pago aprobado', 'mensaje' => 'La inscripción quedó confirmada.', 'tono' => 'exito'],
        'RECHAZAR' => ['titulo' => 'Soporte rechazado', 'mensaje' => 'El asociado verá el motivo y podrá cargar un nuevo soporte.', 'tono' => 'aviso'],
        'ANULAR' => ['titulo' => 'Inscripción anulada', 'mensaje' => 'Sus cupos quedaron liberados.', 'tono' => 'info'],
    ];

    public string $estado = '';

    public string $agencia = '';

    public string $busqueda = '';

    /** Detalle abierto (DetalleInscripcion). */
    #[Locked]
    public ?array $detalle = null;

    public string $motivo = '';

    /** Referencia de la fila que se resalta tras una revisión. */
    #[Locked]
    public ?string $resaltada = null;

    #[On('cupos-actualizados')]
    public function actualizar(): void {}

    public function filtrar(): void
    {
        $this->resaltada = null;
    }

    public function abrir(int $id): void
    {
        $detalle = $this->intentar('detalle', fn () => app(DetalleInscripcion::class)->ejecutar($id));
        if ($detalle) {
            $this->detalle = $detalle;
            $this->motivo = '';
            $this->mensaje('revision');
        } else {
            $this->dispatch('notificar', titulo: 'No fue posible abrir la inscripción', mensaje: $this->mensajes['detalle']['texto'] ?? '', tono: 'aviso');
        }
    }

    public function cerrar(): void
    {
        $this->detalle = null;
    }

    public function pedirRevision(string $accion): void
    {
        if (! $this->detalle || ! isset(self::TEXTOS[$accion])) {
            return;
        }
        $detalles = [['Referencia', $this->detalle['referencia']], ['Titular', $this->detalle['nombre_titular']], ['Total', Formato::pesos($this->detalle['total'])]];
        if (trim($this->motivo) !== '') {
            $detalles[] = ['Motivo', trim($this->motivo)];
        }
        $this->pedirConfirmacion('revisar', [...self::TEXTOS[$accion], 'detalles' => $detalles, 'cancelar' => 'Volver'], [$accion]);
    }

    protected function revisar(string $accion): void
    {
        $resultado = $this->intentar('revision', function () use ($accion) {
            if (AccionRevision::desde($accion)->soloAdministrador()) {
                $this->exigirAdministrador('anular inscripción');
            }

            return app(RevisarInscripcion::class)->ejecutar($this->detalle['id'], $accion, $this->motivo, $this->usuario()->usuario, $this->rol());
        });
        if (! $resultado) {
            return; // el detalle sigue abierto para corregir (p. ej. el motivo)
        }
        $this->detalle = null;
        $this->resaltada = $resultado['referencia'];
        $r = self::RESULTADOS[$accion];
        $this->dispatch('notificar', titulo: $r['titulo'], mensaje: "{$resultado['referencia']} · {$resultado['nombre_titular']}. {$r['mensaje']}", tono: $r['tono']);
        $this->dispatch('cupos-actualizados');
    }

    /** Acciones disponibles para el estado actual y el rol. */
    protected function accionesPermitidas(): array
    {
        if (! $this->detalle) {
            return [];
        }
        $estado = EstadoInscripcion::from($this->detalle['estado']);

        return array_values(array_map(fn (AccionRevision $a) => $a->value, array_filter(AccionRevision::cases(),
            fn (AccionRevision $a) => in_array($estado, $a->permitidaDesde(), true) && (! $a->soloAdministrador() || $this->esAdministrador()))));
    }

    public function render(ListarInscripciones $listar, ConsultarCupos $cupos, ConfiguracionEvento $configuracion): View
    {
        return view('livewire.panel.inscripciones', [
            'filas' => $listar->ejecutar(['estado' => $this->estado ?: null, 'agencia' => $this->agencia ?: null, 'busqueda' => $this->busqueda]),
            'agencias' => array_column($cupos->ejecutar(), 'agencia'),
            'etiquetas' => array_column($configuracion->camposSoporte, 'etiqueta', 'id'),
            'acciones' => $this->accionesPermitidas(),
        ]);
    }
}
