<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Padron\Asociado;
use App\Dominio\Padron\Coopetrolito;
use App\Dominio\Padron\RepositorioPadron;
use App\Dominio\Padron\Tarifa;
use App\Models\AgenciaEvento;
use App\Models\Asociado as AsociadoModelo;
use App\Models\Coopetrolito as CoopetrolitoModelo;
use App\Models\Evento;
use App\Models\Tarifa as TarifaModelo;

final class RepositorioPadronEloquent implements RepositorioPadron
{
    public function asociado(string $documento): ?Asociado
    {
        $a = AsociadoModelo::query()->find($documento);

        return $a ? new Asociado(
            documento: $a->documento,
            nombre: $a->nombre,
            agencia: $a->agencia,
            estado: $a->estado,
            fechaActualizacion: FormatoFecha::fecha($a->fecha_actualizacion),
            huellaExpedicion: $a->expedicion_hmac,
            fechaNacimiento: FormatoFecha::fecha($a->fecha_nacimiento),
        ) : null;
    }

    public function coopetrolito(string $documento): ?Coopetrolito
    {
        $c = CoopetrolitoModelo::query()->find($documento);

        return $c ? new Coopetrolito($c->documento, $c->documento_asociado) : null;
    }

    public function tarifa(string $agencia): ?Tarifa
    {
        $t = TarifaModelo::query()->find($agencia);

        return $t ? self::tarifaDe($t) : null;
    }

    public function tarifas(): array
    {
        return TarifaModelo::query()->orderBy('agencia')->get()->map(self::tarifaDe(...))->all();
    }

    public function eventoDeAgencia(string $agencia): ?string
    {
        return AgenciaEvento::query()->whereKey($agencia)->value('evento')
            ?? (TarifaModelo::query()->whereKey($agencia)->exists() ? $agencia : null);
    }

    public function existeAgencia(string $agencia): bool
    {
        return AgenciaEvento::query()->whereKey($agencia)->exists() || TarifaModelo::query()->whereKey($agencia)->exists();
    }

    public function agencias(): array
    {
        return AgenciaEvento::query()->orderBy('agencia')->get(['agencia', 'evento'])
            ->map(fn (AgenciaEvento $a) => ['agencia' => $a->agencia, 'evento' => $a->evento])->all();
    }

    public function evento(): ?array
    {
        return Evento::query()->find(1)?->only(['nombre', 'inscripciones', 'cuenta_contable', 'concepto']);
    }

    private static function tarifaDe(TarifaModelo $t): Tarifa
    {
        return new Tarifa($t->agencia, $t->cupos, $t->valor_invitado, $t->valor_asociado);
    }
}
