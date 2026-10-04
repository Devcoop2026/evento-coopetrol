<?php

namespace App\Infraestructura\Excel;

use App\Aplicacion\Bases\LectorHojaCalculo;
use RuntimeException;

/**
 * Lectura de hojas de cálculo sin dependencias: .xlsx (ZIP + XML) y .csv (separado por ; o ,). Devuelve la primera
 * hoja como matriz de filas. Las celdas numéricas llegan como número (las fechas de Excel son números de serie).
 * El formato se detecta por el contenido, no por la extensión.
 */
final class LectorHojaCalculoNativo implements LectorHojaCalculo
{
    private const MAX_DESCOMPRIMIDO = 200 * 1024 * 1024; // protección contra archivos ZIP maliciosos

    public function leer(string $contenido): array
    {
        if ($contenido === '') {
            throw new RuntimeException('El archivo está vacío.');
        }

        return str_starts_with($contenido, "PK\x03\x04") ? $this->leerXlsx($contenido) : $this->leerCsv($contenido);
    }

    /** Además de las filas, `numeros` trae el número de fila de Excel de cada una (para leer celdas por posición). */
    public function leerConNumeros(string $contenido): array
    {
        return str_starts_with($contenido, "PK\x03\x04") ? $this->leerXlsx($contenido) : [...$this->leerCsv($contenido), 'numeros' => []];
    }

    /** @return array<string, callable(): string> archivo -> lector perezoso */
    private function leerZip(string $zip): array
    {
        $largo = strlen($zip);
        $fin = -1;
        for ($i = $largo - 22; $i >= max(0, $largo - 65557); $i--) {
            if (substr($zip, $i, 4) === "PK\x05\x06") {
                $fin = $i;
                break;
            }
        }
        if ($fin < 0) {
            throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
        }
        $total = unpack('v', $zip, $fin + 10)[1];
        $pos = unpack('V', $zip, $fin + 16)[1];
        $archivos = [];
        $descomprimido = 0;
        for ($n = 0; $n < $total; $n++) {
            if ($pos + 46 > $largo || substr($zip, $pos, 4) !== "PK\x01\x02") {
                throw new RuntimeException('El archivo Excel está dañado.');
            }
            $c = unpack('vmetodo/x8/Vcomprimido/Vtamano/vnombre/vextra/vcomentario/x8/Vlocal', $zip, $pos + 10);
            $nombre = substr($zip, $pos + 46, $c['nombre']);
            $pos += 46 + $c['nombre'] + $c['extra'] + $c['comentario'];
            $descomprimido += $c['tamano'];
            if ($descomprimido > self::MAX_DESCOMPRIMIDO) {
                throw new RuntimeException('El archivo Excel es demasiado grande.');
            }
            $archivos[$nombre] = function () use ($zip, $c) {
                $l = unpack('vnombre/vextra', $zip, $c['local'] + 26);
                $datos = substr($zip, $c['local'] + 30 + $l['nombre'] + $l['extra'], $c['comprimido']);
                if ($c['metodo'] === 0) {
                    return $datos;
                }
                if ($c['metodo'] === 8) {
                    $salida = @gzinflate($datos, self::MAX_DESCOMPRIMIDO);

                    return $salida === false ? throw new RuntimeException('El archivo Excel está dañado.') : $salida;
                }
                throw new RuntimeException('Compresión de Excel no soportada.');
            };
        }

        return $archivos;
    }

