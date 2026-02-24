<?php

use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAnyPermission;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SetLocaleFromRequest;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetLocaleFromRequest::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'any_permission' => EnsureAnyPermission::class,
            'active' => EnsureActiveUser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
