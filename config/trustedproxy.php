<?php

/*
| Proxies de confianza para tomar la IP real del cliente (X-Forwarded-For) y el esquema HTTPS (X-Forwarded-Proto).
| En Render: TRUSTED_PROXIES=* (el balanceador inmediato). Vacío = conexión directa (sin proxy).
| La IP se usa en los límites de intentos, en la restricción del panel por red y en la autorización de habeas data.
*/

return [
    'proxies' => env('TRUSTED_PROXIES') ?: null,
];
