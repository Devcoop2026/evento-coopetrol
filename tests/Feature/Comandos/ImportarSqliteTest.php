<?php

namespace Tests\Feature\Comandos;

use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Models\Administrador;
use Database\Seeders\TarifasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

/** evento:importar-sqlite — migración de los datos de la versión anterior (Node.js + SQLite, esquema 9). */
class ImportarSqliteTest extends TestCase
{
    use ConCarpetaTemporal, RefreshDatabase;

    private const CLAVE_ANTERIOR = 'ClaveAnterior2026';

    /** Esquema de la versión anterior (PRAGMA user_version = 9). */
    private const ESQUEMA = <<<'SQL'
        CREATE TABLE evento (id INTEGER PRIMARY KEY CHECK (id = 1), nombre TEXT NOT NULL, inscripciones TEXT, cuenta_contable TEXT, concepto TEXT);
        CREATE TABLE tarifas (agencia TEXT PRIMARY KEY, cupos INTEGER NOT NULL, valor_invitado INTEGER NOT NULL, valor_asociado INTEGER NOT NULL);
        CREATE TABLE agencias_evento (agencia TEXT PRIMARY KEY, evento TEXT NOT NULL REFERENCES tarifas(agencia));
        CREATE TABLE cupos_ajustados (agencia TEXT PRIMARY KEY, cupos INTEGER NOT NULL, actualizado_por TEXT, actualizado_en TEXT);
        CREATE TABLE asociados (documento TEXT PRIMARY KEY, nombre TEXT NOT NULL, agencia TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'ACTIVO',
            fecha_actualizacion TEXT, expedicion_hmac TEXT, fecha_nacimiento TEXT);
        CREATE TABLE coopetrolitos (documento TEXT PRIMARY KEY, nombre TEXT NOT NULL, documento_asociado TEXT NOT NULL, fecha_nacimiento TEXT);
        CREATE TABLE cargas_bases (id INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL, archivo TEXT, registros INTEGER NOT NULL,
            usuario TEXT NOT NULL, cargada_en TEXT NOT NULL);
        CREATE TABLE inscripciones (id INTEGER PRIMARY KEY AUTOINCREMENT, referencia TEXT NOT NULL UNIQUE, documento_titular TEXT NOT NULL,
            nombre_titular TEXT NOT NULL, agencia TEXT NOT NULL, agencia_asociado TEXT, total INTEGER NOT NULL, estado TEXT NOT NULL,
            motivo TEXT, revisado_por TEXT, revisado_en TEXT, autorizacion_version TEXT, autorizacion_en TEXT, autorizacion_ip TEXT,
            creada_en TEXT NOT NULL, actualizada_en TEXT NOT NULL, autorizacion_imagen INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE inscripcion_personas (inscripcion_id INTEGER NOT NULL REFERENCES inscripciones(id), documento TEXT NOT NULL,
            nombre TEXT NOT NULL, tipo TEXT NOT NULL, valor INTEGER NOT NULL, PRIMARY KEY (inscripcion_id, documento));
        CREATE TABLE soportes (id INTEGER PRIMARY KEY AUTOINCREMENT, inscripcion_id INTEGER NOT NULL REFERENCES inscripciones(id),
            cus TEXT NOT NULL, banco TEXT NOT NULL, fecha_pago TEXT NOT NULL, valor_pagado INTEGER NOT NULL, campos TEXT NOT NULL DEFAULT '{}',
            archivo TEXT NOT NULL, tipo_archivo TEXT NOT NULL, nombre_original TEXT, alerta TEXT, autorizacion_version TEXT,
            cargado_en TEXT NOT NULL, medio_pago TEXT NOT NULL DEFAULT 'PSE', agencia_pago TEXT, recibo TEXT);
        CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT, usuario TEXT NOT NULL, accion TEXT NOT NULL, entidad TEXT NOT NULL,
            clave TEXT NOT NULL, detalle TEXT, fecha TEXT NOT NULL);
        CREATE TABLE administradores (usuario TEXT PRIMARY KEY, nombre TEXT NOT NULL, salt TEXT NOT NULL, hash TEXT NOT NULL,
            rol TEXT NOT NULL DEFAULT 'ADMINISTRADOR');
        CREATE TABLE sesiones (token TEXT PRIMARY KEY, usuario TEXT NOT NULL, expira_en TEXT NOT NULL);
        PRAGMA user_version = 9;
        SQL;

    private string $archivo;

