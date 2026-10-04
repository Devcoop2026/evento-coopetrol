<?php

namespace App\Dominio\Inscripcion\Pagos;

use App\Dominio\Compartido\ErrorValidacion;

/** Medios de pago disponibles, por código. */
final class CatalogoMediosPago
{
    /** @var array<string, MedioPago> */
    private array $medios = [];

    /** @param  list<MedioPago>|null  $medios */
    public function __construct(?array $medios = null)
    {
        foreach ($medios ?? [new MedioPagoPse, new MedioPagoAgencia] as $medio) {
            $this->medios[$medio->codigo()] = $medio;
        }
    }

    public function medio(mixed $codigo): MedioPago
    {
        return (is_string($codigo) ? ($this->medios[$codigo] ?? null) : null)
            ?? throw new ErrorValidacion('Seleccione cómo realizó el pago.');
    }

    public function etiqueta(string $codigo): string
    {
        return ($this->medios[$codigo] ?? null)?->etiqueta() ?? $codigo;
    }
}
