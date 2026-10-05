<?php

namespace App\Infraestructura\Excel;

use RuntimeException;
use ZipArchive;

final class GeneradorXlsx
{
    public function desdeCsv(string $csv): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP es necesaria para generar plantillas Excel.');
        }

        $filas = [];
        $flujo = fopen('php://temp', 'r+');
        fwrite($flujo, ltrim($csv, "\xEF\xBB\xBF"));
        rewind($flujo);
        while (($fila = fgetcsv($flujo, null, ';', '"', '')) !== false) {
            if ($fila !== [null]) {
                $filas[] = $fila;
            }
        }
        fclose($flujo);

        return $this->desdeFilas($filas);
    }

    /** @param list<list<mixed>> $filas */
    public function desdeFilas(array $filas): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('La extensión ZIP de PHP es necesaria para generar archivos Excel.');
        }

        $archivo = tempnam(sys_get_temp_dir(), 'excel-');
        $zip = new ZipArchive;
        if ($archivo === false || $zip->open($archivo, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No fue posible crear el archivo Excel.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($filas));
        $zip->close();

        $contenido = file_get_contents($archivo);
        unlink($archivo);

        return $contenido === false ? throw new RuntimeException('No fue posible leer el archivo Excel.') : $contenido;
    }

    /** @param list<list<mixed>> $filas */
    private function sheet(array $filas): string
    {
        $filasXml = [];
        foreach ($filas as $numero => $fila) {
            $celdas = [];
            foreach ($fila as $columna => $valor) {
                $referencia = $this->columna($columna).($numero + 1);
                $texto = htmlspecialchars((string) ($valor ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
                $celdas[] = "<c r=\"{$referencia}\" t=\"inlineStr\"><is><t>{$texto}</t></is></c>";
            }
            $filasXml[] = '<row r="'.($numero + 1).'">'.implode('', $celdas).'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheetData>'.implode('', $filasXml).'</sheetData></worksheet>';
    }

    private function columna(int $indice): string
    {
        $resultado = '';
        do {
            $resultado = chr(65 + ($indice % 26)).$resultado;
            $indice = intdiv($indice, 26) - 1;
        } while ($indice >= 0);

        return $resultado;
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Plantilla" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>';
    }
}
