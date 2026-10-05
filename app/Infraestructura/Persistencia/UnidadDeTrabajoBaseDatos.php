<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Compartido\UnidadDeTrabajo;
use Illuminate\Database\ConnectionInterface;

/** Serializa las operaciones que validan y ocupan cupos para evitar sobreventas e inscripciones duplicadas. */
final class UnidadDeTrabajoBaseDatos implements UnidadDeTrabajo
{
    private const CLAVE_BLOQUEO = 'evento-coopetrol-cupos';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function ejecutar(callable $bloque): mixed
    {
        $driver = (string) config('database.connections.'.config('database.default').'.driver');
        $bloqueado = false;

        try {
            return $this->db->transaction(function () use ($bloque, $driver, &$bloqueado) {
                if ($driver === 'pgsql') {
                    $this->db->select('SELECT pg_advisory_xact_lock(?)', [2026_1231]);
                } elseif ($driver === 'mysql') {
                    $resultado = $this->db->selectOne('SELECT GET_LOCK(?, 10) AS adquirido', [self::CLAVE_BLOQUEO]);
                    if ((int) ($resultado->adquirido ?? 0) !== 1) {
                        throw new \RuntimeException('No fue posible reservar un cupo en este momento. Intente de nuevo.');
                    }
                    $bloqueado = true;
                }

                return $bloque();
            });
        } finally {
            if ($bloqueado) {
                $this->db->selectOne('SELECT RELEASE_LOCK(?) AS liberado', [self::CLAVE_BLOQUEO]);
            }
        }
    }
}
