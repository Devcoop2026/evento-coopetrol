<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Las migraciones crean el esquema completo desde cero. No usa RefreshDatabase: deja la base migrada (y vacía), que es
 * el estado que esperan las demás pruebas.
 */
class MigracionesTest extends TestCase
{
    private const ESQUEMA = [
        'sessions' => ['id', 'user_id', 'payload', 'last_activity'],
        'cache' => ['key', 'value', 'expiration'],
        'cache_locks' => ['key', 'owner', 'expiration'],
        'evento' => ['id', 'nombre', 'firma_datos'],
        'tarifas' => ['agencia', 'cupos', 'valor_invitado', 'valor_asociado'],
        'agencias_evento' => ['agencia', 'evento'],
        'cupos_ajustados' => ['agencia', 'cupos', 'actualizado_por', 'actualizado_en'],
        'asociados' => ['documento', 'nombre', 'agencia', 'estado', 'fecha_actualizacion', 'expedicion_hmac', 'fecha_nacimiento'],
        'coopetrolitos' => ['documento', 'nombre', 'documento_asociado', 'fecha_nacimiento'],
        'cargas_bases' => ['id', 'tipo', 'archivo', 'registros', 'usuario', 'cargada_en'],
        'inscripciones' => ['id', 'referencia', 'documento_titular', 'agencia', 'agencia_asociado', 'total', 'estado', 'motivo',
            'autorizacion_version', 'autorizacion_en', 'autorizacion_ip', 'autorizacion_imagen', 'creada_en', 'actualizada_en'],
        'inscripcion_personas' => ['id', 'inscripcion_id', 'documento', 'nombre', 'tipo', 'valor'],
        'soportes' => ['id', 'inscripcion_id', 'medio_pago', 'cus', 'banco', 'agencia_pago', 'recibo', 'fecha_pago', 'valor_pagado',
            'campos', 'archivo', 'tipo_archivo', 'nombre_original', 'alerta', 'autorizacion_version', 'cargado_en'],
        'soportes_archivos' => ['nombre', 'contenido', 'creado_en'],
        'administradores' => ['usuario', 'nombre', 'clave', 'clave_salt', 'rol'],
        'auditoria' => ['id', 'usuario', 'accion', 'entidad', 'clave', 'detalle', 'fecha'],
    ];

    public function test_migrate_fresh_crea_todas_las_tablas(): void
    {
        $this->artisan('migrate:fresh')->assertExitCode(0);

        foreach (self::ESQUEMA as $tabla => $columnas) {
            $this->assertTrue(Schema::hasTable($tabla), "Falta la tabla {$tabla}.");
            $this->assertTrue(Schema::hasColumns($tabla, $columnas), "Faltan columnas en {$tabla}.");
        }
    }
}
