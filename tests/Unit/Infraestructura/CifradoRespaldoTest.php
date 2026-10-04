<?php

namespace Tests\Unit\Infraestructura;

use App\Infraestructura\Respaldo\CifradoRespaldo;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Cifrado de respaldos con AES-256-GCM (formato "EVTENC1\n" | iv | etiqueta | datos). */
class CifradoRespaldoTest extends TestCase
{
    private string $directorio;

    protected function setUp(): void
    {
        $this->directorio = sys_get_temp_dir().'/respaldo-prueba-'.bin2hex(random_bytes(6));
        mkdir($this->directorio);
        file_put_contents("{$this->directorio}/respaldo.sql", "INSERT INTO asociados VALUES ('1001', 'Ana María Torres');\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->directorio}/*"));
        rmdir($this->directorio);
    }

    private function cifrar(string $clave): string
    {
        CifradoRespaldo::cifrar("{$this->directorio}/respaldo.sql", "{$this->directorio}/respaldo.enc", $clave);

        return "{$this->directorio}/respaldo.enc";
    }

    public function test_cifra_y_descifra_sin_dejar_datos_legibles(): void
    {
        $clave = random_bytes(32);
        $cifrado = $this->cifrar($clave);

        $contenido = file_get_contents($cifrado);
        $this->assertStringStartsWith("EVTENC1\n", $contenido);
        $this->assertStringNotContainsString('1001', $contenido);

        CifradoRespaldo::descifrar($cifrado, "{$this->directorio}/restaurado.sql", $clave);
        $this->assertFileEquals("{$this->directorio}/respaldo.sql", "{$this->directorio}/restaurado.sql");
    }

    public function test_clave_equivocada(): void
    {
        $cifrado = $this->cifrar(random_bytes(32));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no corresponde/');

        CifradoRespaldo::descifrar($cifrado, "{$this->directorio}/restaurado.sql", random_bytes(32));
    }

    public function test_archivo_alterado(): void
    {
        $clave = random_bytes(32);
        $cifrado = $this->cifrar($clave);
        $contenido = file_get_contents($cifrado);
        $contenido[strlen($contenido) - 1] = chr(ord($contenido[strlen($contenido) - 1]) ^ 1);
        file_put_contents($cifrado, $contenido);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/alterado/');

        CifradoRespaldo::descifrar($cifrado, "{$this->directorio}/restaurado.sql", $clave);
    }

    public function test_clave_hexadecimal(): void
    {
        $this->assertNull(CifradoRespaldo::clave(''));
        $this->assertSame(32, strlen(CifradoRespaldo::clave(str_repeat('ab', 32))));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/64 caracteres/');
        CifradoRespaldo::clave('corta');
    }
}
