<?php

namespace App\Infraestructura;

use App\Dominio\Compartido\Reloj;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/** Reloj del sistema. En las pruebas se fija con Carbon::setTestNow() / $this->travelTo(). */
final class RelojSistema implements Reloj
{
    public function ahora(): DateTimeImmutable
    {
        return CarbonImmutable::now('UTC')->toDateTimeImmutable();
    }
}
