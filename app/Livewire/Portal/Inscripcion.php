<?php

namespace App\Livewire\Portal;

use App\Aplicacion\Inscripciones\InscribirConPago;
use App\Aplicacion\Inscripciones\Preinscribir;
use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\ErrorValidacion;
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
 * Módulos de inscripción del portal:
 *   PSE      1. identificarse  2. evento  3. acompañantes  4. simular -> preinscribirse (no ocupa cupo)
 *   AGENCIA  los mismos pasos y, debajo del resumen, el pago hecho en la agencia: crea la inscripción en revisión.
 * Tras una inscripción exitosa el formulario se limpia (equipos compartidos en las agencias) y queda la confirmación.
 */
class Inscripcion extends Component
{
    use ConConfirmacion, ConFormularioPago, ConMensajes;

    #[Locked]
    public array $evento = [];

    #[Locked]
    public ?string $modulo = null;

    // Paso 1: identificación
    public string $documento = '';

    public string $fechaExpedicion = '';

    public bool $aceptaDatos = false;

    public bool $aceptaImagen = false;

    /** Asociado validado: {nombre, agencia, fechaActualizacion}. */
    #[Locked]
    public ?array $asociado = null;

    /** Documento y fecha validados en el paso 1 (los que usan la simulación y la inscripción). */
    #[Locked]
    public ?array $identidad = null;

    // Pasos 2 y 3
    public string $agenciaEvento = '';

    public string $modalidad = 'SOLO';

    /** @var list<array{documento: string, nombre: string, tipo: string}> */
    public array $acompanantes = [];

    /** Resultado de la simulación y los datos con que se calculó: {resultado, agenciaEvento, acompanantes}. */
    #[Locked]
    public ?array $simulacion = null;

    /** Inscripción recién registrada (confirmación). */
    #[Locked]
    public ?array $confirmada = null;

    /** Credenciales para "Ya pagué" / "Ver mi inscripción": {referencia, documento, fechaExpedicion}. */
    #[Locked]
    public ?array $credenciales = null;

    #[Locked]
    public bool $tieneInscripcionExistente = false;

    public function mount(array $evento): void
    {
        $this->evento = $evento;
        $this->pago->reiniciar('AGENCIA');
    }

    #[On('modulo-elegido')]
    public function elegirModulo(string $modulo): void
    {
        $this->modulo = $modulo;
        $this->ocultarResultados();
        $this->confirmada = null;
    }

    // ---- Paso 1: identificación ----

    public function validar(): void
    {
        $this->ocultarResultados();
        $this->invalidos = array_values(array_filter([
            trim($this->documento) === '' ? 'documento' : null,
            $this->fechaExpedicion === '' ? 'fechaExpedicion' : null,
        ]));
        if ($this->invalidos) {
            $this->mensaje('asociado', 'Ingrese su documento y la fecha de expedición.', 'error');

            return;
        }
        if (! $this->aceptaDatos) {
            $this->invalidos = ['aceptaDatos'];
            $this->mensaje('asociado', 'Debe aceptar la autorización para el tratamiento de datos personales.', 'error');

            return;
        }
        $identidad = $this->intentar('asociado', fn () => $this->conIdentidad('identificar', $this->documento,
            fn () => app(IdentificarAsociado::class)->ejecutar($this->documento, $this->fechaExpedicion)));
        if (! $identidad) {
            $this->identidad = $this->asociado = null;

            return;
        }
        $this->identidad = ['documento' => trim($this->documento), 'fechaExpedicion' => $this->fechaExpedicion];
        $this->asociado = [
            'nombre' => $identidad->asociado->nombre,
            'agencia' => $identidad->asociado->agencia,
            'fechaActualizacion' => $identidad->asociado->fechaActualizacion,
        ];
        // En un evento compartido, el evento al que asiste su agencia.
        $this->agenciaEvento = $identidad->tarifa->agencia;
        $this->mensaje('asociado', 'Asociado validado correctamente.', 'ok');
    }

    /** Si cambia el documento, la fecha o la autorización después de validar, hay que validar de nuevo. */
    public function updated(string $propiedad): void
    {
        if (in_array($propiedad, ['documento', 'fechaExpedicion', 'aceptaDatos'], true) && $this->identidad) {
            $this->identidad = $this->asociado = null;
            $this->ocultarResultados();
            $this->mensaje('asociado');
        }
        if ($propiedad === 'agenciaEvento' || str_starts_with($propiedad, 'acompanantes')) {
            $this->ocultarResultados();
        }
    }

    // ---- Pasos 2 y 3: evento y acompañantes ----

    public function updatedModalidad(): void
    {
        if ($this->modalidad === 'ACOMPAÑADO' && ! $this->acompanantes) {
            $this->agregarAcompanante();
        }
        $this->ocultarResultados();
    }

