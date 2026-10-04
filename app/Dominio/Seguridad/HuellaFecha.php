<?php

namespace App\Dominio\Seguridad;

/**
 * Puerto: la fecha de expedición es un factor de identidad y se guarda solo como huella (HMAC con una clave del
 * servidor). Si la base se filtra, las fechas no quedan expuestas.
 */
interface HuellaFecha
{
    public function calcular(string $fecha): string;

    /** Comparación en tiempo constante. */
    public function coincide(string $fecha, ?string $huella): bool;
}
