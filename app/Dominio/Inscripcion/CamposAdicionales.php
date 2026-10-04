<?php

namespace App\Dominio\Inscripcion;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reglas;
use App\Dominio\Compartido\Valores;

/**
 * Campos adicionales del formulario de pago (definidos en data/formulario_soporte.json, sin programar):
 *   { id, etiqueta, tipo, requerido, opciones?, max?, ayuda?, mostrarSi?: { campo, valor } }
 * Un campo con `mostrarSi` solo aplica (y solo se guarda) cuando la otra respuesta coincide.
 */
final class CamposAdicionales
{
    /** @param  list<array<string, mixed>>  $definicion */
    public function __construct(private readonly array $definicion) {}

    /** @return list<array<string, mixed>> */
    public function definicion(): array
    {
        return $this->definicion;
    }

    public static function aplica(array $campo, array $valores): bool
    {
        return empty($campo['mostrarSi'])
            || trim(Valores::cadena($valores[$campo['mostrarSi']['campo']] ?? null)) === $campo['mostrarSi']['valor'];
    }

    /**
     * Devuelve solo los campos que aplican, normalizados; lanza el primer error encontrado.
     *
     * @return array<string, string>
     */
    public function validar(mixed $valores): array
    {
        $valores = is_array($valores) ? $valores : [];
        $limpio = [];
        foreach ($this->definicion as $campo) {
            if (! self::aplica($campo, $valores)) {
                continue;
            }
            $valor = self::normalizar($campo['tipo'] ?? 'texto', trim(Valores::cadena($valores[$campo['id']] ?? null)));
            if ($valor === '') {
                if (! empty($campo['requerido'])) {
                    throw new ErrorValidacion("Complete el campo \"{$campo['etiqueta']}\".");
                }

                continue;
            }
            if (mb_strlen($valor) > ($campo['max'] ?? 200)) {
                throw new ErrorValidacion("\"{$campo['etiqueta']}\" es demasiado largo.");
            }
            if (! self::esValido($campo, $valor)) {
                throw new ErrorValidacion("\"{$campo['etiqueta']}\" no tiene un formato válido.");
            }
            $limpio[$campo['id']] = $valor;
        }

        return $limpio;
    }

    /** Placas colombianas: carro ABC123, moto ABC12D. */
    private static function normalizar(string $tipo, string $valor): string
    {
        return match ($tipo) {
            'placa' => mb_strtoupper(preg_replace('/[\s-]/u', '', $valor)),
            'telefono' => preg_replace('/[\s-]/u', '', $valor),
            default => $valor,
        };
    }

    private static function esValido(array $campo, string $valor): bool
    {
        return match ($campo['tipo'] ?? 'texto') {
            'numero' => (bool) preg_match('/^-?\d+([.,]\d+)?$/D', $valor),
            'fecha' => Valores::esFechaValida($valor),
            'correo' => (bool) preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/Du', $valor),
            'telefono' => Reglas::esNumerico($valor) && strlen($valor) >= 7 && strlen($valor) <= 15,
            'numerico' => Reglas::esNumerico($valor),
            'seleccion' => in_array($valor, $campo['opciones'] ?? [], true),
            'placa' => (bool) preg_match('/^[A-Z]{3}\d{2}[A-Z0-9]$/D', $valor),
            default => true,
        };
    }
}
