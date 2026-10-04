<?php

namespace App\Dominio\Seguridad;

/** Puerto: limitador de intentos por clave (IP, documento...) dentro de una ventana de tiempo. */
interface LimitadorIntentos
{
    /** Segundos que faltan para liberar la clave si ya alcanzó `maximo` intentos; 0 si no está bloqueada. */
    public function bloqueada(string $clave, int $maximo): int;

    public function registrar(string $clave, int $ventanaSegundos): void;

    public function reiniciar(string $clave): void;
}
