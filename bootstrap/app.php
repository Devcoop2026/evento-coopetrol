<?php

use App\Aplicacion\Panel\PermisoDenegado;
use App\Aplicacion\Seguridad\DemasiadosIntentos;
use App\Dominio\Compartido\ErrorValidacion;
use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\RestringirRedPanel;
use App\Http\Middleware\SincronizarTarifas;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CabecerasSeguridad::class);
        $middleware->web(append: [SincronizarTarifas::class]);
        $middleware->alias(['red.panel' => RestringirRedPanel::class]);
        $middleware->redirectGuestsTo(fn () => route('panel.ingreso'));
        $middleware->redirectUsersTo(fn () => route('panel'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reglas de negocio incumplidas fuera de Livewire (p. ej. descargas del panel): mensaje para el usuario.
        $exceptions->render(fn (ErrorValidacion $e, Request $request) => $request->expectsJson()
            ? response()->json(['error' => $e->getMessage(), ...$e->datos], 422)
            : response($e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=utf-8'));
        $exceptions->render(fn (PermisoDenegado $e) => response($e->getMessage(), 403)->header('Content-Type', 'text/plain; charset=utf-8'));
        $exceptions->render(fn (DemasiadosIntentos $e) => response($e->getMessage(), 429)
            ->header('Retry-After', (string) $e->segundos)->header('Content-Type', 'text/plain; charset=utf-8'));
        $exceptions->dontReport([ErrorValidacion::class, PermisoDenegado::class, DemasiadosIntentos::class]);
    })->create();
