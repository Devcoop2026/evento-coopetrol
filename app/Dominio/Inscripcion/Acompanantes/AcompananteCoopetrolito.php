<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Padron\Tarifa;

/** Hijo del titular, validado contra la base de Coopetrolitos. Paga tarifa de asociado y ocupa cupo. */
final class AcompananteCoopetrolito implements TipoAcompanante
{
    public function codigo(): string
    {
        return 'COOPETROLITO';
    }

    public function ocupaCupo(): bool
    {
        return true;
    }

    public function aplica(ContextoAcompanante $contexto): bool
    {
        return $contexto->solicitado === 'COOPETROLITO';
    }

    public function validar(ContextoAcompanante $contexto): void
    {
        if (! $contexto->coopetrolito()?->esHijoDe($contexto->titular)) {
            throw new ErrorValidacion("{$contexto->etiqueta}: el documento {$contexto->documento} no figura como Coopetrolito vinculado a su cuenta de asociado.");
        }
    }

    public function valor(Tarifa $tarifa): int
    {
        return $tarifa->valorAsociado;
    }

    public function observacion(ContextoAcompanante $contexto): ?string
    {
        return null;
    }
}
