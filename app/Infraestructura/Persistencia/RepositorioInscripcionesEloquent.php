<?php

namespace App\Infraestructura\Persistencia;

use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\CambioDeEstado;
use App\Dominio\Inscripcion\EstadoInscripcion;
use App\Dominio\Inscripcion\Inscripcion;
use App\Dominio\Inscripcion\NuevaInscripcion;
use App\Dominio\Inscripcion\NuevoSoporte;
use App\Dominio\Inscripcion\PersonaInscrita;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Inscripcion\Soporte;
use App\Models\CupoAjustado;
use App\Models\Inscripcion as InscripcionModelo;
use App\Models\InscripcionPersona;
use App\Models\Soporte as SoporteModelo;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class RepositorioInscripcionesEloquent implements RepositorioInscripciones
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ClasificadorAcompanantes $clasificador,
    ) {}

    // ---- Escritura ----

    public function crear(NuevaInscripcion $datos, callable $referencia): int
    {
        $marca = FormatoFecha::aBaseDatos($datos->marca);
        $inscripcion = InscripcionModelo::query()->create([
            'referencia' => 'TMP-'.Str::uuid(),
            'documento_titular' => $datos->titular->documento,
            'nombre_titular' => $datos->titular->nombre,
            'agencia' => $datos->agencia,
            'agencia_asociado' => $datos->titular->agencia,
            'total' => $datos->total,
            'estado' => EstadoInscripcion::Preinscrito->value,
            'autorizacion_version' => $datos->versionAutorizacion,
            'autorizacion_en' => $marca,
            'autorizacion_ip' => $datos->ip,
            'autorizacion_imagen' => $datos->autorizaImagen,
            'creada_en' => $marca,
            'actualizada_en' => $marca,
        ]);
        $inscripcion->update(['referencia' => $referencia($inscripcion->id)]);
        $this->guardarPersonas($inscripcion->id, $datos->personas);

        return $inscripcion->id;
    }

    public function guardarPersonas(int $inscripcionId, array $personas): void
    {
        InscripcionPersona::query()->where('inscripcion_id', $inscripcionId)->delete();
        InscripcionPersona::query()->insert(array_map(fn (PersonaInscrita $p) => [
            'inscripcion_id' => $inscripcionId, 'documento' => $p->documento, 'nombre' => $p->nombre, 'tipo' => $p->tipo, 'valor' => $p->valor,
        ], $personas));
    }

    public function insertarSoporte(NuevoSoporte $s): void
    {
        SoporteModelo::query()->create([
            'inscripcion_id' => $s->inscripcionId,
            'medio_pago' => $s->medioPago,
            'cus' => $s->datosMedio->cus,
            'banco' => $s->datosMedio->banco,
            'agencia_pago' => $s->datosMedio->agenciaPago,
            'recibo' => $s->datosMedio->recibo,
            'fecha_pago' => $s->fechaPago,
            'valor_pagado' => $s->valorPagado,
            'campos' => (object) $s->campos,
            'archivo' => $s->archivo,
            'tipo_archivo' => $s->tipoArchivo,
            'nombre_original' => $s->nombreOriginal,
            'alerta' => $s->alerta,
            'autorizacion_version' => $s->versionAutorizacion,
            'cargado_en' => FormatoFecha::aBaseDatos($s->marca),
        ]);
    }

    public function actualizarLiquidacion(int $id, int $total, string $agencia, DateTimeImmutable $marca): void
    {
        InscripcionModelo::query()->whereKey($id)->update(['total' => $total, 'agencia' => $agencia, 'actualizada_en' => FormatoFecha::aBaseDatos($marca)]);
    }

    public function cambiarEstado(int $id, EstadoInscripcion $estado, ?string $motivo, DateTimeImmutable $marca): void
    {
        InscripcionModelo::query()->whereKey($id)->update(['estado' => $estado->value, 'motivo' => $motivo, 'actualizada_en' => FormatoFecha::aBaseDatos($marca)]);
    }

    public function registrarRevision(int $id, CambioDeEstado $cambio, string $usuario, DateTimeImmutable $marca): void
    {
        $momento = FormatoFecha::aBaseDatos($marca);
        InscripcionModelo::query()->whereKey($id)->update([
            'estado' => $cambio->hacia->value, 'motivo' => $cambio->motivo, 'revisado_por' => $usuario,
            'revisado_en' => $momento, 'actualizada_en' => $momento,
        ]);
    }

    public function ajustarCupos(string $agencia, int $cupos, string $usuario, DateTimeImmutable $marca): void
    {
        CupoAjustado::query()->updateOrCreate(['agencia' => $agencia],
            ['cupos' => $cupos, 'actualizado_por' => $usuario, 'actualizado_en' => FormatoFecha::aBaseDatos($marca)]);
    }

    public function quitarAjusteCupos(string $agencia): void
    {
        CupoAjustado::query()->whereKey($agencia)->delete();
    }

    // ---- Lectura ----

    public function porId(int $id): ?Inscripcion
    {
        return self::inscripcionDe(InscripcionModelo::query()->find($id));
    }

    public function porReferencia(string $referencia): ?Inscripcion
    {
        return self::inscripcionDe(InscripcionModelo::query()->where('referencia', $referencia)->first());
    }

    public function deTitular(string $documento): ?Inscripcion
    {
        [$enActivos, $valores] = self::en(EstadoInscripcion::valores(EstadoInscripcion::activos()));

        return self::inscripcionDe(InscripcionModelo::query()->where('documento_titular', $documento)
            ->orderByRaw("CASE WHEN estado IN {$enActivos} THEN 0 ELSE 1 END", $valores)
            ->orderByDesc('id')->first());
    }

    public function todas(): array
    {
        return InscripcionModelo::query()->orderBy('id')->get()->map(self::inscripcionDe(...))->all();
    }

    public function personas(int $inscripcionId): array
    {
        return InscripcionPersona::query()->where('inscripcion_id', $inscripcionId)->orderBy('id')->get()
            ->map(fn (InscripcionPersona $p) => new PersonaInscrita($p->documento, $p->nombre, $p->tipo, $p->valor))->all();
    }

    public function soportes(int $inscripcionId): array
    {
        return SoporteModelo::query()->where('inscripcion_id', $inscripcionId)->orderByDesc('id')->get()->map(self::soporteDe(...))->all();
    }

    public function ultimoSoporte(int $inscripcionId): ?Soporte
    {
        $s = SoporteModelo::query()->where('inscripcion_id', $inscripcionId)->orderByDesc('id')->first();

        return $s ? self::soporteDe($s) : null;
    }

    public function soporte(int $soporteId): ?Soporte
    {
        $s = SoporteModelo::query()->find($soporteId);

        return $s ? self::soporteDe($s) : null;
    }

    public function personaActiva(string $documento, int $excluida = 0): ?array
    {
        $fila = $this->db->table('inscripcion_personas as p')
            ->join('inscripciones as i', 'i.id', '=', 'p.inscripcion_id')
            ->where('p.documento', $documento)
            ->whereIn('i.estado', EstadoInscripcion::valores(EstadoInscripcion::activos()))
            ->where('i.id', '<>', $excluida)
            ->first(['i.referencia', 'i.documento_titular']);

        return $fila ? (array) $fila : null;
    }

    public function cuposDisponibles(string $agencia, int $excluida = 0): int
    {
        $cupos = (int) $this->db->table('tarifas as t')->leftJoin('cupos_ajustados as a', 'a.agencia', '=', 't.agencia')
            ->where('t.agencia', $agencia)->value($this->db->raw('COALESCE(a.cupos, t.cupos)'));
        $ocupados = $this->db->table('inscripcion_personas as p')
            ->join('inscripciones as i', 'i.id', '=', 'p.inscripcion_id')
            ->where('i.agencia', $agencia)
            ->whereIn('i.estado', EstadoInscripcion::valores(EstadoInscripcion::ocupanCupo()))
            ->where('i.id', '<>', $excluida)
            ->whereNotIn('p.tipo', $this->clasificador->tiposSinCupo())
            ->count();

        return $cupos - $ocupados;
    }

    public function cusUsado(string $cus, int $excluida): ?string
    {
        return $this->soporteVigente($excluida)->where('s.medio_pago', 'PSE')->where('s.cus', $cus)->value('i.referencia');
    }

    public function reciboUsado(string $agencia, string $recibo, int $excluida): ?string
    {
        return $this->soporteVigente($excluida)->where('s.medio_pago', 'AGENCIA')->where('s.agencia_pago', $agencia)
            ->where('s.recibo', $recibo)->value('i.referencia');
    }

    private function soporteVigente(int $excluida): \Illuminate\Database\Query\Builder
    {
        return $this->db->table('soportes as s')->join('inscripciones as i', 'i.id', '=', 's.inscripcion_id')
            ->where('i.id', '<>', $excluida)
            ->whereIn('i.estado', EstadoInscripcion::valores(EstadoInscripcion::activos()));
    }

    public function cupos(): array
    {
        [$enCupo, $vCupo] = self::en(EstadoInscripcion::valores(EstadoInscripcion::ocupanCupo()));
        [$enPendientes, $vPendientes] = self::en(EstadoInscripcion::valores(EstadoInscripcion::pendientes()));
        [$sinCupo, $vSinCupo] = self::en($this->clasificador->tiposSinCupo());
        $ocupa = "p.tipo NOT IN {$sinCupo}";
        $filas = $this->db->select("
            SELECT t.agencia, t.cupos AS cupos_excel, a.cupos AS cupos_ajustados, COALESCE(a.cupos, t.cupos) AS cupos,
                   COUNT(CASE WHEN i.estado IN {$enCupo} AND {$ocupa} THEN 1 END) AS ocupados,
                   COUNT(CASE WHEN i.estado IN {$enPendientes} AND {$ocupa} THEN 1 END) AS pendientes,
                   COUNT(CASE WHEN i.estado = ? AND {$ocupa} THEN 1 END) AS confirmados,
                   COUNT(CASE WHEN i.estado IN {$enCupo} AND NOT ({$ocupa}) THEN 1 END) AS invitados
            FROM tarifas t
            LEFT JOIN cupos_ajustados a ON a.agencia = t.agencia
            LEFT JOIN inscripciones i ON i.agencia = t.agencia
            LEFT JOIN inscripcion_personas p ON p.inscripcion_id = i.id
            GROUP BY t.agencia, t.cupos, a.cupos
            ORDER BY t.agencia", [
            ...$vCupo, ...$vSinCupo, ...$vPendientes, ...$vSinCupo, EstadoInscripcion::Confirmado->value, ...$vSinCupo, ...$vCupo, ...$vSinCupo,
        ]);

        return array_map(fn ($f) => [
            'agencia' => $f->agencia,
            'cupos_excel' => (int) $f->cupos_excel,
            'cupos_ajustados' => $f->cupos_ajustados === null ? null : (int) $f->cupos_ajustados,
            'cupos' => (int) $f->cupos,
            'ocupados' => (int) $f->ocupados,
            'pendientes' => (int) $f->pendientes,
            'confirmados' => (int) $f->confirmados,
            'invitados' => (int) $f->invitados,
        ], $filas);
    }

    public function listar(array $filtros): array
    {
        $consulta = InscripcionModelo::query()
            ->select(['id', 'referencia', 'documento_titular', 'nombre_titular', 'agencia', 'total', 'estado', 'creada_en', 'actualizada_en'])
            ->withCount('personas')
            ->addSelect(['alerta' => SoporteModelo::query()->select('alerta')->whereColumn('soportes.inscripcion_id', 'inscripciones.id')
                ->orderByDesc('id')->limit(1)])
            ->when($filtros['estado'] ?? null, fn (Builder $q, $estado) => $q->where('estado', $estado))
            ->when($filtros['agencia'] ?? null, fn (Builder $q, $agencia) => $q->where('agencia', $agencia))
            ->when(trim((string) ($filtros['busqueda'] ?? '')), function (Builder $q, string $busqueda) {
                $patron = '%'.mb_strtolower($busqueda).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('LOWER(referencia) LIKE ?', [$patron])
                    ->orWhereRaw('LOWER(documento_titular) LIKE ?', [$patron])
                    ->orWhereRaw('LOWER(nombre_titular) LIKE ?', [$patron]));
            })
            ->orderByDesc('id')->limit(1000);

        return $consulta->get()->map(fn (InscripcionModelo $i) => [
            'id' => $i->id,
            'referencia' => $i->referencia,
            'documento_titular' => $i->documento_titular,
            'nombre_titular' => $i->nombre_titular,
            'agencia' => $i->agencia,
            'total' => $i->total,
            'estado' => $i->estado,
            'creada_en' => FormatoFecha::aIso($i->creada_en),
            'actualizada_en' => FormatoFecha::aIso($i->actualizada_en),
            'personas' => (int) $i->personas_count,
            'alerta' => $i->alerta,
        ])->all();
    }

    // ---- Conversión a entidades del dominio ----

    /** @param  list<string>  $valores
     *  @return array{0: string, 1: list<string>} "(?, ?, ...)" y sus valores */
    private static function en(array $valores): array
    {
        return ['('.implode(', ', array_fill(0, max(1, count($valores)), '?')).')', $valores ?: ['']];
    }

    private static function inscripcionDe(?InscripcionModelo $i): ?Inscripcion
    {
        return $i ? new Inscripcion(
            id: $i->id,
            referencia: $i->referencia,
            documentoTitular: $i->documento_titular,
            nombreTitular: $i->nombre_titular,
            agencia: $i->agencia,
            agenciaAsociado: $i->agencia_asociado,
            total: $i->total,
            estado: EstadoInscripcion::from($i->estado),
            motivo: $i->motivo,
            revisadoPor: $i->revisado_por,
            revisadoEn: FormatoFecha::aIso($i->revisado_en),
            autorizacionVersion: $i->autorizacion_version,
            autorizacionEn: FormatoFecha::aIso($i->autorizacion_en),
            autorizacionIp: $i->autorizacion_ip,
            autorizacionImagen: (bool) $i->autorizacion_imagen,
            creadaEn: FormatoFecha::aIso($i->creada_en),
            actualizadaEn: FormatoFecha::aIso($i->actualizada_en),
        ) : null;
    }

    private static function soporteDe(SoporteModelo $s): Soporte
    {
        return new Soporte(
            id: $s->id,
            inscripcionId: $s->inscripcion_id,
            medioPago: $s->medio_pago,
            cus: (string) $s->cus,
            banco: (string) $s->banco,
            agenciaPago: $s->agencia_pago,
            recibo: $s->recibo,
            fechaPago: FormatoFecha::fecha($s->fecha_pago),
            valorPagado: $s->valor_pagado,
            campos: (array) ($s->campos ?? []),
            archivo: (string) $s->archivo,
            tipoArchivo: (string) $s->tipo_archivo,
            nombreOriginal: $s->nombre_original,
            alerta: $s->alerta,
            autorizacionVersion: $s->autorizacion_version,
            cargadoEn: FormatoFecha::aIso($s->cargado_en),
        );
    }
}
