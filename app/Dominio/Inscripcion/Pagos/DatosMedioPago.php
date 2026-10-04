<?php

namespace App\Dominio\Inscripcion\Pagos;

/**
 * Datos del soporte que dependen del medio de pago. El banco ya no se pide en el formulario; la columna se conserva
 * para los soportes registrados antes.
 */
final readonly class DatosMedioPago
{
    public function __construct(
        public string $cus = '',
        public string $banco = '',
        public ?string $agenciaPago = null,
        public ?string $recibo = null,
    ) {}
}
