<?php

namespace App\Aplicacion\Bases;

use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Padron\RepositorioBases;

/**
 * Caso de uso: carga masiva de la base de asociados o de Coopetrolitos. Sin confirmar solo devuelve la vista previa;
 * al confirmar reemplaza la base completa en una transacción (todo o nada) y registra quién la cargó.
 */
final class CargarBase
{
    public function __construct(
        private readonly AnalizarBase $analizar,
        private readonly RepositorioBases $bases,
        private readonly UnidadDeTrabajo $unidad,
        private readonly Reloj $reloj,
    ) {}

    public function ejecutar(string $tipo, string $contenido, bool $confirmar = false, ?string $archivo = null, string $usuario = ''): array
    {
        ['registros' => $registros, 'errores' => $errores, 'advertencias' => $advertencias] = $this->analizar->ejecutar($tipo, $contenido);
        if ($confirmar) {
            $this->unidad->ejecutar(function () use ($tipo, $registros, $archivo, $usuario) {
                $tipo === 'asociados' ? $this->bases->reemplazarAsociados($registros) : $this->bases->reemplazarCoopetrolitos($registros);
                $this->bases->registrarCarga($tipo, $archivo !== null ? mb_substr($archivo, 0, 200) : null, count($registros), $usuario, $this->reloj->ahora());
            });
        }
        $resumen = [
            'tipo' => $tipo,
            'registros' => count($registros),
            'omitidas' => count($errores),
            'errores' => array_slice($errores, 0, 100),
            'advertencias' => $advertencias,
            'guardado' => $confirmar,
        ];
        if ($tipo === 'asociados') {
            $resumen['activos'] = count(array_filter($registros, fn ($r) => $r['estado'] === 'ACTIVO'));
            $porAgencia = array_count_values(array_column($registros, 'agencia'));
            ksort($porAgencia);
            $resumen['porAgencia'] = array_map(fn ($a, $n) => [$a, $n], array_keys($porAgencia), $porAgencia);
        }

        return $resumen;
    }

    public function estado(): array
    {
        return ['asociados' => $this->bases->estado('asociados'), 'coopetrolitos' => $this->bases->estado('coopetrolitos')];
    }
}
