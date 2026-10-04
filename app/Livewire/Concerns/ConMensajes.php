<?php

namespace App\Livewire\Concerns;

use App\Aplicacion\Panel\PermisoDenegado;
use App\Aplicacion\Seguridad\DemasiadosIntentos;
use App\Aplicacion\Seguridad\ProteccionIdentidad;
use App\Dominio\Compartido\ErrorValidacion;

/**
 * Mensajes de estado por área del formulario (p. ej. 'asociado', 'simulacion') y ejecución de acciones que traducen
 * los errores de negocio a mensajes para el usuario. Vista: @include('livewire.partials.mensaje', ['area' => '...']).
 */
trait ConMensajes
{
    /** @var array<string, array{texto: string, tipo: ?string}> */
    public array $mensajes = [];

    /** @var list<string> campos marcados como inválidos (aria-invalid) */
    public array $invalidos = [];

    /** `tipo`: null (informativo), 'ok' o 'error'. */
    protected function mensaje(string $area, string $texto = '', ?string $tipo = null): void
    {
        if ($texto === '') {
            unset($this->mensajes[$area]);

            return;
        }
        $this->mensajes[$area] = ['texto' => $texto, 'tipo' => $tipo];
    }

    /**
     * Ejecuta la acción y muestra el error de negocio en el área. Devuelve el resultado o null si falló.
     * El ErrorValidacion queda disponible en $error para leer sus datos (p. ej. la referencia existente).
     */
    protected function intentar(string $area, callable $accion, ?ErrorValidacion &$error = null): mixed
    {
        $this->mensaje($area);
        try {
            return $accion();
        } catch (ErrorValidacion $e) {
            $error = $e;
            $this->mensaje($area, $e->getMessage(), 'error');
        } catch (DemasiadosIntentos|PermisoDenegado $e) {
            $this->mensaje($area, $e->getMessage(), 'error');
        }

        return null;
    }

    /** Acción que valida documento + fecha de expedición: cuenta los fallos de identidad por IP. */
    protected function conIdentidad(string $ruta, mixed $documento, callable $accion): mixed
    {
        return app(ProteccionIdentidad::class)->ejecutar((string) request()->ip(), $documento, $ruta, $accion);
    }

    protected function marcarInvalidos(array $campos): void
    {
        $this->invalidos = array_values($campos);
    }
}
