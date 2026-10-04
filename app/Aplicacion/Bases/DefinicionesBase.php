<?php

namespace App\Aplicacion\Bases;

use App\Dominio\Compartido\ErrorValidacion;

/**
 * Columnas aceptadas de cada base (primera fila; no importan mayúsculas, tildes, puntos ni guiones bajos) y su plantilla.
 * Las columnas `opcionales` pueden faltar (archivos con el formato anterior): el valor queda vacío.
 */
final class DefinicionesBase
{
    public const TIPOS = ['asociados', 'coopetrolitos'];

    private const DEFINICIONES = [
        'asociados' => [
            'columnas' => [
                'documento' => ['cedula', 'documento', 'numero de documento', 'identificacion'],
                'nombre' => ['nombre', 'nombre completo', 'nombres'],
                'agencia' => ['agencia'],
                'asociado' => ['asociado', 'estado', 'es asociado'],
                'fecha_actualizacion' => ['actualizacion datos', 'ultima actualizacion de datos', 'fecha actualizacion',
                    'fecha de actualizacion', 'actualizacion de datos'],
                'fecha_expedicion' => ['fecha expedicion', 'fecha de expedicion', 'fecha expedicion cedula',
                    'fecha de expedicion de la cedula', 'fecha de expedicion cedula', 'fecha expedicion documento'],
                'fecha_nacimiento' => ['fecha nacimiento', 'fecha de nacimiento', 'nacimiento'],
            ],
            'opcionales' => ['fecha_nacimiento'],
            'plantilla' => "Cedula;Nombre;Agencia;Asociado;actualizacion_datos;fecha_expedicion;fecha_nacimiento\n"
                ."1234567;Nombre de Ejemplo;BOGOTA;SI;15/02/2026;14/03/2008;20/05/1985\n",
        ],
        'coopetrolitos' => [
            'columnas' => [
                'documento' => ['documento', 'documento coopetrolito', 'tarjeta de identidad', 'registro civil'],
                'nombre' => ['nombre', 'nombre coopetrolito', 'nombre completo'],
                'documento_asociado' => ['cedula asociado', 'documento asociado', 'cedula del asociado', 'cedula padre', 'cedula madre'],
                'fecha_nacimiento' => ['fecha nacimiento', 'fecha de nacimiento', 'nacimiento'],
            ],
            'opcionales' => ['fecha_nacimiento'],
            'plantilla' => "Documento;Nombre;Cedula asociado;fecha_nacimiento\n1100000001;Nombre de Ejemplo;1234567;10/06/2015\n",
        ],
    ];

    /** @return array{columnas: array<string, list<string>>, opcionales: list<string>, plantilla: string} */
    public static function de(mixed $tipo): array
    {
        return (is_string($tipo) ? (self::DEFINICIONES[$tipo] ?? null) : null) ?? throw new ErrorValidacion('Tipo de base no válido.');
    }

    /** Plantilla CSV con BOM (Excel abre las tildes bien). */
    public static function plantilla(mixed $tipo): string
    {
        return "\u{FEFF}".self::de($tipo)['plantilla'];
    }
}
