<?php

namespace App\Infraestructura\Configuracion;

use App\Models\AgenciaEvento;
use App\Models\Evento;
use App\Models\Tarifa;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Carga data/tarifas.json (evento, tarifas y cupos; sin datos personales) y data/agencias_evento.json (eventos
 * compartidos por varias agencias) en la base. Se ejecuta en cada solicitud si los archivos cambiaron (compara una
 * firma de su contenido), así las tarifas se actualizan sin reiniciar. Si un archivo nuevo es inválido se conservan
 * las tarifas anteriores.
 */
final class SincronizadorTarifas
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly string $directorio,
        private readonly LoggerInterface $logger,
    ) {}

    public function firma(): string
    {
        $partes = [];
        foreach (['tarifas.json', 'agencias_evento.json'] as $archivo) {
            $ruta = "{$this->directorio}/{$archivo}";
            $partes[] = is_file($ruta) ? hash_file('sha256', $ruta) : '-';
        }

        return hash('sha256', implode('|', $partes));
    }

    /** Recarga si los archivos cambiaron. Devuelve true si cargó. */
    public function sincronizar(): bool
    {
        $firma = $this->firma();
        if (Evento::query()->whereKey(1)->value('firma_datos') === $firma) {
            return false;
        }
        try {
            $this->cargar($firma);
        } catch (Throwable $error) {
            if (! Tarifa::query()->exists()) {
                throw $error;
            }
            $this->logger->error("No se pudieron recargar las tarifas (se conservan las anteriores): {$error->getMessage()}");

            return false;
        }
        $this->logger->info('Tarifas recargadas.');

        return true;
    }

    public function cargar(?string $firma = null): void
    {
        $datos = LectorConfiguracion::json("{$this->directorio}/tarifas.json");
        $rutaCompartidos = "{$this->directorio}/agencias_evento.json";
        $compartidos = is_file($rutaCompartidos) ? LectorConfiguracion::json($rutaCompartidos) : [];
        $eventos = array_map(fn ($t) => (string) $t['agencia'], $datos['agencias'] ?? []);

        // Las agencias que no están en un evento compartido asisten al evento con su mismo nombre.
        $filas = array_combine($eventos, $eventos);
        foreach ($compartidos as $evento => $agencias) {
            if (str_starts_with((string) $evento, '_')) {
                continue; // comentarios
            }
            if (! in_array($evento, $eventos, true)) {
                throw new RuntimeException("agencias_evento.json: el evento \"{$evento}\" no existe en tarifas.json");
            }
            unset($filas[$evento]); // el nombre del evento compartido no es una agencia donde se paga
            foreach ((array) $agencias as $agencia) {
                $filas[mb_strtoupper(trim((string) $agencia))] = $evento;
            }
        }

        $this->db->transaction(function () use ($datos, $filas, $firma) {
            Evento::query()->updateOrCreate(['id' => 1], [
                'nombre' => $datos['evento'] ?? 'Evento',
                'inscripciones' => $datos['inscripciones'] ?? null,
                'cuenta_contable' => $datos['cuenta_contable'] ?? null,
                'concepto' => $datos['concepto'] ?? null,
                'firma_datos' => $firma ?? $this->firma(),
            ]);
            foreach ($datos['agencias'] ?? [] as $t) {
                Tarifa::query()->updateOrCreate(['agencia' => $t['agencia']], [
                    'cupos' => (int) $t['cupos'], 'valor_invitado' => (int) $t['valor_invitado'], 'valor_asociado' => (int) $t['valor_asociado'],
                ]);
            }
            AgenciaEvento::query()->delete();
            AgenciaEvento::query()->insert(array_map(fn ($agencia, $evento) => ['agencia' => (string) $agencia, 'evento' => $evento],
                array_keys($filas), $filas));
        });
    }
}
