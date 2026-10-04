<?php

namespace Tests\Feature\Comandos;

use App\Console\Commands\Respaldar;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Carpeta temporal propia de cada prueba (se borra al terminar) y utilidades de los comandos de respaldo. */
trait ConCarpetaTemporal
{
    private ?string $carpetaTemporal = null;

    protected function carpetaTemporal(string $subcarpeta = ''): string
    {
        $this->carpetaTemporal ??= sys_get_temp_dir().'/evento-comandos-'.bin2hex(random_bytes(6));
        $ruta = rtrim($this->carpetaTemporal.'/'.$subcarpeta, '/');
        File::ensureDirectoryExists($ruta);

        return str_replace('\\', '/', $ruta);
    }

    protected function tearDown(): void
    {
        if ($this->carpetaTemporal !== null) {
            File::deleteDirectory($this->carpetaTemporal);
        }
        parent::tearDown();
    }

    /** Los respaldos de PostgreSQL necesitan pg_dump; sin el cliente instalado la prueba se omite. */
    protected function requierePgDump(): void
    {
        try {
            Respaldar::ubicarPgDump();
        } catch (RuntimeException) {
            $this->markTestSkipped('pg_dump no está instalado (cliente de PostgreSQL).');
        }
    }
}
