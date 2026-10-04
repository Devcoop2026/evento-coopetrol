<?php

namespace App\Dominio\Inscripcion;

/** Pago registrado para una inscripción (el más reciente es el vigente; los anteriores fueron rechazados). */
final readonly class Soporte
{
    /** @param  array<string, string>  $campos  campos adicionales del formulario */
    public function __construct(
        public int $id,
        public int $inscripcionId,
        public string $medioPago,
        public string $cus,
        public string $banco,
        public ?string $agenciaPago,
        public ?string $recibo,
        public string $fechaPago,
        public int $valorPagado,
        public array $campos,
        public string $archivo,
        public string $tipoArchivo,
        public ?string $nombreOriginal,
        public ?string $alerta,
        public ?string $autorizacionVersion,
        public string $cargadoEn,
    ) {}

    public function tieneArchivo(): bool
    {
        return $this->archivo !== '';
    }
}
