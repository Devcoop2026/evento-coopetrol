<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;

/** Acciones del revisor en el panel y la transición que producen. */
enum AccionRevision: string
{
    case Aprobar = 'APROBAR';
    case Rechazar = 'RECHAZAR';
    case Anular = 'ANULAR';

    public static function desde(mixed $accion): self
    {
        return (is_string($accion) ? self::tryFrom($accion) : null) ?? throw new ErrorValidacion('Acción no válida.');
    }

    /** @return list<EstadoInscripcion> estados desde los que se permite la acción */
    public function permitidaDesde(): array
    {
        return match ($this) {
            self::Aprobar, self::Rechazar => [EstadoInscripcion::EnRevision],
            self::Anular => EstadoInscripcion::activos(),
        };
    }

    public function estadoResultante(): EstadoInscripcion
    {
        return match ($this) {
            self::Aprobar => EstadoInscripcion::Confirmado,
            self::Rechazar => EstadoInscripcion::Rechazado,
            self::Anular => EstadoInscripcion::Anulado,
        };
    }

    public function exigeMotivo(): bool
    {
        return $this !== self::Aprobar;
    }

    /** Anular solo lo puede hacer un administrador; el revisor aprueba o rechaza. */
    public function soloAdministrador(): bool
    {
        return $this === self::Anular;
    }

    /** Valida la acción sobre el estado actual y devuelve el nuevo estado y el motivo normalizado. */
    public function aplicar(EstadoInscripcion $actual, mixed $motivo): CambioDeEstado
    {
        if (! in_array($actual, $this->permitidaDesde(), true)) {
            throw new ErrorValidacion('No se puede '.mb_strtolower($this->value)." una inscripción en estado {$actual->value}.");
        }
        $texto = mb_substr(trim(Valores::cadena($motivo)), 0, 500);
        if ($this->exigeMotivo() && $texto === '') {
            throw new ErrorValidacion('Indique el motivo.');
        }

        return new CambioDeEstado($this->estadoResultante(), $texto === '' ? null : $texto);
    }
}
