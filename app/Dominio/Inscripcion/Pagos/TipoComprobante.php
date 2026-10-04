<?php

namespace App\Dominio\Inscripcion\Pagos;

/** Tipos de comprobante aceptados, reconocidos por su firma de bytes (no por la extensión ni el tipo que declara el navegador). */
enum TipoComprobante: string
{
    case Pdf = 'application/pdf';
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';

    public static function porFirma(string $bytes): ?self
    {
        foreach (self::cases() as $tipo) {
            if (str_starts_with($bytes, $tipo->firma())) {
                return $tipo;
            }
        }

        return null;
    }

    public function firma(): string
    {
        return match ($this) {
            self::Pdf => '%PDF',
            self::Png => "\x89PNG",
            self::Jpeg => "\xFF\xD8\xFF",
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Pdf => 'pdf',
            self::Png => 'png',
            self::Jpeg => 'jpg',
        };
    }

    public function esImagen(): bool
    {
        return $this !== self::Pdf;
    }
}
