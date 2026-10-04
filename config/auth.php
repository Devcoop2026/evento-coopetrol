<?php

/*
| Autenticación del panel: sesión de Laravel (guard "web") con los usuarios de la tabla administradores.
| El proveedor "administradores" (App\Infraestructura\Seguridad\ProveedorAdministradores) acepta además las claves
| scrypt importadas de la versión anterior y las convierte al hash de Laravel al ingresar.
| No hay recuperación de clave por correo: un administrador la cambia desde el panel o con php artisan evento:usuario.
*/

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => null,
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'administradores',
        ],
    ],

    'providers' => [
        'administradores' => [
            'driver' => 'administradores',
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
