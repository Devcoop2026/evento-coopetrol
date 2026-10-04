<?php

namespace App\Dominio\Inscripcion\Pagos;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;

/** PSE: CUS (solo números, sin repetir) y comprobante obligatorios. */
final class MedioPagoPse implements MedioPago
{
    public function codigo(): string
    {
        return 'PSE';
    }

    public function etiqueta(): string
    {
        return 'PSE';
    }

    public function archivoObligatorio(): bool
    {
        return true;
    }

    public function validar(array $datos, VerificacionPagos $verificacion, int $inscripcionExcluida): DatosMedioPago
    {
        $cus = preg_replace('/\s/u', '', Valores::cadena($datos['cus'] ?? null));
        if (! preg_match('/^\d{4,20}$/D', $cus)) {
            throw new ErrorValidacion('Ingrese el número CUS de la transacción PSE (solo números).');
        }
        if ($verificacion->cusUsado($cus, $inscripcionExcluida)) {
            throw new ErrorValidacion('Ese número CUS ya fue registrado en otra inscripción.');
        }

        return new DatosMedioPago(cus: $cus);
    }
}
