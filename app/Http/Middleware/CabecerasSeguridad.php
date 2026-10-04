<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas. Política de contenido estricta: solo recursos propios y las fuentes
 * de Google; los scripts y estilos en línea solo con el nonce de la solicitud (Livewire lo toma de Vite::cspNonce()).
 * Los atributos style (que Livewire y Alpine aplican al mostrar u ocultar elementos) se permiten con style-src-attr.
 * Los comprobantes de pago definen su propia política (sandbox) en ControladorComprobantes.
 */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $respuesta = $next($request);

        $cabeceras = [
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
        if (! $respuesta->headers->has('Content-Security-Policy')) {
            $cabeceras['Content-Security-Policy'] = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'nonce-{$nonce}'",
                "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com",
                "style-src-attr 'unsafe-inline'",
                'font-src https://fonts.gstatic.com',
                "img-src 'self' data: blob:",
                "connect-src 'self'",
                "frame-src 'self'",
                "worker-src 'self'",
                "manifest-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]);
        }
        foreach ($cabeceras as $nombre => $valor) {
            $respuesta->headers->set($nombre, $valor, false);
        }

        return $respuesta;
    }
}
