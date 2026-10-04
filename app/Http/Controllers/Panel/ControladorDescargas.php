<?php

namespace App\Http\Controllers\Panel;

use App\Aplicacion\Bases\DefinicionesBase;
use App\Aplicacion\Panel\ExportarInscripciones;
use App\Aplicacion\Panel\ObtenerComprobante;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/** Archivos que descarga el panel: comprobantes de pago, exportación de inscripciones y plantillas de las bases. */
class ControladorDescargas extends Controller
{
    /**
     * Comprobante aislado: las imágenes no pueden ejecutar nada (CSP sandbox) y los PDF se abren en el visor aislado del
     * navegador, sin acceso a la página ni a la sesión. Solo para usuarios del panel; nunca se guarda en caché.
     */
    public function comprobante(int $soporte, ObtenerComprobante $obtener): Response
    {
        ['contenido' => $contenido, 'tipo' => $tipo, 'nombre' => $nombre] = $obtener->ejecutar($soporte);
        $cabeceras = [
            'Content-Type' => $tipo->value,
            'Content-Disposition' => 'inline; filename="'.rawurlencode($nombre).'"',
            'Cache-Control' => 'private, no-store',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ];
        if ($tipo->esImagen()) {
            $cabeceras['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        return response($contenido, 200, $cabeceras);
    }

    /** CSV de inscripciones para Excel (con BOM y fórmulas neutralizadas). */
    public function exportar(ExportarInscripciones $exportar, Reloj $reloj): Response
    {
        $fecha = Valores::fechaColombia($reloj->ahora());

        return response($exportar->ejecutar(), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"inscripciones-{$fecha}.csv\"",
            'Cache-Control' => 'no-store',
        ]);
    }

    public function plantilla(string $tipo): Response
    {
        return response(DefinicionesBase::plantilla($tipo), 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"plantilla-{$tipo}.csv\"",
        ]);
    }
}
