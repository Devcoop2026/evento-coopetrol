<?php

namespace App\Livewire;

use DateTimeImmutable;
use DateTimeZone;

/** Formatos de presentación (pesos colombianos y fechas en hora de Colombia), los mismos de la interfaz anterior. */
final class Formato
{
    public const ETIQUETA_TIPO = ['TITULAR' => 'Titular', 'ASOCIADO' => 'Asociado', 'COOPETROLITO' => 'Coopetrolito', 'INVITADO' => 'No asociado'];

    public static function pesos(int|float|null $valor): string
    {
        return '$ '.number_format((float) ($valor ?? 0), 0, ',', '.');
    }

    public static function numero(int|float|null $valor): string
    {
        return number_format((float) ($valor ?? 0), 0, ',', '.');
    }

    /** AAAA-MM-DD (o ISO) -> DD/MM/AAAA. */
    public static function fechaCorta(?string $iso): string
    {
        return $iso ? implode('/', array_reverse(explode('-', substr($iso, 0, 10)))) : '—';
    }

    /** ISO en UTC -> fecha y hora corta en Colombia ("5/10/26, 10:00 a. m."). */
    public static function fechaHora(?string $iso): string
    {
        if (! $iso) {
            return '—';
        }
        $momento = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('America/Bogota'));

        return $momento->format('j/n/y, g:i').' '.($momento->format('A') === 'AM' ? 'a. m.' : 'p. m.');
    }

    /** 'EVENTO FIN DE AÑO COOPETROL' -> 'Evento fin de año Coopetrol'. */
    public static function oracion(string $texto): string
    {
        $t = str_replace('coopetrol', 'Coopetrol', mb_strtolower(trim($texto)));

        return mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1);
    }

    /** Texto de la persona en las tablas de liquidación. */
    public static function subPersona(array $persona): string
    {
        if ($persona['tipo'] === 'TITULAR') {
            return 'Asociado titular';
        }
        $descripcion = [
            'ASOCIADO' => 'Asociado: tarifa preferencial',
            'COOPETROLITO' => 'Coopetrolito: tarifa de asociado',
            'INVITADO' => 'No asociado: tarifa invitado',
        ][$persona['tipo']] ?? '';

        return "Doc. {$persona['documento']} · ".(($persona['observacion'] ?? null) ?: $descripcion);
    }
}
