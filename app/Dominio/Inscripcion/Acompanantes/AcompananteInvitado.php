<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Padron\Tarifa;

/** No asociado o asociado inactivo: paga tarifa de invitado y no ocupa cupo. Aplica siempre (último de la lista). */
final class AcompananteInvitado implements TipoAcompanante
{
    public function codigo(): string
    {
        return 'INVITADO';
    }

    public function ocupaCupo(): bool
    {
        return false;
    }

    public function aplica(ContextoAcompanante $contexto): bool
    {
        return true;
    }

    public function validar(ContextoAcompanante $contexto): void {}

    public function valor(Tarifa $tarifa): int
    {
        return $tarifa->valorInvitado;
    }

    public function observacion(ContextoAcompanante $contexto): ?string
    {
        return $contexto->registro ? 'Asociado inactivo: se liquida como invitado' : null;
    }
}
