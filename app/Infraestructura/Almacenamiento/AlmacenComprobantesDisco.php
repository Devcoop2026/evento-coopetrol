<?php

namespace App\Infraestructura\Almacenamiento;

use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/** Comprobantes en un disco de Laravel (local fuera de la carpeta pública, o S3 / Cloudflare R2). */
final class AlmacenComprobantesDisco implements AlmacenComprobantes
{
    public function __construct(private readonly Filesystem $disco) {}

    public function guardar(string $contenido, TipoComprobante $tipo): string
    {
        $nombre = Str::uuid().'.'.$tipo->extension();
        $this->disco->put($nombre, $contenido);

        return $nombre;
    }

    public function leer(string $nombre): ?string
    {
        $nombre = basename($nombre);

        return $this->disco->exists($nombre) ? $this->disco->get($nombre) : null;
    }

    public function borrar(string $nombre): void
    {
        $this->disco->delete(basename($nombre));
    }
}
