<?php

namespace App\Livewire\Panel;

use App\Aplicacion\Gestion\GestionAsociados;
use App\Aplicacion\Gestion\GestionCoopetrolitos;
use App\Aplicacion\Gestion\GestionUsuarios;
use App\Dominio\Padron\RepositorioGestionPadron;
use App\Livewire\Concerns\ConConfirmacion;
use App\Livewire\Concerns\ConMensajes;
use App\Livewire\Concerns\ConPermisos;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Gestión manual (solo ADMINISTRADOR) de asociados, Coopetrolitos y usuarios del panel: listado con búsqueda y
 * paginación, alta, edición y baja. Las reglas las aplican los casos de uso de Aplicacion\Gestion; cada cambio queda
 * en el registro de cambios.
 */
class Gestion extends Component
{
    use ConConfirmacion, ConMensajes, ConPermisos;

    #[Locked]
    public string $tipo = 'asociados';

    public string $busqueda = '';

    #[Locked]
    public string $busquedaAplicada = '';

    public int $pagina = 1;

    /** Formulario abierto: null cerrado; ['clave' => null] nuevo; ['clave' => 'X'] edición de X. */
    #[Locked]
    public ?array $formulario = null;

    /** @var array<string, string> valores del formulario */
    public array $valores = [];

    public function mount(string $tipo): void
    {
        $this->exigirAdministrador("gestionar {$tipo}");
        $this->tipo = in_array($tipo, ['asociados', 'coopetrolitos', 'usuarios'], true) ? $tipo : 'asociados';
    }

    /** Columnas del listado y campos del formulario de cada tipo de registro. */
    protected function definicion(): array
    {
        return match ($this->tipo) {
            'asociados' => [
                'clave' => 'documento', 'singular' => 'asociado', 'busqueda' => 'Buscar por documento, nombre o agencia',
                'titulo' => 'Asociados',
                'nota' => 'Alta, edición y baja manual. Para cargar muchos registros use "Cupos y cargas masivas". La fecha de expedición se guarda protegida y nunca se muestra.',
                'columnas' => [['documento', 'Documento'], ['nombre', 'Nombre'], ['agencia', 'Agencia'], ['estado', 'Estado'],
                    ['fecha_actualizacion', 'Datos actualizados'], ['fecha_nacimiento', 'Nacimiento'], ['expedicion_registrada', 'Fecha expedición']],
                'campos' => [
                    ['id' => 'documento', 'etiqueta' => 'Número de documento', 'tipo' => 'text', 'requerido' => true, 'soloCrear' => true, 'validar' => 'numerico', 'max' => 15],
                    ['id' => 'nombre', 'etiqueta' => 'Nombres y apellidos', 'tipo' => 'text', 'requerido' => true, 'validar' => 'texto', 'max' => 100],
                    ['id' => 'agencia', 'etiqueta' => 'Agencia', 'tipo' => 'select', 'requerido' => true,
                        'opciones' => array_map(fn ($a) => [$a, $a], $this->agencias())],
                    ['id' => 'estado', 'etiqueta' => 'Estado', 'tipo' => 'select', 'requerido' => true, 'opciones' => [['ACTIVO', 'Activo'], ['INACTIVO', 'Inactivo']]],
                    ['id' => 'fechaActualizacion', 'desde' => 'fecha_actualizacion', 'etiqueta' => 'Última actualización de datos', 'tipo' => 'date'],
                    ['id' => 'fechaNacimiento', 'desde' => 'fecha_nacimiento', 'etiqueta' => 'Fecha de nacimiento', 'tipo' => 'date'],
                    ['id' => 'fechaExpedicion', 'etiqueta' => 'Fecha de expedición del documento', 'tipo' => 'date', 'requeridoAlCrear' => true,
                        'ayudaEditar' => 'Por seguridad no se muestra. Déjela vacía para conservar la registrada o escriba una nueva para reemplazarla.'],
                ],
            ],
            'coopetrolitos' => [
                'clave' => 'documento', 'singular' => 'Coopetrolito', 'busqueda' => 'Buscar por documento, nombre o cédula del asociado',
                'titulo' => 'Coopetrolitos',
                'nota' => 'Hijos de asociados. Pagan tarifa de asociado y ocupan cupo. La cédula del asociado debe existir en la base.',
                'columnas' => [['documento', 'Documento'], ['nombre', 'Nombre'], ['fecha_nacimiento', 'Nacimiento'],
                    ['documento_asociado', 'Cédula asociado'], ['nombre_asociado', 'Asociado']],
                'campos' => [
                    ['id' => 'documento', 'etiqueta' => 'Número de documento (TI o registro civil)', 'tipo' => 'text', 'requerido' => true, 'soloCrear' => true, 'validar' => 'numerico', 'max' => 15],
                    ['id' => 'nombre', 'etiqueta' => 'Nombres y apellidos', 'tipo' => 'text', 'requerido' => true, 'validar' => 'texto', 'max' => 100],
                    ['id' => 'documentoAsociado', 'desde' => 'documento_asociado', 'etiqueta' => 'Cédula del asociado (padre, madre o acudiente)', 'tipo' => 'text', 'requerido' => true, 'validar' => 'numerico', 'max' => 15],
                    ['id' => 'fechaNacimiento', 'desde' => 'fecha_nacimiento', 'etiqueta' => 'Fecha de nacimiento', 'tipo' => 'date'],
                ],
            ],
            default => [
                'clave' => 'usuario', 'singular' => 'usuario', 'busqueda' => 'Buscar usuario', 'titulo' => 'Usuarios del panel',
                'nota' => 'Personas que pueden entrar al panel. Cree un usuario por persona para que quede registro de quién aprueba cada pago.',
                'columnas' => [['usuario', 'Usuario'], ['nombre', 'Nombre'], ['rol', 'Rol'], ['actualizado_en', 'Último cambio']],
                'campos' => [
                    ['id' => 'usuario', 'etiqueta' => 'Usuario (3 a 40 letras, números, . _ -)', 'tipo' => 'text', 'requerido' => true, 'soloCrear' => true, 'autocomplete' => 'off', 'validar' => 'usuario', 'max' => 40],
                    ['id' => 'nombre', 'etiqueta' => 'Nombre completo', 'tipo' => 'text', 'requerido' => true, 'validar' => 'texto', 'max' => 100],
                    ['id' => 'rol', 'etiqueta' => 'Rol', 'tipo' => 'select', 'requerido' => true,
                        'opciones' => [['REVISOR', 'Revisor: consulta, aprueba o rechaza pagos y exporta'], ['ADMINISTRADOR', 'Administrador: todo el panel']]],
                    ['id' => 'clave', 'etiqueta' => 'Contraseña (mínimo 10 caracteres)', 'tipo' => 'password', 'requeridoAlCrear' => true, 'autocomplete' => 'new-password',
                        'ayudaEditar' => 'Déjela vacía para no cambiarla. Si la cambia, se cierran las sesiones abiertas de ese usuario.'],
                    ['id' => 'clave2', 'etiqueta' => 'Repita la contraseña', 'tipo' => 'password', 'requeridoAlCrear' => true, 'autocomplete' => 'new-password', 'noEnviar' => true],
                ],
            ],
        };
    }

