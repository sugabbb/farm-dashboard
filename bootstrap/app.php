<?php

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
  ->withCommands()
  ->withMiddleware(function (Middleware $middleware) {
    $middleware->trustProxies(at: '*');
    $middleware->alias(['active' => \App\Http\Middleware\ActiveUser::class,'abk.api'=>\App\Http\Middleware\AuthenticateAbkApi::class]);
    $middleware->web(append: [\App\Http\Middleware\HandleInertiaRequests::class]);
  })
  ->withExceptions(function (Exceptions $exceptions) {
    $exceptions->respond(function ($response, $exception, $request) {
      $status = $response->getStatusCode();
      if (!$request->expectsJson() && in_array($status, [403, 404, 419, 500, 503], true)) {
        return \Inertia\Inertia::render('Error', ['status' => $status])
          ->toResponse($request)
          ->setStatusCode($status);
      }
      return $response;
    });
  })->create();
