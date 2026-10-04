<?php

namespace App\Dominio\Compartido;

/**
 * Puerto: ejecuta un bloque como una transacción exclusiva. Las operaciones que validan y ocupan cupos o que verifican
 * duplicados corren aquí, para que dos solicitudes simultáneas no superen el cupo ni inscriban dos veces a la misma persona.
 */
interface UnidadDeTrabajo
{
    /**
     * @template T
     *
     * @param  callable(): T  $bloque
     * @return T
     */
    public function ejecutar(callable $bloque): mixed;
}
