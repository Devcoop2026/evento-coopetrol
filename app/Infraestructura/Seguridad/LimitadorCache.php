<?php

namespace App\Infraestructura\Seguridad;

use App\Dominio\Seguridad\LimitadorIntentos;
use Illuminate\Cache\RateLimiter;

/** Limitador sobre el RateLimiter de Laravel (caché en la base: compartido entre procesos y reinicios). */
final class LimitadorCache implements LimitadorIntentos
{
    public function __construct(private readonly RateLimiter $limitador) {}

    public function bloqueada(string $clave, int $maximo): int
    {
        return $this->limitador->tooManyAttempts($clave, $maximo) ? max(1, $this->limitador->availableIn($clave)) : 0;
    }

    public function registrar(string $clave, int $ventanaSegundos): void
    {
        $this->limitador->hit($clave, $ventanaSegundos);
    }

    public function reiniciar(string $clave): void
    {
        $this->limitador->clear($clave);
    }
}
