<?php

namespace App\Aplicacion\Gestion;

use App\Dominio\Panel\RegistroAuditoria;

/** Últimos cambios manuales hechos desde el panel (máximo 500). */
final class ConsultarAuditoria
{
    public function __construct(private readonly RegistroAuditoria $auditoria) {}

    public function ejecutar(int|string|null $limite = 100): array
    {
        return $this->auditoria->ultimos(min(500, max(1, (int) $limite ?: 100)));
    }
}
