<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Compartido\UnidadDeTrabajo;
use Illuminate\Database\ConnectionInterface;

/**
 * Transacción con bloqueo exclusivo. En PostgreSQL toma un bloqueo consultivo de transacción (pg_advisory_xact_lock):
 * las operaciones que validan y ocupan cupos se ejecutan de una en una, así dos pagos simultáneos no superan el cupo
 * ni inscriben dos veces a la misma persona. El bloqueo se libera solo al terminar la transacción.
 * En SQLite (pruebas) la propia transacción de escritura ya serializa.
 */
final class UnidadDeTrabajoBaseDatos implements UnidadDeTrabajo
{
    private const CLAVE_BLOQUEO = 2026_1231;

    public function __construct(private readonly ConnectionInterface $db) {}

    public function ejecutar(callable $bloque): mixed
    {
        return $this->db->transaction(function () use ($bloque) {
            if ($this->db->getDriverName() === 'pgsql') {
                $this->db->select('SELECT pg_advisory_xact_lock(?)', [self::CLAVE_BLOQUEO]);
            }

            return $bloque();
        });
    }
}
