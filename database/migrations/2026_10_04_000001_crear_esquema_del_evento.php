<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema del evento. Estados y reglas: app/Dominio/Inscripcion.
 * Fechas calendario en columnas date (AAAA-MM-DD) y marcas de tiempo con zona horaria (se guardan en UTC).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Datos generales del evento (una sola fila). `firma_datos` identifica la versión de data/tarifas.json y
        // data/agencias_evento.json cargada, para recargarlas cuando cambian sin reiniciar.
        Schema::create('evento', function (Blueprint $table) {
            $table->unsignedSmallInteger('id')->primary();
            $table->string('nombre');
            $table->string('inscripciones')->nullable();
            $table->string('cuenta_contable')->nullable();
            $table->string('concepto')->nullable();
            $table->string('firma_datos', 64)->nullable();
        });

        // Tarifas y cupos por evento (agencia). Se cargan desde data/tarifas.json.
        Schema::create('tarifas', function (Blueprint $table) {
            $table->string('agencia', 80)->primary();
            $table->unsignedInteger('cupos');
            $table->unsignedInteger('valor_invitado');
            $table->unsignedInteger('valor_asociado');
        });

        // Agencia o punto de atención -> evento al que asiste (eventos compartidos, p. ej. CARTAGENA Y MAMONAL).
        Schema::create('agencias_evento', function (Blueprint $table) {
            $table->string('agencia', 80)->primary();
            $table->string('evento', 80);
            $table->foreign('evento')->references('agencia')->on('tarifas')->cascadeOnUpdate();
        });

        // Cupos editados desde el panel; tienen prioridad sobre los de tarifas.json.
        Schema::create('cupos_ajustados', function (Blueprint $table) {
            $table->string('agencia', 80)->primary();
            $table->unsignedInteger('cupos');
            $table->string('actualizado_por', 60)->nullable();
            $table->dateTime('actualizado_en')->nullable();
        });

        // Base de asociados. La fecha de expedición nunca se guarda en texto plano: solo su HMAC.
        Schema::create('asociados', function (Blueprint $table) {
            $table->string('documento', 20)->primary();
            $table->string('nombre', 120);
            $table->string('agencia', 80)->index();
            $table->string('estado', 10)->default('ACTIVO');
            $table->date('fecha_actualizacion')->nullable();
            $table->char('expedicion_hmac', 64)->nullable();
            $table->date('fecha_nacimiento')->nullable();
        });

        // Coopetrolitos: hijos de asociados (padre, madre o acudiente).
        Schema::create('coopetrolitos', function (Blueprint $table) {
            $table->string('documento', 20)->primary();
            $table->string('nombre', 120);
            $table->string('documento_asociado', 20)->index();
            $table->date('fecha_nacimiento')->nullable();
        });

        Schema::create('cargas_bases', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->string('archivo', 200)->nullable();
            $table->unsignedInteger('registros');
            $table->string('usuario', 60);
            $table->dateTime('cargada_en');
        });

        Schema::create('inscripciones', function (Blueprint $table) {
            $table->id();
            $table->string('referencia', 60)->unique();
            $table->string('documento_titular', 20)->index();
            $table->string('nombre_titular', 120);
            $table->string('agencia', 80);                     // evento al que asiste (define tarifa y cupo)
            $table->string('agencia_asociado', 80)->nullable(); // agencia a la que pertenece el asociado
            $table->unsignedInteger('total');
            $table->string('estado', 15);
            $table->text('motivo')->nullable();
            $table->string('revisado_por', 60)->nullable();
            $table->dateTime('revisado_en')->nullable();
            $table->string('autorizacion_version', 40)->nullable(); // versión del texto de habeas data aceptado
            $table->dateTime('autorizacion_en')->nullable();
            $table->string('autorizacion_ip', 45)->nullable();
            $table->boolean('autorizacion_imagen')->default(false); // uso de imagen (opcional)
            $table->dateTime('creada_en');
            $table->dateTime('actualizada_en');
            $table->index(['estado', 'agencia']);
        });

        Schema::create('inscripcion_personas', function (Blueprint $table) {
            $table->id(); // conserva el orden en que se registraron
            $table->foreignId('inscripcion_id')->constrained('inscripciones')->cascadeOnDelete();
            $table->string('documento', 20)->index();
            $table->string('nombre', 120);
            $table->string('tipo', 15); // TITULAR, ASOCIADO, COOPETROLITO, INVITADO
            $table->unsignedInteger('valor');
            $table->unique(['inscripcion_id', 'documento']);
        });

        Schema::create('soportes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('inscripciones')->cascadeOnDelete();
            $table->string('medio_pago', 15)->default('PSE');
            $table->string('cus', 20)->default('')->index();
            $table->string('banco', 80)->default('');
            $table->string('agencia_pago', 80)->nullable();
            $table->string('recibo', 30)->nullable();
            $table->date('fecha_pago');
            $table->unsignedBigInteger('valor_pagado');
            $table->json('campos');                          // campos adicionales del formulario
            $table->string('archivo', 80)->default('');      // nombre interno del comprobante ('' si no hay)
            $table->string('tipo_archivo', 40)->default('');
            $table->string('nombre_original', 120)->nullable();
            $table->string('alerta')->nullable();
            $table->string('autorizacion_version', 40)->nullable();
            $table->dateTime('cargado_en');
        });

        // Comprobantes guardados en la base (almacén 'base_datos').
        Schema::create('soportes_archivos', function (Blueprint $table) {
            $table->string('nombre', 80)->primary();
            $table->binary('contenido');
            $table->dateTime('creado_en');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            Schema::getConnection()->statement('ALTER TABLE soportes_archivos MODIFY contenido LONGBLOB NOT NULL');
        }

        Schema::create('administradores', function (Blueprint $table) {
            $table->string('usuario', 40)->primary();
            $table->string('nombre', 100);
            $table->string('clave');                  // hash de Laravel (bcrypt)
            $table->string('clave_salt', 64)->nullable(); // solo claves scrypt importadas de la versión anterior
            $table->string('rol', 15)->default('ADMINISTRADOR');
        });

        // Cambios manuales hechos desde el panel (quién, qué y cuándo).
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->string('usuario', 60);
            $table->string('accion', 10);  // CREAR, EDITAR, ELIMINAR
            $table->string('entidad', 20); // asociado, coopetrolito, usuario
            $table->string('clave', 60);
            $table->string('detalle')->nullable();
            $table->dateTime('fecha');
        });
    }

    public function down(): void
    {
        foreach (['auditoria', 'administradores', 'soportes_archivos', 'soportes', 'inscripcion_personas', 'inscripciones',
            'cargas_bases', 'coopetrolitos', 'asociados', 'cupos_ajustados', 'agencias_evento', 'tarifas', 'evento'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
