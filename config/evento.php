<?php

/*
| Configuración del Evento Fin de Año Coopetrol.
| Los datos del evento que cambian sin programar (fechas, enlaces, formulario de pago, habeas data, tarifas) están en
| los JSON de data/. Aquí solo van las variables de entorno y los parámetros técnicos.
*/

return [

    // Carpeta con los JSON de configuración (config.json, formulario_soporte.json, habeas_data.json, tarifas.json,
    // agencias_evento.json). EVENTO_CONFIG permite reemplazar config.json por otro archivo (p. ej. un ambiente de pruebas).
    'datos' => env('EVENTO_DATOS', base_path('data')),
    'config' => env('EVENTO_CONFIG'),

    // Clave HMAC de las fechas de expedición (mínimo 32 caracteres). Obligatoria en producción.
    // Si cambia, hay que volver a cargar la base de asociados.
    'secreto' => env('EVENTO_SECRETO'),

    'max_acompanantes' => (int) env('MAX_ACOMPANANTES', 5),
    'meses_vigencia_datos' => (int) env('MESES_VIGENCIA_DATOS', 12),

    // Comprobantes de pago: 'base_datos' (por defecto; sirve en Render sin disco persistente) o el nombre de un disco de
    // config/filesystems.php ('soportes' en disco local, o un disco s3 / Cloudflare R2).
    'almacen_soportes' => env('SOPORTES_ALMACEN', 'base_datos'),

    // Límites de intentos. Son amplios para no afectar a varias personas que salen a internet por la misma IP (una agencia).
    'limites' => [
        'login_por_ip' => (int) env('LIMITE_LOGIN_IP', 10),           // ingresos al panel cada 15 minutos
        'identidad_por_ip' => (int) env('LIMITE_IDENTIDAD_IP', 20),   // fallos de documento + fecha cada 15 minutos
        'identidad_por_documento' => 10,                               // fallos por documento: bloqueo de 15 minutos
        'solicitudes_por_ip' => (int) env('LIMITE_SOLICITUDES_IP', 600), // solicitudes cada 5 minutos
    ],

    // Restricción del panel a redes internas (CIDR separados por coma). Vacío = sin restricción.
    'panel_redes' => env('PANEL_REDES'),

    'respaldo' => [
        'dir' => env('RESPALDO_DIR', base_path('respaldos')),
        'retencion_dias' => (int) env('RETENCION_DIAS', 30),
        'clave' => env('RESPALDO_CLAVE'),   // 64 hexadecimales: cifra los respaldos con AES-256-GCM
        'pg_dump' => env('PG_DUMP', 'pg_dump'),
    ],
];
