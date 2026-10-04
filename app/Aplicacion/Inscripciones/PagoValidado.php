<?php

namespace App\Aplicacion\Inscripciones;

use App\Dominio\Inscripcion\Pagos\DatosMedioPago;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;

/** Datos del pago ya validados, listos para registrar. */
final readonly class PagoValidado
{
    /** @param  array<string, string>  $adicionales */
    public function __construct(
        public string $medio,
        public DatosMedioPago $datosMedio,
        public string $fecha,
        public int $valor,
        public array $adicionales,
        public ?string $comprobante = null,
        public ?TipoComprobante $tipoComprobante = null,
        public ?string $nombreOriginal = null,
    ) {}
}
