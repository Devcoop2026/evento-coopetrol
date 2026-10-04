<?php

namespace App\Aplicacion\Inscripciones;

use App\Aplicacion\Simulacion\SimularInscripcion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/**
 * Caso de uso: cambiar los acompañantes y/o el evento de una inscripción pendiente de pago. Recalcula el total con las
 * tarifas vigentes y vuelve a validar duplicados y cupos.
 */
final class ModificarInscripcion
{
    public function __construct(
        private readonly AutenticarInscripcion $autenticar,
        private readonly SimularInscripcion $simular,
        private readonly PoliticaInscripcion $politica,
        private readonly RepositorioInscripciones $repositorio,
        private readonly UnidadDeTrabajo $unidad,
        private readonly PresentadorInscripcion $presentador,
        private readonly Reloj $reloj,
    ) {}

    /** @param  array{referencia?: mixed, documento?: mixed, fechaExpedicion?: mixed, agenciaEvento?: mixed, acompanantes?: mixed}  $datos */
    public function ejecutar(array $datos): array
    {
        $inscripcion = $this->autenticar->ejecutar($datos);
        $inscripcion->estado->exigirEditable('modificarla');
        $this->politica->validarPeriodo();
        $simulacion = $this->simular->ejecutar([
            'documento' => $datos['documento'] ?? null,
            'fechaExpedicion' => $datos['fechaExpedicion'] ?? null,
            'acompanantes' => $datos['acompanantes'] ?? [],
            'agenciaEvento' => ($datos['agenciaEvento'] ?? null) ?: $inscripcion->agencia,
        ]);
        $this->unidad->ejecutar(function () use ($inscripcion, $simulacion) {
            $this->politica->validarDisponibilidad($simulacion, $inscripcion->id);
            $this->repositorio->guardarPersonas($inscripcion->id, $simulacion->personas());
            $this->repositorio->actualizarLiquidacion($inscripcion->id, $simulacion->total(), $simulacion->agenciaEvento(), $this->reloj->ahora());
        });

        return $this->presentador->publico($this->repositorio->porId($inscripcion->id));
    }
}
