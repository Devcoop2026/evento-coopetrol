<?php

namespace App\Infraestructura\Configuracion;

use App\Aplicacion\Configuracion\ConfiguracionEvento;
use RuntimeException;

/** Construye la configuración del evento a partir de los JSON de data/ (o EVENTO_CONFIG para config.json). */
final class LectorConfiguracion
{
    public static function leer(string $directorio, ?string $archivoConfig, int $maxAcompanantes, int $mesesVigencia): ConfiguracionEvento
    {
        return new ConfiguracionEvento(
            config: $archivoConfig ? self::json($archivoConfig) : self::jsonOpcional("{$directorio}/config.json", []),
            camposSoporte: self::jsonOpcional("{$directorio}/formulario_soporte.json", []),
            habeasData: self::jsonOpcional("{$directorio}/habeas_data.json", ['version' => 'sin-version', 'texto' => []]),
            maxAcompanantes: $maxAcompanantes,
            mesesVigenciaDatos: $mesesVigencia,
        );
    }

    public static function json(string $ruta): array
    {
        $texto = @file_get_contents($ruta);
        if ($texto === false) {
            throw new RuntimeException("No se pudo leer {$ruta}.");
        }
        $datos = json_decode($texto, true);
        if (! is_array($datos)) {
            throw new RuntimeException("{$ruta} no es un JSON válido: ".json_last_error_msg());
        }

        return $datos;
    }

    private static function jsonOpcional(string $ruta, array $defecto): array
    {
        return is_file($ruta) ? self::json($ruta) : $defecto;
    }
}
