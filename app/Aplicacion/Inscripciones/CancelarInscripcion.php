<?php

namespace App\Aplicacion\Inscripciones;

use App\Dominio\Compartido\Reloj;
use App\Dominio\Inscripcion\EstadoInscripcion;
use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Caso de uso: el asociado cancela su inscripción pendiente de pago (libera la persona para inscribirse de nuevo). */
final class CancelarInscripcion
{
    public function __construct(
        private readonly AutenticarInscripcion $autenticar,
        private readonly RepositorioInscripciones $repositorio,
        private readonly PresentadorInscripcion $presentador,
        private readonly Reloj $reloj,
    ) {}

    /** @param  array{referencia?: mixed, documento?: mixed, fechaExpedicion?: mixed}  $credenciales */
    public function ejecutar(array $credenciales): array
    {
        $inscripcion = $this->autenticar->ejecutar($credenciales);
        $inscripcion->estado->exigirEditable('cancelarla');
        $this->repositorio->cambiarEstado($inscripcion->id, EstadoInscripcion::Cancelado, 'Cancelada por el asociado', $this->reloj->ahora());

        return $this->presentador->publico($this->repositorio->porId($inscripcion->id));
    }
}
