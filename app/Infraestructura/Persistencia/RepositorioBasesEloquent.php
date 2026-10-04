<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Padron\RepositorioBases;
use App\Models\AgenciaEvento;
use App\Models\Asociado;
use App\Models\CargaBase;
use App\Models\Coopetrolito;
use App\Models\Tarifa;
use DateTimeImmutable;

/** Reemplazo masivo de las bases. Debe ejecutarse dentro de la unidad de trabajo (todo o nada). */
final class RepositorioBasesEloquent implements RepositorioBases
{
    private const LOTE = 500;

    public function agenciasValidas(): array
    {
        return AgenciaEvento::query()->pluck('agencia')->merge(Tarifa::query()->pluck('agencia'))->unique()->values()->all();
    }

    public function documentosAsociados(): array
    {
        return Asociado::query()->pluck('documento')->all();
    }

    public function reemplazarAsociados(array $registros): void
    {
        Asociado::query()->delete();
        foreach (array_chunk($registros, self::LOTE) as $lote) {
            Asociado::query()->insert($lote);
        }
    }

    public function reemplazarCoopetrolitos(array $registros): void
    {
        Coopetrolito::query()->delete();
        foreach (array_chunk($registros, self::LOTE) as $lote) {
            Coopetrolito::query()->insert($lote);
        }
    }

    public function registrarCarga(string $tipo, ?string $archivo, int $registros, string $usuario, DateTimeImmutable $marca): void
    {
        CargaBase::query()->create([
            'tipo' => $tipo, 'archivo' => $archivo, 'registros' => $registros, 'usuario' => $usuario,
            'cargada_en' => FormatoFecha::aBaseDatos($marca),
        ]);
    }

    public function estado(string $tipo): array
    {
        $ultima = CargaBase::query()->where('tipo', $tipo)->orderByDesc('id')->first();

        return [
            'total' => $tipo === 'asociados' ? Asociado::query()->count() : Coopetrolito::query()->count(),
            'ultimaCarga' => $ultima ? [
                'archivo' => $ultima->archivo,
                'registros' => $ultima->registros,
                'usuario' => $ultima->usuario,
                'cargada_en' => FormatoFecha::aIso($ultima->cargada_en),
            ] : null,
        ];
    }
}
