<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Compartido\ErrorValidacion;

/** Regla de cupos: una inscripción solo se acepta si los cupos que requiere no superan los disponibles del evento. */
final class PoliticaCupos
{
    /** `sugerencia` se añade al final del mensaje (p. ej. qué puede hacer el asociado). */
    public static function exigir(string $agencia, int $requeridos, int $disponibles, string $sugerencia = ''): void
    {
        if ($requeridos <= $disponibles) {
            return;
        }
        throw new ErrorValidacion($disponibles > 0
            ? "Lo sentimos, se ha superado el límite de cupos disponibles: en el evento de {$agencia} quedan {$disponibles} y su inscripción requiere {$requeridos}.{$sugerencia}"
            : "Lo sentimos, se ha superado el límite de cupos disponibles para el evento de {$agencia}.");
    }
}
