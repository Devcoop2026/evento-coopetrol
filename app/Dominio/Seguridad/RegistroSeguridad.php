<?php

namespace App\Dominio\Seguridad;

/**
 * Puerto: registro de eventos de seguridad (ingresos, bloqueos, permisos denegados, cargas de bases).
 * Nunca debe recibir claves, fechas de expedición ni documentos completos (use Enmascarar::documento()).
 */
interface RegistroSeguridad
{
    public function registrar(string $evento, array $datos = []): void;
}
