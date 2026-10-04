<?php

namespace App\Console\Commands;

use App\Infraestructura\Configuracion\SincronizadorTarifas;
use App\Infraestructura\Excel\LectorHojaCalculoNativo;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Genera data/tarifas.json desde la primera hoja del Excel de tarifas (docs/Evento.xlsx) y lo carga en la base.
 * Celdas: B3 periodo de inscripciones, B4 nombre del evento, C2 cuenta contable, D2 concepto; desde la fila 6, columnas
 * B a E: agencia (evento), cupos, valor evento invitado y valor asumido por asociado.
 */
final class ImportarTarifas extends Command
{
    protected $signature = 'evento:tarifas
        {archivo? : Excel de tarifas (por defecto docs/Evento.xlsx)}
        {--solo-recargar : No lee el Excel; solo vuelve a cargar data/tarifas.json en la base}';

    protected $description = 'Genera data/tarifas.json desde el Excel de tarifas y lo carga en la base';

    public function handle(LectorHojaCalculoNativo $lector, SincronizadorTarifas $sincronizador): int
    {
        $destino = rtrim((string) config('evento.datos'), '/\\').'/tarifas.json';
        try {
            if (! $this->option('solo-recargar')) {
                $ruta = (string) ($this->argument('archivo') ?? base_path('docs/Evento.xlsx'));
                if (! is_file($ruta)) {
                    $this->error("No se encontró el archivo {$ruta}.");

                    return self::FAILURE;
                }
                $tarifas = self::desdeExcel($lector->leerConNumeros((string) file_get_contents($ruta)));
                file_put_contents($destino, self::json($tarifas));
                $this->info(count($tarifas['agencias'])." agencias exportadas a {$destino}");
            }
            $sincronizador->cargar();
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
        $this->info('Tarifas cargadas en la base de datos.');

        return self::SUCCESS;
    }

    /** @param  array{filas: list<list<mixed>>, numeros?: list<int>}  $hoja */
    public static function desdeExcel(array $hoja): array
    {
        if (empty($hoja['numeros'])) {
            throw new RuntimeException('El archivo de tarifas debe ser un Excel (.xlsx).');
        }
        // Fila de Excel -> celdas (columna A = 0).
        $filas = array_combine($hoja['numeros'], $hoja['filas']);
        $celda = fn (int $fila, int $columna) => $filas[$fila][$columna] ?? null;
        $texto = fn (mixed $valor) => $valor === null || $valor === '' || $valor === false ? '' : (string) $valor;

        $agencias = [];
        ksort($filas);
        foreach ($filas as $numero => $fila) {
            if ($numero < 6) {
                continue;
            }
            [$nombre, $cupos, $valorInvitado, $valorAsociado] = array_pad(array_slice($fila, 1, 4), 4, null);
            if ($nombre === null || $nombre === '' || $nombre === 0 || $valorInvitado === null || $valorInvitado === '') {
                continue;
            }
            $agencias[] = [
                'agencia' => trim((string) $nombre),
                'cupos' => (int) ($cupos ?: 0),
                'valor_invitado' => (int) $valorInvitado,
                'valor_asociado' => (int) $valorAsociado,
            ];
        }

        $cuenta = $celda(2, 2);

        return [
            'evento' => trim($texto($celda(4, 1))),
            'inscripciones' => trim($texto($celda(3, 1))),
            // La cuenta contable llega como número: sin decimales si es entera.
            'cuenta_contable' => is_float($cuenta) && floor($cuenta) === $cuenta ? sprintf('%.0f', $cuenta) : $texto($cuenta),
            'concepto' => trim($texto($celda(2, 3))),
            'agencias' => $agencias,
        ];
    }

    /** JSON legible con sangría de 2 espacios (el formato de data/*.json). */
    public static function json(array $datos): string
    {
        $json = json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return preg_replace_callback('/^( +)/m', fn ($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json)."\n";
    }
}