    private function agencias(): array
    {
        $agencias = app(RepositorioGestionPadron::class)->agenciasValidas();
        sort($agencias);

        return $agencias;
    }

    private function servicio(): GestionAsociados|GestionCoopetrolitos|GestionUsuarios
    {
        return app(match ($this->tipo) {
            'asociados' => GestionAsociados::class,
            'coopetrolitos' => GestionCoopetrolitos::class,
            default => GestionUsuarios::class,
        });
    }

    public function buscar(): void
    {
        $this->busquedaAplicada = trim($this->busqueda);
        $this->pagina = 1;
    }

    public function irAPagina(int $pagina): void
    {
        $this->pagina = max(1, $pagina);
    }

    // ---- Formulario ----

    public function nuevo(): void
    {
        $this->abrirFormulario(null, []);
    }

    public function editar(string $clave): void
    {
        $registro = collect($this->listado()['filas'])->firstWhere($this->definicion()['clave'], $clave);
        if ($registro) {
            $this->abrirFormulario($clave, $registro);
        }
    }

    private function abrirFormulario(?string $clave, array $registro): void
    {
        $this->formulario = ['clave' => $clave];
        $this->valores = [];
        foreach ($this->definicion()['campos'] as $c) {
            $this->valores[$c['id']] = $clave === null
                ? (['estado' => 'ACTIVO', 'rol' => 'REVISOR'][$c['id']] ?? '')
                : ($c['tipo'] === 'password' ? '' : (string) ($registro[$c['desde'] ?? $c['id']] ?? ''));
        }
        $this->invalidos = [];
        $this->mensaje('formulario');
    }

    public function cerrarFormulario(): void
    {
        $this->formulario = null;
        $this->valores = [];
    }

