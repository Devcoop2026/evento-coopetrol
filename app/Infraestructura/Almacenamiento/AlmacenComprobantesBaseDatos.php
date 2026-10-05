<?php

namespace App\Infraestructura\Almacenamiento;

use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\TipoComprobante;
use App\Infraestructura\Persistencia\FormatoFecha;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use PDO;

/**
 * Comprobantes guardados como datos binarios en la tabla soportes_archivos.
 */
final class AlmacenComprobantesBaseDatos implements AlmacenComprobantes
{
    public function __construct(private readonly Connection $db) {}

    public function guardar(string $contenido, TipoComprobante $tipo): string
    {
        $nombre = Str::uuid().'.'.$tipo->extension();
        // Mantiene los comprobantes como bytes; el contenido no necesariamente es UTF-8 válido.
        $sentencia = $this->db->getPdo()->prepare('INSERT INTO soportes_archivos (nombre, contenido, creado_en) VALUES (?, ?, ?)');
        $sentencia->bindValue(1, $nombre);
        $sentencia->bindValue(2, $contenido, PDO::PARAM_LOB);
        $sentencia->bindValue(3, FormatoFecha::aBaseDatos(new DateTimeImmutable));
        $sentencia->execute();

        return $nombre;
    }

    public function leer(string $nombre): ?string
    {
        $contenido = $this->db->table('soportes_archivos')->where('nombre', $nombre)->value('contenido');

        return is_resource($contenido) ? stream_get_contents($contenido) : $contenido;
    }

    public function borrar(string $nombre): void
    {
        $this->db->table('soportes_archivos')->where('nombre', $nombre)->delete();
    }
}