    private string $soportes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(TarifasSeeder::class);
        $this->soportes = $this->carpetaTemporal('soportes');
        $this->archivo = $this->carpetaTemporal().'/evento.db';
        $this->crearBaseAnterior();
    }

    private function crearBaseAnterior(): void
    {
        $db = new PDO("sqlite:{$this->archivo}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec(self::ESQUEMA);

        // Clave scrypt como la guardaba la versión anterior (crypto.scryptSync(clave, salt, 64), N=16384, r=8, p=1).
        $salt = bin2hex(random_bytes(16));
        $hash = bin2hex(sodium_crypto_pwhash_scryptsalsa208sha256(64, self::CLAVE_ANTERIOR, $salt, 524288, 33554432));
        $db->prepare("INSERT INTO administradores VALUES ('Tesoreria', 'Tesorería', ?, ?, 'REVISOR')")->execute([$salt, $hash]);
        $db->exec("INSERT INTO sesiones VALUES ('token', 'tesoreria', '2026-10-02T00:00:00.000Z')");
        $db->exec("INSERT INTO asociados VALUES ('1001', 'Ana María Torres', 'BOGOTA', 'ACTIVO', '2026-02-15', '".str_repeat('a', 64)."', '1980-04-12'),
            ('9001', 'Hernán Ortiz', 'BOGOTA', 'INACTIVO', NULL, NULL, NULL)");
        $db->exec("INSERT INTO coopetrolitos VALUES ('1100001', 'Mariana Torres', '1001', '2016-02-14')");
        $db->exec("INSERT INTO cargas_bases (tipo, archivo, registros, usuario, cargada_en) VALUES ('asociados', 'base.xlsx', 2, 'dreina', '2026-10-01T13:00:00.000Z')");
        $db->exec("INSERT INTO cupos_ajustados VALUES ('BOGOTA', 320, 'dreina', '2026-10-01T14:00:00.000Z')");
        $db->exec("INSERT INTO inscripciones (id, referencia, documento_titular, nombre_titular, agencia, agencia_asociado, total, estado,
            revisado_por, revisado_en, autorizacion_version, autorizacion_en, autorizacion_ip, creada_en, actualizada_en, autorizacion_imagen) VALUES
            (7, 'EVT26-000007', '1001', 'Ana María Torres', 'BOGOTA', 'BOGOTA', 188500, 'CONFIRMADO', 'dreina', '2026-10-02T16:00:00.000Z',
             'v1', '2026-10-02T15:00:00.000Z', '190.1.2.3', '2026-10-02T15:00:00.000Z', '2026-10-02T16:00:00.000Z', 1),
            (12, 'EVT26-000012', '9001', 'Hernán Ortiz', 'BOGOTA', 'BOGOTA', 145000, 'EN_REVISION', NULL, NULL,
             'v1', '2026-10-02T17:30:00.000Z', '190.1.2.4', '2026-10-02T17:30:00.000Z', '2026-10-02T17:31:00.000Z', 0)");
        $db->exec("INSERT INTO inscripcion_personas VALUES (7, '1001', 'Ana María Torres', 'TITULAR', 43500),
            (7, '55555', 'Pedro Pérez', 'INVITADO', 145000), (12, '9001', 'Hernán Ortiz', 'TITULAR', 145000)");
        $db->exec("INSERT INTO soportes (id, inscripcion_id, cus, banco, fecha_pago, valor_pagado, campos, archivo, tipo_archivo, nombre_original,
            alerta, autorizacion_version, cargado_en, medio_pago, agencia_pago, recibo) VALUES
            (3, 7, '123456789', 'Bancolombia', '2026-10-02', 188500, '{\"correo\":\"ana@correo.com\"}', 'a1b2c3.pdf', 'application/pdf',
             'comprobante.pdf', NULL, 'v1', '2026-10-02T15:10:00.000Z', 'PSE', NULL, NULL),
            (5, 12, '', '', '2026-10-02', 140000, '{}', 'perdido.jpg', 'image/jpeg', 'foto.jpg', 'El valor pagado no coincide', 'v1',
             '2026-10-02T17:31:00.000Z', 'AGENCIA', 'BOGOTA', 'RC123')");
        $db->exec("INSERT INTO auditoria (usuario, accion, entidad, clave, detalle, fecha) VALUES ('dreina', 'CREAR', 'usuario', 'tesoreria', 'rol REVISOR', '2026-10-01T12:00:00.000Z')");
        $db = null;

        file_put_contents("{$this->soportes}/a1b2c3.pdf", "%PDF-1.4\n% comprobante\n%%EOF\n");
    }

    private function importar(bool $confirmar = true)
    {
        return $this->artisan('evento:importar-sqlite', array_filter([
            'archivo' => $this->archivo, '--soportes' => $this->soportes, '--confirmar' => $confirmar,
        ]));
    }

    public function test_la_vista_previa_no_importa_nada(): void
    {
        $this->importar(false)
            ->expectsOutputToContain('EVENTO_SECRETO')
            ->expectsOutput('Vista previa: no se importó nada. Agregue --confirmar para importar.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('inscripciones')->count());
        $this->assertSame(0, DB::table('administradores')->count());
    }

    public function test_importa_conservando_ids_referencias_y_comprobantes(): void
    {
        $this->importar()
            ->expectsOutput('Advertencia: no se encontró el comprobante del soporte 5 (perdido.jpg); queda sin archivo.')
            ->expectsOutput('Importación terminada.')
            ->assertSuccessful();

        $this->assertSame(['EVT26-000007', 'EVT26-000012'], DB::table('inscripciones')->orderBy('id')->pluck('referencia')->all());
        $this->assertSame([7, 12], DB::table('inscripciones')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all());
        $this->assertTrue((bool) DB::table('inscripciones')->where('id', 7)->value('autorizacion_imagen'));
        $this->assertSame(['1001', '55555'], DB::table('inscripcion_personas')->where('inscripcion_id', 7)->orderBy('id')->pluck('documento')->all());

        $soporte = DB::table('soportes')->where('id', 3)->first();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}\.pdf$/', $soporte->archivo); // nombre interno nuevo
        $this->assertSame("%PDF-1.4\n% comprobante\n%%EOF\n", app(AlmacenComprobantes::class)->leer($soporte->archivo));
        $this->assertSame(['correo' => 'ana@correo.com'], json_decode($soporte->campos, true));
        $faltante = DB::table('soportes')->where('id', 5)->first();
        $this->assertSame('', $faltante->archivo);
        $this->assertSame('AGENCIA', $faltante->medio_pago);
        $this->assertSame('{}', str_replace(' ', '', $faltante->campos));

        $this->assertSame(2, DB::table('asociados')->count());
        $this->assertSame(str_repeat('a', 64), DB::table('asociados')->where('documento', '1001')->value('expedicion_hmac'));
        $this->assertSame(1, DB::table('coopetrolitos')->count());
        $this->assertSame(320, (int) DB::table('cupos_ajustados')->where('agencia', 'BOGOTA')->value('cupos'));
        $this->assertSame(1, DB::table('cargas_bases')->count());
        $this->assertSame(1, DB::table('auditoria')->count());

        // Los consecutivos siguen después de los id importados.
        $nuevo = DB::table('inscripciones')->insertGetId([
            'referencia' => 'TMP', 'documento_titular' => '1', 'nombre_titular' => 'X', 'agencia' => 'BOGOTA', 'total' => 1,
            'estado' => 'PREINSCRITO', 'creada_en' => now(), 'actualizada_en' => now(),
        ]);
        $this->assertSame(13, $nuevo);
    }

    public function test_la_clave_scrypt_anterior_sigue_sirviendo_y_se_convierte_al_ingresar(): void
    {
        $this->importar()->assertSuccessful();

        $usuario = Administrador::query()->findOrFail('tesoreria');
        $this->assertSame('REVISOR', $usuario->rol->value);
        $this->assertNotNull($usuario->clave_salt);
        $this->assertFalse(Auth::guard('web')->validate(['usuario' => 'tesoreria', 'password' => 'otra-clave-123']));
        $this->assertTrue(Auth::guard('web')->attempt(['usuario' => 'tesoreria', 'password' => self::CLAVE_ANTERIOR]));
        $this->assertNull($usuario->fresh()->clave_salt); // convertida al hash de Laravel
    }

    public function test_rechaza_una_base_actual_con_datos(): void
    {
        DB::table('asociados')->insert(['documento' => '1', 'nombre' => 'Existente', 'agencia' => 'BOGOTA']);

        $this->importar()
            ->expectsOutputToContain('La base actual ya tiene datos en: asociados.')
            ->assertFailed();

        $this->assertSame(0, DB::table('inscripciones')->count());
    }

    public function test_no_sobrescribe_un_usuario_del_panel_existente(): void
    {
        Administrador::query()->create(['usuario' => 'tesoreria', 'nombre' => 'Actual', 'clave' => 'hash-actual', 'rol' => 'ADMINISTRADOR']);

        $this->importar()
            ->expectsOutput('Advertencia: el usuario del panel "tesoreria" ya existe en la base actual; se conservó el actual.')
            ->assertSuccessful();

        $this->assertSame('Actual', Administrador::query()->findOrFail('tesoreria')->nombre);
    }

    public function test_rechaza_un_archivo_que_no_es_de_la_version_anterior(): void
    {
        $otro = $this->carpetaTemporal().'/otro.db';
        (new PDO("sqlite:{$otro}"))->exec('CREATE TABLE x (y INTEGER)');

        $this->artisan('evento:importar-sqlite', ['archivo' => $otro])
            ->expectsOutputToContain('El archivo no es una base de la versión anterior')
            ->assertFailed();
    }
}
