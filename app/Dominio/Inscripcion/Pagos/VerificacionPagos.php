<?php

namespace App\Dominio\Inscripcion\Pagos;

/** Puerto: consultas que necesitan los medios de pago para validar (implementado por los repositorios). */
interface VerificacionPagos
{
    /** Referencia de otra inscripción vigente que ya usó el CUS, o null. */
    public function cusUsado(string $cus, int $inscripcionExcluida): ?string;

    /** Referencia de otra inscripción vigente que ya usó el recibo de esa agencia, o null. */
    public function reciboUsado(string $agencia, string $recibo, int $inscripcionExcluida): ?string;

    public function existeAgencia(string $agencia): bool;
}
