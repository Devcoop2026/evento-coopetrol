<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Padron\Tarifa;

/** Asociado ACTIVO: se detecta por documento, sin importar el tipo elegido. Paga tarifa de asociado. */
final class AcompananteAsociado implements TipoAcompanante
{
    public function codigo(): string
    {
        return 'ASOCIADO';
    }

    public function ocupaCupo(): bool
    {
        return true;
    }

    public function aplica(ContextoAcompanante $contexto): bool
    {
        return $contexto->registro?->esActivo() ?? false;
    }

    public function validar(ContextoAcompanante $contexto): void {}

    public function valor(Tarifa $tarifa): int
    {
        return $tarifa->valorAsociado;
    }

    public function observacion(ContextoAcompanante $contexto): ?string
    {
        return null;
    }
}
