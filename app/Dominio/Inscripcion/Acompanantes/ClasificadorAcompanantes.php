<?php

namespace App\Dominio\Inscripcion\Acompanantes;

use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Padron\Tarifa;
use InvalidArgumentException;

/**
 * Clasifica a cada acompañante con la primera estrategia que aplica y calcula los cupos que ocupa un grupo.
 * El titular siempre ocupa cupo.
 */
final class ClasificadorAcompanantes
{
    /** @var list<TipoAcompanante> */
    private array $tipos;

    /** @param  list<TipoAcompanante>|null  $tipos  en orden de prioridad; el último debe aplicar siempre */
    public function __construct(?array $tipos = null)
    {
        $this->tipos = $tipos ?? [new AcompananteAsociado, new AcompananteCoopetrolito, new AcompananteInvitado];
        if (! $this->tipos) {
            throw new InvalidArgumentException('Debe haber al menos un tipo de acompañante.');
        }
    }

    public function clasificar(ContextoAcompanante $contexto, Tarifa $tarifa, string $nombre): PersonaInscrita
    {
        foreach ($this->tipos as $tipo) {
            if ($tipo->aplica($contexto)) {
                $tipo->validar($contexto);

                return new PersonaInscrita($contexto->documento, $nombre, $tipo->codigo(), $tipo->valor($tarifa), $tipo->observacion($contexto));
            }
        }
        throw new InvalidArgumentException('Ningún tipo de acompañante aplica.');
    }

    /** @return list<string> tipos de persona que no ocupan cupo */
    public function tiposSinCupo(): array
    {
        return array_values(array_map(fn (TipoAcompanante $t) => $t->codigo(), array_filter($this->tipos, fn (TipoAcompanante $t) => ! $t->ocupaCupo())));
    }

    public function ocupaCupo(string $tipo): bool
    {
        return ! in_array($tipo, $this->tiposSinCupo(), true);
    }

    /** @param  iterable<PersonaInscrita>  $personas */
    public function cuposRequeridos(iterable $personas): int
    {
        $n = 0;
        foreach ($personas as $persona) {
            $n += $this->ocupaCupo($persona->tipo) ? 1 : 0;
        }

        return $n;
    }
}
