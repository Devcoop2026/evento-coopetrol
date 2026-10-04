<?php

namespace App\Aplicacion\Bases;

use App\Dominio\Compartido\ErrorValidacion;
use App\Dominio\Compartido\Reglas;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\Valores;
use App\Dominio\Padron\RepositorioBases;
use App\Dominio\Seguridad\HuellaFecha;
use RuntimeException;

/**
 * Valida un archivo de la base de asociados o de Coopetrolitos y devuelve los registros listos para guardar, sin tocar
 * la base. Las filas no válidas se omiten con su motivo; los datos personales solo pasan por memoria.
 */
final class AnalizarBase
{
    private const VALORES_SI = ['si', 's', 'x', '1', 'activo', 'true', 'verdadero'];

    public function __construct(
        private readonly LectorHojaCalculo $lector,
        private readonly RepositorioBases $bases,
        private readonly HuellaFecha $huella,
        private readonly Reloj $reloj,
    ) {}

    /** @return array{registros: list<array>, errores: list<string>, advertencias: list<string>} */
    public function ejecutar(string $tipo, string $contenido): array
    {
        $definicion = DefinicionesBase::de($tipo);
        try {
            ['filas' => $filas, 'fecha1904' => $fecha1904] = $this->lector->leer($contenido);
        } catch (RuntimeException $error) {
            throw new ErrorValidacion($error->getMessage());
        }
        $iFila = null;
        foreach ($filas as $i => $fila) {
            if (self::tieneDatos($fila)) {
                $iFila = $i;
                break;
            }
        }
        if ($iFila === null) {
            throw new ErrorValidacion('El archivo no tiene datos.');
        }
        $encabezado = array_map(self::normalizar(...), $filas[$iFila]);
        $indice = [];
        $faltantes = [];
        foreach ($definicion['columnas'] as $campo => $nombres) {
            $posicion = null;
            foreach ($encabezado as $k => $h) {
                if (in_array($h, $nombres, true)) {
                    $posicion = $k;
                    break;
                }
            }
            if ($posicion === null && ! in_array($campo, $definicion['opcionales'], true)) {
                $faltantes[] = $nombres[0];
            }
            $indice[$campo] = $posicion;
        }
        if ($faltantes) {
            throw new ErrorValidacion('Faltan columnas: '.implode(', ', $faltantes).'. Revise la primera fila del archivo.');
        }

        $agenciasValidas = $tipo === 'asociados' ? array_flip($this->bases->agenciasValidas()) : [];
        $asociados = $tipo === 'coopetrolitos' ? array_flip($this->bases->documentosAsociados()) : [];
        $hoy = Valores::fechaColombia($this->reloj->ahora());
        $registros = [];
        $errores = [];
        $advertencias = [];
        $vistos = [];
        $sinExpedicion = 0;
        $sinAsociado = 0;
        $agenciasDesconocidas = []; // agencia -> filas, para resumirlas aunque la lista de errores se recorte

        foreach (array_slice($filas, $iFila + 1) as $k => $fila) {
            $n = $iFila + $k + 2; // número de fila como lo ve el usuario en Excel
            if (! self::tieneDatos($fila)) {
                continue;
            }
            $v = fn (string $campo) => $indice[$campo] !== null ? ($fila[$indice[$campo]] ?? null) : null;
            $doc = self::documento($v('documento'));
            if ($doc === '') {
                $errores[] = "Fila {$n}: sin documento";

                continue;
            }
            if (! Reglas::esNumerico($doc)) {
                $errores[] = "Fila {$n}: el documento \"{$doc}\" solo debe contener números";

                continue;
            }
            $nombre = self::texto($v('nombre'));
            if ($nombre === '') {
                $errores[] = "Fila {$n}: sin nombre";

                continue;
            }
            if (! Reglas::esTexto($nombre)) {
                $errores[] = "Fila {$n}: el nombre \"{$nombre}\" solo debe contener letras y espacios";

                continue;
            }
            if (isset($vistos[$doc])) {
                $errores[] = "Fila {$n}: documento {$doc} repetido (se conserva el primero)";

                continue;
            }
            try {
                $nacimiento = self::fecha($v('fecha_nacimiento'), $fecha1904);
                if ($nacimiento && ($nacimiento < '1900-01-01' || $nacimiento > $hoy)) {
                    $errores[] = "Fila {$n}: la fecha de nacimiento {$nacimiento} no es válida";

                    continue;
                }
                if ($tipo === 'asociados') {
                    $agencia = mb_strtoupper(self::texto($v('agencia')));
                    if (! isset($agenciasValidas[$agencia])) {
                        $agenciasDesconocidas[$agencia] = ($agenciasDesconocidas[$agencia] ?? 0) + 1;
                        $errores[] = "Fila {$n}: la agencia \"{$agencia}\" no existe en las tarifas ni en data/agencias_evento.json";

                        continue;
                    }
                    $expedicion = self::fecha($v('fecha_expedicion'), $fecha1904);
                    if (! $expedicion) {
                        $sinExpedicion++;
                    }
                    $esAsociado = $v('asociado');
                    $esAsociado = is_bool($esAsociado) ? ($esAsociado ? 'si' : 'no') : self::normalizar($esAsociado);
                    $registros[] = [
                        'documento' => $doc,
                        'nombre' => $nombre,
                        'agencia' => $agencia,
                        'estado' => in_array($esAsociado, self::VALORES_SI, true) ? 'ACTIVO' : 'INACTIVO',
                        'fecha_actualizacion' => self::fecha($v('fecha_actualizacion'), $fecha1904),
                        'expedicion_hmac' => $expedicion ? $this->huella->calcular($expedicion) : null,
                        'fecha_nacimiento' => $nacimiento,
                    ];
                } else {
                    $padre = self::documento($v('documento_asociado'));
                    if ($padre === '') {
                        $errores[] = "Fila {$n}: sin cédula del asociado";

                        continue;
                    }
                    if (! Reglas::esNumerico($padre)) {
                        $errores[] = "Fila {$n}: la cédula del asociado \"{$padre}\" solo debe contener números";

                        continue;
                    }
                    if (! isset($asociados[$padre])) {
                        $sinAsociado++;
                    }
                    $registros[] = ['documento' => $doc, 'nombre' => $nombre, 'documento_asociado' => $padre, 'fecha_nacimiento' => $nacimiento];
                }
                $vistos[$doc] = true;
            } catch (FechaNoValida $error) {
                $errores[] = "Fila {$n}: {$error->getMessage()}";
            }
        }

        if ($sinExpedicion) {
            $advertencias[] = "{$sinExpedicion} asociado(s) sin fecha de expedición: no podrán identificarse.";
        }
        if ($agenciasDesconocidas) {
            arsort($agenciasDesconocidas);
            $lista = implode(', ', array_map(fn ($a, $n) => ($a === '' ? '(vacía)' : $a)." ({$n})", array_keys($agenciasDesconocidas), $agenciasDesconocidas));
            $advertencias[] = "Agencias no reconocidas: {$lista}. Agréguelas a data/agencias_evento.json o corrija el archivo.";
        }
        if ($sinAsociado) {
            $advertencias[] = "{$sinAsociado} Coopetrolito(s) con una cédula de asociado que no está en la base de asociados.";
        }
        if (! $registros) {
            throw new ErrorValidacion('El archivo no tiene registros válidos. '.implode('. ', array_slice($errores, 0, 3)));
        }

        return ['registros' => $registros, 'errores' => $errores, 'advertencias' => $advertencias];
    }

