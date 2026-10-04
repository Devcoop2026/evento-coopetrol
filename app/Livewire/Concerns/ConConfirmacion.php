<?php

namespace App\Livewire\Concerns;

use Livewire\Attributes\Locked;

/**
 * Diálogo de confirmación con la identidad de Coopetrol (reemplaza window.confirm). La acción pendiente queda en una
 * propiedad bloqueada: el navegador no puede cambiarla. Vista: <x-modal-confirmacion :confirmacion="$confirmacion" />.
 */
trait ConConfirmacion
{
    /** @var array{titulo: string, mensaje: string, detalles: list<array{0: string, 1: string}>, aceptar: string, cancelar: string, tono: string}|null */
    #[Locked]
    public ?array $confirmacion = null;

    #[Locked]
    public ?string $accionPendiente = null;

    #[Locked]
    public array $argumentosPendientes = [];

    /** `tono`: 'normal' (verde), 'peligro' (rojo) o 'aviso' (amarillo). */
    protected function pedirConfirmacion(string $accion, array $opciones, array $argumentos = []): void
    {
        $this->accionPendiente = $accion;
        $this->argumentosPendientes = $argumentos;
        $this->confirmacion = [
            'titulo' => $opciones['titulo'],
            'mensaje' => $opciones['mensaje'] ?? '',
            'detalles' => $opciones['detalles'] ?? [],
            'aceptar' => $opciones['aceptar'] ?? 'Aceptar',
            'cancelar' => $opciones['cancelar'] ?? 'Cancelar',
            'tono' => $opciones['tono'] ?? 'normal',
        ];
    }

    public function confirmarAccion(): void
    {
        $accion = $this->accionPendiente;
        $argumentos = $this->argumentosPendientes;
        $this->cancelarAccion();
        if ($accion && method_exists($this, $accion)) {
            $this->{$accion}(...$argumentos);
        }
    }

    public function cancelarAccion(): void
    {
        $this->confirmacion = null;
        $this->accionPendiente = null;
        $this->argumentosPendientes = [];
    }
}
