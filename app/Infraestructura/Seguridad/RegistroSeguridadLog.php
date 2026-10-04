<?php

namespace App\Infraestructura\Seguridad;

use App\Dominio\Compartido\Reloj;
use App\Dominio\Seguridad\RegistroSeguridad;
use Psr\Log\LoggerInterface;

/**
 * Una línea JSON por evento en el canal "seguridad" (config/logging.php):
 *   {"fecha":"2026-10-05T15:00:00.000Z","tipo":"seguridad","evento":"login_fallido","usuario":"...","ip":"..."}
 */
final class RegistroSeguridadLog implements RegistroSeguridad
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly Reloj $reloj,
    ) {}

    public function registrar(string $evento, array $datos = []): void
    {
        $this->logger->info(json_encode([
            'fecha' => $this->reloj->ahora()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'tipo' => 'seguridad',
            'evento' => $evento,
            ...$datos,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
