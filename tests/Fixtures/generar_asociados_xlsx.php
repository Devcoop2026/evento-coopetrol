<?php

/*
| Genera tests/Fixtures/asociados.xlsx (base de asociados ficticia para BasesTest). Se ejecutó una vez y el .xlsx queda
| en el repositorio; vuelva a correrlo solo si cambia el contenido:  php tests/Fixtures/generar_asociados_xlsx.php
|
| Ejercita el lector nativo: textos en sharedStrings, documentos como números y fechas como número de serie de Excel.
|   Fila 2: 5550001 válido y activo (fechas como texto DD/MM/AAAA, expedición 20/05/2001)
|   Fila 3: 5550002 "Prueba Dos" activo, actualización 2026-06-01 y expedición 1999-08-15 como números de serie
|   Fila 4: 5550003 inactivo y sin fecha de expedición
|   Fila 5: agencia MARTE (no existe)
|   Fila 6: documento 5550001 repetido
*/

$serial = fn (string $fecha) => (int) ((strtotime("{$fecha} UTC") - strtotime('1899-12-30 UTC')) / 86400);

$filas = [
    ['Cedula', 'Nombre', 'Agencia', 'Asociado', 'Ultima actualizacion de datos', 'Fecha expedicion'],
    [5550001, 'Prueba Uno', 'BOGOTA', 'SI', '15/03/2026', '20/05/2001'],
    [5550002, 'Prueba Dos', 'BOGOTA', 'Si', $serial('2026-06-01'), $serial('1999-08-15')],
    [5550003, 'Prueba Tres', 'CALI', 'NO', '01/02/2026', null],
    [5550004, 'Prueba Cuatro', 'MARTE', 'SI', '01/02/2026', '01/01/2000'],
    [5550001, 'Prueba Repetida', 'BOGOTA', 'SI', '01/02/2026', '01/01/2000'],
];

$compartidas = [];
$indice = function (string $texto) use (&$compartidas): int {
    $i = array_search($texto, $compartidas, true);
    if ($i === false) {
        $compartidas[] = $texto;
        $i = count($compartidas) - 1;
    }

    return $i;
};

$filasXml = '';
foreach ($filas as $n => $fila) {
    $celdas = '';
    foreach ($fila as $c => $valor) {
        $ref = chr(65 + $c).($n + 1);
        if ($valor === null) {
            continue;
        }
        $celdas .= is_int($valor) ? "<c r=\"{$ref}\"><v>{$valor}</v></c>" : "<c r=\"{$ref}\" t=\"s\"><v>{$indice($valor)}</v></c>";
    }
    $filasXml .= '<row r="'.($n + 1)."\">{$celdas}</row>";
}

$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
$sst = implode('', array_map(fn ($t) => '<si><t>'.htmlspecialchars($t, ENT_XML1).'</t></si>', $compartidas));
$archivos = [
    '[Content_Types].xml' => $xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        .'</Types>',
    '_rels/.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>',
    'xl/workbook.xml' => $xml.'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Asociados" sheetId="1" r:id="rId1"/></sheets></workbook>',
    'xl/_rels/workbook.xml.rels' => $xml.'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        .'</Relationships>',
    'xl/sharedStrings.xml' => $xml.'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($compartidas)
        .'" uniqueCount="'.count($compartidas).'">'.$sst.'</sst>',
    'xl/worksheets/sheet1.xml' => $xml.'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        ."<sheetData>{$filasXml}</sheetData></worksheet>",
];

$destino = __DIR__.'/asociados.xlsx';
@unlink($destino);
$zip = new ZipArchive;
if ($zip->open($destino, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "No se pudo crear {$destino}\n");
    exit(1);
}
foreach ($archivos as $nombre => $contenido) {
    $zip->addFromString($nombre, $contenido);
    $zip->setCompressionName($nombre, ZipArchive::CM_DEFLATE);
}
$zip->close();
echo "Generado {$destino}\n";
