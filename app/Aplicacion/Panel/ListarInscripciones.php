<?php

namespace App\Aplicacion\Panel;

use App\Dominio\Inscripcion\RepositorioInscripciones;

/** Listado del panel con filtros por estado, evento y búsqueda (referencia, documento o nombre). */
final class ListarInscripciones
{
    public const LIMITE = 1000;

    public function __construct(private readonly RepositorioInscripciones $repositorio) {}

    /** @param  array{estado?: ?string, agencia?: ?string, busqueda?: ?string}  $filtros */
    public function ejecutar(array $filtros = []): array
    {
        return $this->repositorio->listar($filtros);
    }
}
