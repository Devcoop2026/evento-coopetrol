<?php

namespace App\Aplicacion\Inscripciones;

/** Archivo adjunto tal como lo recibe la presentación (nombre original y contenido). */
final readonly class ArchivoRecibido
{
    public function __construct(
        public string $nombre,
        public string $contenido,
    ) {}
}
