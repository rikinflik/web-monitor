<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // withRouting(commands:) only registers the routes/console.php file, so the
    // class-based commands in app/Console/Commands need their own opt-in.
    ->withCommands()
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectTo(
            guests: '/login',
            users: '/monitors',
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