    public function agregarAcompanante(): void
    {
        if (count($this->acompanantes) < $this->evento['maxAcompanantes']) {
            $this->acompanantes[] = ['documento' => '', 'nombre' => '', 'tipo' => 'NO_ASOCIADO'];
        }
        $this->ocultarResultados();
    }

    public function quitarAcompanante(int $indice): void
    {
        unset($this->acompanantes[$indice]);
        $this->acompanantes = array_values($this->acompanantes);
        if (! $this->acompanantes) {
            $this->modalidad = 'SOLO';
        }
        $this->ocultarResultados();
    }

    // ---- Paso 4: simulación ----

    public function simular(): void
    {
        if (! $this->identidad) {
            $this->mensaje('simulacion', 'Primero valide su documento de asociado.', 'error');

            return;
        }
        $acompanantes = $this->modalidad === 'ACOMPAÑADO' ? $this->acompanantes : [];
        if (! $this->acompanantesCompletos('acompanantes', $acompanantes)) {
            $this->mensaje('simulacion', 'Cada acompañante debe tener número de documento (solo números) y nombres y apellidos completos.', 'error');

            return;
        }
        $datos = [...$this->identidad, 'agenciaEvento' => $this->agenciaEvento, 'acompanantes' => $acompanantes];
        $simulacion = $this->intentar('simulacion', fn () => $this->conIdentidad('simular', $this->identidad['documento'],
            fn () => app(SimularInscripcion::class)->ejecutar($datos)));
        if (! $simulacion) {
            return;
        }
        $this->simulacion = ['resultado' => $simulacion->aArreglo(), 'agenciaEvento' => $this->agenciaEvento, 'acompanantes' => $acompanantes];
        $this->tieneInscripcionExistente = false;
        $this->mensaje('preinscripcion');
        if ($this->modulo === 'AGENCIA') {
            // El pago se registra en el mismo paso, justo debajo del resumen.
            $this->pago->reiniciar('AGENCIA', $simulacion->total());
            $this->mensaje('soporte');
        }
        $this->dispatch('desplazar', selector: '#resultado');
    }

    /** Marca los acompañantes sin documento válido o sin nombres y apellidos completos. */
    protected function acompanantesCompletos(string $propiedad, array $lista): bool
    {
        $invalidos = [];
        foreach ($lista as $i => $a) {
            if (! preg_match('/^\d{4,15}$/D', preg_replace('/[\s.,-]/u', '', (string) ($a['documento'] ?? '')))) {
                $invalidos[] = "{$propiedad}.{$i}.documento";
            }
            $palabras = array_filter(preg_split('/\s+/u', trim((string) ($a['nombre'] ?? ''))), fn ($p) => mb_strlen($p) >= 2);
            if (count($palabras) < 2) {
                $invalidos[] = "{$propiedad}.{$i}.nombre";
            }
        }
        $this->invalidos = $invalidos;

        return ! $invalidos;
    }

    // ---- Módulo PSE: preinscripción ----

    public function preinscribir(): void
    {
        if (! $this->identidad || ! $this->simulacion) {
            return;
        }
        $r = $this->simulacion['resultado'];
        $asociados = 1 + $r['resumen']['acompanantesAsociados'] + $r['resumen']['coopetrolitos'];
        $this->pedirConfirmacion('registrarPreinscripcion', [
            'titulo' => 'Confirmar preinscripción',
            'mensaje' => 'Se registrará su preinscripción con el valor calculado. El cupo se asigna cuando registre el pago, según disponibilidad.',
            'detalles' => [
                ['Evento', $r['agenciaEvento']],
                ['Cupos que ocupará al pagar', "{$asociados} (los no asociados no ocupan cupo)"],
                ['Personas', (string) $r['resumen']['personas']],
                ['Total a pagar', Formato::pesos($r['resumen']['total'])],
            ],
            'aceptar' => 'Sí, preinscribirme',
        ]);
    }

    protected function registrarPreinscripcion(): void
    {
        $datos = [
            ...$this->identidad,
            'agenciaEvento' => $this->simulacion['agenciaEvento'],
            'acompanantes' => $this->simulacion['acompanantes'],
            'autorizaDatos' => true,
            'autorizaImagen' => $this->aceptaImagen,
        ];
        $error = null;
        $inscripcion = $this->intentar('preinscripcion', fn () => $this->conIdentidad('preinscribir', $this->identidad['documento'],
            fn () => app(Preinscribir::class)->ejecutar($datos, request()->ip())), $error);
        $inscripcion ? $this->mostrarConfirmacion($inscripcion) : $this->ofrecerInscripcionExistente($error);
    }

