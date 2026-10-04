<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\AccionRevision;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Panel\Rol;

/** Caso de uso: aprobar o rechazar el pago (revisor o administrador) o anular la inscripción (solo administrador). */
final class RevisarInscripcion
{
    public function __construct(
        private readonly RepositorioInscripciones $repositorio,
        private readonly DetalleInscripcion $detalle,
        private readonly UnidadDeTrabajo $unidad,
        private readonly Reloj $reloj,
    ) {}

    public function ejecutar(int $id, mixed $accion, mixed $motivo, string $usuario, Rol $rol): array
    {
        $accion = AccionRevision::desde($accion);
        if ($accion->soloAdministrador() && $rol !== Rol::Administrador) {
            throw new PermisoDenegado('anular inscripción');
        }
        $this->unidad->ejecutar(function () use ($id, $accion, $motivo, $usuario) {
            $inscripcion = $this->repositorio->porId($id) ?? throw new ErrorValidacion('Inscripción no encontrada.');
            $cambio = $accion->aplicar($inscripcion->estado, $motivo);
            $this->repositorio->registrarRevision($inscripcion->id, $cambio, $usuario, $this->reloj->ahora());
        });

        return $this->detalle->ejecutar($id);
    }
}
