<?php

namespace App\Http\Middleware;

use App\Dominio\Seguridad\RegistroSeguridad;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restringe el panel a las redes de PANEL_REDES (CIDR separados por coma, IPv4 o IPv6). Sin la variable no restringe.
 * Es middleware persistente de Livewire: también aplica a las acciones de los componentes del panel.
 */
class RestringirRedPanel
{
    public function __construct(private readonly RegistroSeguridad $registro) {}

    public function handle(Request $request, Closure $next): Response
    {
        $redes = array_filter(array_map('trim', explode(',', (string) config('evento.panel_redes'))));
        if ($redes && ! IpUtils::checkIp((string) $request->ip(), $redes)) {
            $this->registro->registrar('acceso_panel_denegado', ['ip' => $request->ip(), 'ruta' => '/'.$request->path()]);
            abort(403, 'El panel solo está disponible desde la red de Coopetrol.');
        }

        return $next($request);
    }
}
