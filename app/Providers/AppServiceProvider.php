<?php

namespace App\Providers;

use App\Aplicacion\Bases\LectorHojaCalculo;
use App\Aplicacion\Configuracion\ConfiguracionEvento;
use App\Aplicacion\Seguridad\AutenticadorPanel;
use App\Aplicacion\Seguridad\IngresarAlPanel;
use App\Aplicacion\Seguridad\ProteccionIdentidad;
use App\Aplicacion\Simulacion\IdentificarAsociado;
use App\Dominio\Compartido\Reloj;
use App\Dominio\Compartido\UnidadDeTrabajo;
use App\Dominio\Inscripcion\Acompanantes\ClasificadorAcompanantes;
use App\Dominio\Inscripcion\AlmacenComprobantes;
use App\Dominio\Inscripcion\Pagos\CatalogoMediosPago;
use App\Dominio\Inscripcion\RepositorioInscripciones;
use App\Dominio\Padron\RepositorioBases;
use App\Dominio\Padron\RepositorioGestionPadron;
use App\Dominio\Padron\RepositorioPadron;
use App\Dominio\Panel\HashClaves;
use App\Dominio\Panel\RegistroAuditoria;
use App\Dominio\Panel\RepositorioUsuarios;
use App\Dominio\Panel\Rol;
use App\Dominio\Seguridad\HuellaFecha;
use App\Dominio\Seguridad\LimitadorIntentos;
use App\Dominio\Seguridad\RegistroSeguridad;
use App\Http\Middleware\RestringirRedPanel;
use App\Infraestructura\Almacenamiento\AlmacenComprobantesBaseDatos;
use App\Infraestructura\Almacenamiento\AlmacenComprobantesDisco;
use App\Infraestructura\Configuracion\LectorConfiguracion;
use App\Infraestructura\Configuracion\SincronizadorTarifas;
use App\Infraestructura\Excel\LectorHojaCalculoNativo;
use App\Infraestructura\Persistencia\RegistroAuditoriaEloquent;
use App\Infraestructura\Persistencia\RepositorioBasesEloquent;
use App\Infraestructura\Persistencia\RepositorioGestionPadronEloquent;
use App\Infraestructura\Persistencia\RepositorioInscripcionesEloquent;
use App\Infraestructura\Persistencia\RepositorioPadronEloquent;
use App\Infraestructura\Persistencia\RepositorioUsuariosEloquent;
use App\Infraestructura\Persistencia\UnidadDeTrabajoBaseDatos;
use App\Infraestructura\RelojSistema;
use App\Infraestructura\Seguridad\AutenticadorPanelLaravel;
use App\Infraestructura\Seguridad\HashClavesLaravel;
use App\Infraestructura\Seguridad\HuellaFechaHmac;
use App\Infraestructura\Seguridad\LimitadorCache;
use App\Infraestructura\Seguridad\ProveedorAdministradores;
use App\Infraestructura\Seguridad\RegistroSeguridadLog;
use App\Models\Administrador;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Composición de la aplicación: cada puerto del dominio y de la aplicación (interfaces) se resuelve con su adaptador
 * de infraestructura. Los casos de uso se construyen por inyección automática del contenedor.
 */
class AppServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $singletons = [
        Reloj::class => RelojSistema::class,
        UnidadDeTrabajo::class => UnidadDeTrabajoBaseDatos::class,
        RepositorioPadron::class => RepositorioPadronEloquent::class,
        RepositorioInscripciones::class => RepositorioInscripcionesEloquent::class,
        RepositorioGestionPadron::class => RepositorioGestionPadronEloquent::class,
        RepositorioBases::class => RepositorioBasesEloquent::class,
        RepositorioUsuarios::class => RepositorioUsuariosEloquent::class,
        RegistroAuditoria::class => RegistroAuditoriaEloquent::class,
        HashClaves::class => HashClavesLaravel::class,
        LimitadorIntentos::class => LimitadorCache::class,
        LectorHojaCalculo::class => LectorHojaCalculoNativo::class,
        AutenticadorPanel::class => AutenticadorPanelLaravel::class,
        ClasificadorAcompanantes::class => ClasificadorAcompanantes::class,
        CatalogoMediosPago::class => CatalogoMediosPago::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ConfiguracionEvento::class, fn () => LectorConfiguracion::leer(
            config('evento.datos'), config('evento.config'), config('evento.max_acompanantes'), config('evento.meses_vigencia_datos'),
        ));

        $this->app->singleton(HuellaFecha::class, fn (Application $app) => new HuellaFechaHmac(config('evento.secreto'), $app->isProduction()));

        $this->app->singleton(RegistroSeguridad::class, fn (Application $app) => new RegistroSeguridadLog(Log::channel('seguridad'), $app->make(Reloj::class)));

        $this->app->singleton(AlmacenComprobantes::class, function (Application $app) {
            $almacen = config('evento.almacen_soportes');

            return $almacen === 'base_datos'
                ? new AlmacenComprobantesBaseDatos($app->make(ConnectionInterface::class))
                : new AlmacenComprobantesDisco(Storage::disk($almacen));
        });

        $this->app->singleton(SincronizadorTarifas::class, fn (Application $app) => new SincronizadorTarifas(
            $app->make(ConnectionInterface::class), config('evento.datos'), Log::channel(),
        ));

        $this->app->bind(IdentificarAsociado::class, fn (Application $app) => new IdentificarAsociado(
            $app->make(RepositorioPadron::class), $app->make(HuellaFecha::class), $app->make(LimitadorIntentos::class),
            $app->make(Reloj::class), $app->make(ConfiguracionEvento::class), config('evento.limites.identidad_por_documento'),
        ));
        $this->app->bind(ProteccionIdentidad::class, fn (Application $app) => new ProteccionIdentidad(
            $app->make(LimitadorIntentos::class), $app->make(RegistroSeguridad::class), config('evento.limites.identidad_por_ip'),
        ));
        $this->app->bind(IngresarAlPanel::class, fn (Application $app) => new IngresarAlPanel(
            $app->make(AutenticadorPanel::class), $app->make(LimitadorIntentos::class), $app->make(RegistroSeguridad::class),
            config('evento.limites.login_por_ip'),
        ));
        $this->app->bind(AutenticadorPanelLaravel::class, fn (Application $app) => new AutenticadorPanelLaravel(
            Auth::guard('web'), $app->make('session.store'),
        ));
    }

    public function boot(): void
    {
        Auth::provider('administradores', fn (Application $app) => new ProveedorAdministradores($app->make('hash')));

        // Roles: el REVISOR consulta, aprueba o rechaza pagos y exporta; lo demás es del ADMINISTRADOR.
        Gate::define('administrar', fn (Administrador $usuario) => $usuario->rol === Rol::Administrador);

        // La restricción del panel por red también aplica a las acciones de sus componentes Livewire.
        Livewire::addPersistentMiddleware([RestringirRedPanel::class]);

        // Límite general por IP de las solicitudes de la parte pública (Livewire), como el de la API anterior.
        RateLimiter::for('publico', fn (Request $request) => Limit::perMinutes(5, config('evento.limites.solicitudes_por_ip'))
            ->by($request->ip())
            ->response(fn () => response('Demasiadas solicitudes. Espere unos minutos e intente de nuevo.', 429)));
    }
}
