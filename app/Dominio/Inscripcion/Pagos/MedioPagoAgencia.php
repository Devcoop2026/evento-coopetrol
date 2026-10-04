<?php

namespace App\Dominio\Inscripcion\Pagos;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Valores;

/** Pago directo en agencia o en efectivo: agencia obligatoria; recibo de caja y comprobante opcionales. */
final class MedioPagoAgencia implements MedioPago
{
    public function codigo(): string
    {
        return 'AGENCIA';
    }

    public function etiqueta(): string
    {
        return 'Agencia / efectivo';
    }

    public function archivoObligatorio(): bool
    {
        return false;
    }

    public function validar(array $datos, VerificacionPagos $verificacion, int $inscripcionExcluida): DatosMedioPago
    {
        $agencia = mb_strtoupper(trim(Valores::cadena($datos['agenciaPago'] ?? null)));
        if ($agencia === '' || ! $verificacion->existeAgencia($agencia)) {
            throw new ErrorValidacion('Seleccione la agencia donde realizó el pago.');
        }
        $recibo = mb_strtoupper(preg_replace('/\s/u', '', Valores::cadena($datos['recibo'] ?? null)));
        if ($recibo !== '' && ! preg_match('/^[A-Z0-9-]{3,30}$/D', $recibo)) {
            throw new ErrorValidacion('El número de recibo no es válido.');
        }
        if ($recibo !== '' && $verificacion->reciboUsado($agencia, $recibo, $inscripcionExcluida)) {
            throw new ErrorValidacion('Ese número de recibo ya fue registrado en otra inscripción.');
        }

        return new DatosMedioPago(agenciaPago: $agencia, recibo: $recibo === '' ? null : $recibo);
    }
}
