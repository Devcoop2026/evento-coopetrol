<?php

namespace App\Dominio\Compartido;

use DateTimeImmutable;

/** Puerto: fecha y hora actuales (inyectable para probar vigencias y periodos). */
interface Reloj
{
    public function ahora(): DateTimeImmutable;
}
