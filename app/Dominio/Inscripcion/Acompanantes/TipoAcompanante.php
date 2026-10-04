<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Padron\Tarifa;

/**
 * Estrategia de un tipo de acompañante. Para agregar un tipo nuevo basta con implementar esta interfaz y registrarla en
 * ClasificadorAcompanantes (el orden importa: gana el primero que aplica).
 */
interface TipoAcompanante
{
    public function codigo(): string;

    /** ¿Cuenta en el cupo del evento? */
    public function ocupaCupo(): bool;

    public function aplica(ContextoAcompanante $contexto): bool;

    /** Lanza ErrorValidacion si no se cumplen sus condiciones. */
    public function validar(ContextoAcompanante $contexto): void;

    public function valor(Tarifa $tarifa): int;

    public function observacion(ContextoAcompanante $contexto): ?string;
}
