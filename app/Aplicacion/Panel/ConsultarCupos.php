<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Contador de cupos por evento (público y panel): cupos vigentes, ocupados y disponibles. */
final class ConsultarCupos
{
    public function __construct(private readonly RepositorioInscripciones $repositorio) {}

    /** @return list<array{agencia: string, cupos_excel: int, cupos_ajustados: ?int, cupos: int, ocupados: int, pendientes: int, confirmados: int, invitados: int, disponibles: int}> */
    public function ejecutar(): array
    {
        return array_map(fn (array $c) => [...$c, 'disponibles' => max(0, $c['cupos'] - $c['ocupados'])], $this->repositorio->cupos());
    }

    /** @return array<string, array{cupos: int, disponibles: int}> por agencia */
    public function porAgencia(): array
    {
        $resultado = [];
        foreach ($this->ejecutar() as $c) {
            $resultado[$c['agencia']] = ['cupos' => $c['cupos'], 'disponibles' => $c['disponibles']];
        }

        return $resultado;
    }
}