    private static function texto(string $xml): string
    {
        preg_match_all('#<t(?:\s[^>]*)?>(.*?)</t>#s', $xml, $m);

        return html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function columna(string $referencia): int
    {
        $n = 0;
        foreach (str_split(preg_replace('/\d+/', '', $referencia)) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }

        return $n - 1;
    }

    private function leerXlsx(string $contenido): array
    {
        $zip = $this->leerZip($contenido);
        $leer = fn (string $nombre) => isset($zip[$nombre]) ? $zip[$nombre]() : null;

        $libro = $leer('xl/workbook.xml') ?? throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
        $fecha1904 = (bool) preg_match('/date1904="(1|true)"/', $libro);
        preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $libro, $m);
        $idHoja = $m[1] ?? null;
        $destino = 'worksheets/sheet1.xml';
        preg_match_all('/<Relationship\b[^>]*>/', $leer('xl/_rels/workbook.xml.rels') ?? '', $relaciones);
        foreach ($relaciones[0] as $relacion) {
            if ($idHoja && str_contains($relacion, "Id=\"{$idHoja}\"") && preg_match('/Target="([^"]+)"/', $relacion, $t)) {
                $destino = $t[1];
                break;
            }
        }
        $rutaHoja = str_starts_with($destino, '/') ? substr($destino, 1) : 'xl/'.preg_replace('#^\./#', '', $destino);

        preg_match_all('#<si>(.*?)</si>#s', $leer('xl/sharedStrings.xml') ?? '', $si);
        $compartidas = array_map(self::texto(...), $si[1]);
        $hoja = $leer($rutaHoja) ?? throw new RuntimeException('No se encontró la primera hoja del Excel.');

        $filas = [];
        $numeros = [];
        // Las filas vacías pueden venir autocerradas (<row r="1"/>): no deben absorber la fila siguiente.
        preg_match_all('#<row\b([^>]*?)(?:/>|>(.*?)</row>)#s', $hoja, $filasXml, PREG_SET_ORDER);
        foreach ($filasXml as $filaXml) {
            $atributosFila = $filaXml[1];
            $celdasXml = $filaXml[2] ?? '';
            $fila = [];
            preg_match_all('#<c\b([^>]*?)(?:/>|>(.*?)</c>)#s', $celdasXml, $celdas, PREG_SET_ORDER);
            foreach ($celdas as $celda) {
                $atributos = $celda[1];
                $cuerpo = $celda[2] ?? '';
                $ref = preg_match('/\br="([A-Z]+\d+)"/', $atributos, $r) ? $r[1] : null;
                $tipo = preg_match('/\bt="(\w+)"/', $atributos, $t) ? $t[1] : null;
                $v = preg_match('#<v>(.*?)</v>#s', $cuerpo, $vv) ? $vv[1] : null;
                $valor = match (true) {
                    $tipo === 's' => $compartidas[(int) $v] ?? '',
                    $tipo === 'inlineStr' => self::texto($cuerpo),
                    $tipo === 'str' || $tipo === 'e' => $v === null ? null : html_entity_decode($v, ENT_QUOTES | ENT_XML1, 'UTF-8'),
                    $tipo === 'b' => $v === '1',
                    $v !== null && is_numeric($v) => $v + 0,
                    default => $v,
                };
                $fila[$ref ? self::columna($ref) : count($fila)] = $valor;
            }
            $lista = [];
            for ($i = 0, $max = $fila ? max(array_keys($fila)) : -1; $i <= $max; $i++) {
                $lista[] = $fila[$i] ?? null;
            }
            $filas[] = $lista;
            $numeros[] = preg_match('/\br="(\d+)"/', $atributosFila, $nr) ? (int) $nr[1] : count($filas);
        }

        return ['filas' => $filas, 'fecha1904' => $fecha1904, 'numeros' => $numeros];
    }

    private function leerCsv(string $contenido): array
    {
        // CSV guardado desde Excel en Windows: si no es UTF-8 válido, viene en Windows-1252.
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }
        $contenido = preg_replace('/^\x{FEFF}/u', '', $contenido);
        $primera = strtok($contenido, "\r\n") ?: '';
        $separador = substr_count($primera, ';') > substr_count($primera, ',') ? ';' : ',';

        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);
        $filas = [];
        while (($fila = fgetcsv($flujo, null, $separador, '"', '')) !== false) {
            $filas[] = array_map(fn ($c) => $c ?? '', $fila);
        }
        fclose($flujo);

        return ['filas' => $filas, 'fecha1904' => false];
    }
}