    public function guardar(): void
    {
        if (! $this->formulario) {
            return;
        }
        $editando = $this->formulario['clave'];
        $campos = $this->definicion()['campos'];
        $this->invalidos = [];
        foreach ($campos as $c) {
            $requerido = ! empty($c['requerido']) || (! empty($c['requeridoAlCrear']) && $editando === null);
            if ($requerido && trim($this->valores[$c['id']] ?? '') === '') {
                $this->invalidos[] = "valores.{$c['id']}";
            }
        }
        if ($this->invalidos) {
            $this->mensaje('formulario', 'Complete los campos obligatorios (*).', 'error');

            return;
        }
        if (array_key_exists('clave2', $this->valores) && $this->valores['clave'] !== $this->valores['clave2']) {
            $this->invalidos = ['valores.clave2'];
            $this->mensaje('formulario', 'Las contraseñas no coinciden.', 'error');

            return;
        }
        $datos = [];
        foreach ($campos as $c) {
            if (empty($c['noEnviar'])) {
                $datos[$c['id']] = trim($this->valores[$c['id']] ?? '');
            }
        }
        $resultado = $this->intentar('formulario', function () use ($editando, $datos) {
            $this->exigirAdministrador("gestionar {$this->tipo}");
            $autor = $this->usuario()->usuario;

            return $editando === null ? $this->servicio()->crear($datos, $autor) : $this->servicio()->editar($editando, $datos, $autor);
        });
        if (! $resultado) {
            return;
        }
        $singular = $this->definicion()['singular'];
        $this->cerrarFormulario();
        $this->dispatch('notificar', titulo: ucfirst($singular).($editando === null ? ' creado' : ' actualizado'),
            mensaje: 'Registro '.($editando ?? reset($resultado)).' guardado correctamente.', tono: 'exito');
        $this->dispatch('auditoria-actualizada');
    }

    // ---- Eliminar ----

    public function eliminar(string $clave): void
    {
        $definicion = $this->definicion();
        $registro = collect($this->listado()['filas'])->firstWhere($definicion['clave'], $clave);
        if (! $registro) {
            return;
        }
        $this->pedirConfirmacion('eliminarRegistro', [
            'titulo' => "¿Eliminar {$definicion['singular']}?",
            'mensaje' => $this->tipo === 'asociados'
                ? 'Si el asociado tiene una inscripción activa o Coopetrolitos vinculados no se podrá eliminar; en ese caso márquelo como inactivo.'
                : 'Esta acción no se puede deshacer.',
            'detalles' => array_map(fn ($col) => [$col[1], (string) ($registro[$col[0]] ?? '—')], array_slice($definicion['columnas'], 0, 2)),
            'aceptar' => 'Sí, eliminar',
            'cancelar' => 'Volver',
            'tono' => 'peligro',
        ], [$clave]);
    }

    protected function eliminarRegistro(string $clave): void
    {
        $singular = $this->definicion()['singular'];
        $resultado = $this->intentar('crud', function () use ($clave) {
            $this->exigirAdministrador("gestionar {$this->tipo}");

            return $this->servicio()->eliminar($clave, $this->usuario()->usuario);
        });
        if (! $resultado) {
            $this->dispatch('notificar', titulo: "No se pudo eliminar {$singular}", mensaje: $this->mensajes['crud']['texto'] ?? '', tono: 'error', duracion: 8000);
            $this->mensaje('crud');

            return;
        }
        $this->dispatch('notificar', titulo: ucfirst($singular).' eliminado', mensaje: "Registro {$clave} eliminado.", tono: 'exito');
        $this->dispatch('auditoria-actualizada');
    }

    // ---- Listado ----

    /** @return array{filas: list<array>, total: int, pagina: int, paginas: int} */
    private function listado(): array
    {
        if ($this->tipo === 'usuarios') {
            $q = mb_strtolower($this->busquedaAplicada);
            $filas = array_values(array_filter(app(GestionUsuarios::class)->listar(),
                fn ($u) => $q === '' || str_contains(mb_strtolower("{$u['usuario']} {$u['nombre']}"), $q)));

            return ['filas' => $filas, 'total' => count($filas), 'pagina' => 1, 'paginas' => 1];
        }

        return $this->servicio()->listar($this->busquedaAplicada ?: null, $this->pagina);
    }

    public function render(): View
    {
        $listado = $this->listado();
        $this->pagina = $listado['pagina'];

        return view('livewire.panel.gestion', [
            'd' => $this->definicion(),
            'listado' => $listado,
            'propio' => $this->usuario()->usuario,
        ]);
    }
}
