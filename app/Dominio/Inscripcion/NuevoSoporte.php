<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Inscripcion\Pagos\DatosMedioPago;
use DateTimeImmutable;

final readonly class NuevoSoporte
{
    /** @param  array<string, string>  $campos */
    public function __construct(
        public int $inscripcionId,
        public string $medioPago,
        public DatosMedioPago $datosMedio,
        public string $fechaPago,
        public int $valorPagado,
        public array $campos,
        public string $archivo,
        public string $tipoArchivo,
        public ?string $nombreOriginal,
        public ?string $alerta,
        public string $versionAutorizacion,
        public DateTimeImmutable $marca,
    ) {}
}
