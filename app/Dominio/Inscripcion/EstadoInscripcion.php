<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Compartido\ErrorValidacion;

/**
 * Ciclo de vida de una inscripción:
 *   PREINSCRITO  pendiente de pago                          -> se puede modificar, cancelar o registrar el pago
 *   EN_REVISION  formulario de pago enviado, por verificar  -> ocupa cupo
 *   RECHAZADO    soporte no válido (con motivo)            -> se puede modificar, cancelar o registrar otro pago
 *   CONFIRMADO   pago verificado                           -> ocupa cupo
 *   CANCELADO    cancelada por el asociado
 *   ANULADO      anulada por un administrador
 * No hay plazo de pago. La preinscripción NO ocupa cupo: se descuenta al enviar el formulario de pago válido y se
 * libera si el pago se rechaza, se anula o se cancela.
 */
enum EstadoInscripcion: string
{
    case Preinscrito = 'PREINSCRITO';
    case EnRevision = 'EN_REVISION';
    case Rechazado = 'RECHAZADO';
    case Confirmado = 'CONFIRMADO';
    case Cancelado = 'CANCELADO';
    case Anulado = 'ANULADO';

    /** @return list<self> inscripción vigente: una persona no puede estar en dos */
    public static function activos(): array
    {
        return [self::Preinscrito, self::EnRevision, self::Rechazado, self::Confirmado];
    }

    /** @return list<self> ocupan cupo (pago registrado) */
    public static function ocupanCupo(): array
    {
        return [self::EnRevision, self::Confirmado];
    }

    /** @return list<self> pendientes de pago */
    public static function pendientes(): array
    {
        return [self::Preinscrito, self::Rechazado];
    }

    /** @param  list<self>  $estados
     *  @return list<string> */
    public static function valores(array $estados): array
    {
        return array_map(fn (self $e) => $e->value, $estados);
    }

    public function esActivo(): bool
    {
        return in_array($this, self::activos(), true);
    }

    public function esEditable(): bool
    {
        return in_array($this, self::pendientes(), true);
    }

    /** `accion`: "modificarla", "cancelarla", "registrar el pago"... */
    public function exigirEditable(string $accion): void
    {
        if (! $this->esEditable()) {
            $estado = mb_strtolower(str_replace('_', ' ', $this->value));
            throw new ErrorValidacion("No es posible {$accion}: la inscripción está {$estado}.");
        }
    }
}
