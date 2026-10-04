<?php

namespace App\Http\Middleware;

use App\Infraestructura\Configuracion\SincronizadorTarifas;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Recarga data/tarifas.json y data/agencias_evento.json en la base si cambiaron (sin reiniciar el servicio). */
class SincronizarTarifas
{
    public function __construct(private readonly SincronizadorTarifas $sincronizador) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->sincronizador->sincronizar();

        return $next($request);
    }
}