    private static function tieneDatos(array $fila): bool
    {
        foreach ($fila as $celda) {
            if ($celda !== null && trim(Valores::cadena($celda)) !== '') {
                return true;
            }
        }

        return false;
    }

    /** Encabezados y valores SI/NO: sin tildes, en minúsculas, con . y _ como espacios. */
    public static function normalizar(mixed $texto): string
    {
        $t = strtr(mb_strtolower(Valores::cadena($texto)), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
        ]);

        return trim(preg_replace('/\s+/u', ' ', str_replace(['.', '_'], ' ', $t)));
    }

    private static function documento(mixed $valor): string
    {
        return mb_strtoupper(preg_replace('/[\s.,-]/u', '', Valores::cadena($valor)));
    }

    private static function texto(mixed $valor): string
    {
        return trim(preg_replace('/\s+/u', ' ', Valores::cadena($valor)));
    }

    /** Fecha de Excel (número de serie), DD/MM/AAAA, DD-MM-AAAA o AAAA-MM-DD -> AAAA-MM-DD. */
    public static function fecha(mixed $valor, bool $fecha1904 = false): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            $base = $fecha1904 ? gmmktime(0, 0, 0, 1, 1, 1904) : gmmktime(0, 0, 0, 12, 30, 1899);

            return gmdate('Y-m-d', $base + (int) round($valor) * 86400);
        }
        $t = explode(' ', trim(Valores::cadena($valor)))[0];
        if (preg_match('#^(\d{1,2})[/-](\d{1,2})[/-](\d{2}|\d{4})$#D', $t, $m)) {
            $anio = strlen($m[3]) === 2 ? "20{$m[3]}" : $m[3];

            return self::validarFecha(sprintf('%s-%02d-%02d', $anio, $m[2], $m[1]), $valor);
        }
        if (preg_match('#^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$#D', $t, $m)) {
            return self::validarFecha(sprintf('%s-%02d-%02d', $m[1], $m[2], $m[3]), $valor);
        }
        throw new FechaNoValida('fecha no reconocida: "'.Valores::cadena($valor).'"');
    }

    private static function validarFecha(string $iso, mixed $original): string
    {
        if (! Valores::esFechaValida($iso)) {
            throw new FechaNoValida('fecha no válida: "'.Valores::cadena($original).'"');
        }

        return $iso;
    }
}
