<?php

namespace App\Dominio\Inscripcion;

use DateTimeInterface;

/** Referencia visible para el asociado: EVT26-000001 (año de la inscripción y consecutivo). */
final class Referencia
{
    public static function generar(int $id, DateTimeInterface $fecha): string
    {
        return 'EVT'.$fecha->format('y').'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
