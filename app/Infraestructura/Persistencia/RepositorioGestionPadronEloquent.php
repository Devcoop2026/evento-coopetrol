<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Padron\DatosAsociado;
use App\Dominio\Padron\DatosCoopetrolito;
use App\Dominio\Padron\RepositorioGestionPadron;
use App\Models\AgenciaEvento;
use App\Models\Asociado;
use App\Models\Coopetrolito;
use App\Models\Tarifa;
use Illuminate\Database\Eloquent\Builder;

final class RepositorioGestionPadronEloquent implements RepositorioGestionPadron
{
    public function agenciasValidas(): array
    {
        return AgenciaEvento::query()->pluck('agencia')->merge(Tarifa::query()->pluck('agencia'))->unique()->values()->all();
    }

    public function existeAsociado(string $documento): bool
    {
        return Asociado::query()->whereKey($documento)->exists();
    }

    public function crearAsociado(string $documento, DatosAsociado $datos): void
    {
        Asociado::query()->create(['documento' => $documento, ...self::columnasAsociado($datos)]);
    }

    public function actualizarAsociado(string $documento, DatosAsociado $datos): void
    {
        $columnas = self::columnasAsociado($datos);
        if ($datos->huellaExpedicion === null) {
            unset($columnas['expedicion_hmac']); // vacía conserva la registrada
        }
        Asociado::query()->whereKey($documento)->update($columnas);
    }

    public function eliminarAsociado(string $documento): void
    {
        Asociado::query()->whereKey($documento)->delete();
    }

    public function coopetrolitosDe(string $documentoAsociado): int
    {
        return Coopetrolito::query()->where('documento_asociado', $documentoAsociado)->count();
    }

    public function listarAsociados(?string $busqueda, int $pagina, int $porPagina): array
    {
        $consulta = Asociado::query()
            ->select(['documento', 'nombre', 'agencia', 'estado', 'fecha_actualizacion', 'fecha_nacimiento'])
            ->selectRaw('CASE WHEN expedicion_hmac IS NULL THEN 0 ELSE 1 END AS expedicion_registrada')
            ->when($busqueda, fn (Builder $q, string $b) => self::buscar($q, $b, ['documento', 'nombre', 'agencia']))
            ->orderBy('nombre');

        return self::paginar($consulta, $pagina, $porPagina, fn (Asociado $a) => [
            'documento' => $a->documento,
            'nombre' => $a->nombre,
            'agencia' => $a->agencia,
            'estado' => $a->estado,
            'fecha_actualizacion' => FormatoFecha::fecha($a->fecha_actualizacion),
            'fecha_nacimiento' => FormatoFecha::fecha($a->fecha_nacimiento),
            'expedicion_registrada' => (int) $a->expedicion_registrada,
        ]);
    }

    public function existeCoopetrolito(string $documento): bool
    {
        return Coopetrolito::query()->whereKey($documento)->exists();
    }

    public function crearCoopetrolito(string $documento, DatosCoopetrolito $datos): void
    {
        Coopetrolito::query()->create(['documento' => $documento, ...self::columnasCoopetrolito($datos)]);
    }

    public function actualizarCoopetrolito(string $documento, DatosCoopetrolito $datos): void
    {
        Coopetrolito::query()->whereKey($documento)->update(self::columnasCoopetrolito($datos));
    }

    public function eliminarCoopetrolito(string $documento): void
    {
        Coopetrolito::query()->whereKey($documento)->delete();
    }

    public function listarCoopetrolitos(?string $busqueda, int $pagina, int $porPagina): array
    {
        $consulta = Coopetrolito::query()->from('coopetrolitos as c')
            ->leftJoin('asociados as a', 'a.documento', '=', 'c.documento_asociado')
            ->select(['c.documento', 'c.nombre', 'c.documento_asociado', 'c.fecha_nacimiento', 'a.nombre as nombre_asociado'])
            ->when($busqueda, fn (Builder $q, string $b) => self::buscar($q, $b, ['c.documento', 'c.nombre', 'c.documento_asociado', 'a.nombre']))
            ->orderBy('c.nombre');

        return self::paginar($consulta, $pagina, $porPagina, fn (Coopetrolito $c) => [
            'documento' => $c->documento,
            'nombre' => $c->nombre,
            'documento_asociado' => $c->documento_asociado,
            'fecha_nacimiento' => FormatoFecha::fecha($c->fecha_nacimiento),
            'nombre_asociado' => $c->nombre_asociado,
        ]);
    }

    private static function columnasAsociado(DatosAsociado $d): array
    {
        return [
            'nombre' => $d->nombre,
            'agencia' => $d->agencia,
            'estado' => $d->estado,
            'fecha_actualizacion' => $d->fechaActualizacion,
            'fecha_nacimiento' => $d->fechaNacimiento,
            'expedicion_hmac' => $d->huellaExpedicion,
        ];
    }

    private static function columnasCoopetrolito(DatosCoopetrolito $d): array
    {
        return ['nombre' => $d->nombre, 'documento_asociado' => $d->documentoAsociado, 'fecha_nacimiento' => $d->fechaNacimiento];
    }

    /** Búsqueda sin distinguir mayúsculas en varias columnas. */
    private static function buscar(Builder $consulta, string $busqueda, array $columnas): void
    {
        $patron = '%'.mb_strtolower($busqueda).'%';
        $consulta->where(function (Builder $w) use ($columnas, $patron) {
            foreach ($columnas as $columna) {
                $w->orWhereRaw("LOWER({$columna}) LIKE ?", [$patron]);
            }
        });
    }

    private static function paginar(Builder $consulta, int $pagina, int $porPagina, callable $fila): array
    {
        $total = (clone $consulta)->toBase()->getCountForPagination();
        $filas = $consulta->forPage($pagina, $porPagina)->get()->map($fila)->all();

        return ['filas' => $filas, 'total' => $total, 'pagina' => $pagina, 'paginas' => max(1, (int) ceil($total / $porPagina))];
    }
}