    // ---- Módulo agencia: inscripción y pago en un solo paso ----

    public function registrarPagoAgencia(): void
    {
        $this->invalidos = $this->pago->obligatoriosVacios($this->evento['camposSoporte'], (bool) $this->comprobante);
        if ($this->invalidos) {
            $this->mensaje('soporte', 'Complete los campos obligatorios (*).', 'error');

            return;
        }
        if (! $this->identidad || ! $this->simulacion) {
            $this->mensaje('soporte', 'Primero valide su documento y simule el valor a pagar.', 'error');

            return;
        }
        $r = $this->simulacion['resultado'];
        $this->pedirConfirmacion('registrarInscripcionConPago', [
            'titulo' => 'Confirmar inscripción y pago',
            'mensaje' => 'Se creará su inscripción con el pago en agencia registrado. El área encargada lo verificará con la agencia.',
            'detalles' => [
                ['Evento', $r['agenciaEvento']],
                ['Personas', (string) $r['resumen']['personas']],
                ['Total a pagar', Formato::pesos($r['resumen']['total'])],
                ['Pagó en la agencia', $this->pago->agenciaPago],
                ['Valor pagado', Formato::pesos((int) preg_replace('/\D/', '', $this->pago->valorPagado))],
            ],
            'aceptar' => 'Sí, inscribirme',
        ]);
    }

    protected function registrarInscripcionConPago(): void
    {
        $datos = [
            ...$this->identidad,
            'agenciaEvento' => $this->simulacion['agenciaEvento'],
            'acompanantes' => $this->simulacion['acompanantes'],
            ...$this->pago->datos($this->evento['camposSoporte'], $this->comprobante instanceof TemporaryUploadedFile ? $this->comprobante : null),
            'medioPago' => 'AGENCIA',
            'autorizaDatos' => true,
            'autorizaImagen' => $this->aceptaImagen,
        ];
        $error = null;
        $inscripcion = $this->intentar('soporte', fn () => $this->conIdentidad('inscribir-con-pago', $this->identidad['documento'],
            fn () => app(InscribirConPago::class)->ejecutar($datos, request()->ip())), $error);
        if (! $inscripcion) {
            $this->ofrecerInscripcionExistente($error);

            return;
        }
        $this->quitarArchivo();
        $this->pago->reiniciar('AGENCIA');
        $this->mostrarConfirmacion($inscripcion);
    }

    // ---- Confirmación ----

    /** Ya tiene una inscripción activa: se ofrece ir a ella (pagar, modificar o cancelar). */
    private function ofrecerInscripcionExistente(?ErrorValidacion $error): void
    {
        $existente = $error?->datos['referenciaExistente'] ?? null;
        if ($existente) {
            $this->credenciales = ['referencia' => $existente, ...$this->identidad];
            $this->tieneInscripcionExistente = true;
        }
    }

    private function mostrarConfirmacion(array $inscripcion): void
    {
        $this->credenciales = ['referencia' => $inscripcion['referencia'], ...$this->identidad];
        $this->confirmada = $inscripcion;
        $this->dispatch('recordar-documento', documento: $this->identidad['documento']);
        $this->dispatch('cupos-actualizados');
        $this->limpiarFormulario();
        $this->dispatch('desplazar', selector: '#confirmacion');
    }

    /**
     * Tras una inscripción exitosa se borran documento, fecha, autorizaciones y acompañantes: en equipos compartidos,
     * como los de las agencias, no quedan a la vista de la siguiente persona. La confirmación sigue visible.
     */
    private function limpiarFormulario(): void
    {
        $this->reset(['documento', 'fechaExpedicion', 'aceptaDatos', 'aceptaImagen', 'asociado', 'identidad', 'agenciaEvento',
            'modalidad', 'acompanantes', 'simulacion', 'mensajes', 'invalidos', 'tieneInscripcionExistente']);
    }

    private function ocultarResultados(): void
    {
        $this->simulacion = null;
        $this->confirmada = null;
        $this->tieneInscripcionExistente = false;
        $this->mensaje('preinscripcion');
        $this->mensaje('simulacion');
    }

    /** "Ya pagué: registrar el pago", "Ver mi inscripción" o "Ver mi inscripción y pagar". */
    public function verInscripcion(): void
    {
        if ($this->credenciales) {
            $this->dispatch('ver-inscripcion', credenciales: $this->credenciales)->to(Portal::class);
        }
    }

    public function render(): View
    {
        return view('livewire.portal.inscripcion', [
            'tarifa' => collect($this->evento['tarifas'])->firstWhere('agencia', $this->agenciaEvento),
            'titulo' => Portal::MODULOS[$this->modulo ?? 'PSE'],
        ]);
    }
}
