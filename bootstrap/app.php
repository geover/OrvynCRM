<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolvePublicTenant;
use App\Http\Middleware\ResolveTenant;
use App\Support\UploadLimit;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // The company database is opened right after the session starts and before anything reads the user or any data.
        $middleware->web(append: [ResolveTenant::class, HandleInertiaRequests::class]);
        $middleware->api(append: [ResolveTenant::class]);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: ResolveTenant::class);
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: ResolvePublicTenant::class);
        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'admin' => EnsureAdmin::class,
            'tenant.public' => ResolvePublicTenant::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $message = 'That file is bigger than this server accepts ('.UploadLimit::label().'). Use a smaller file, or ask your administrator to raise upload_max_filesize and post_max_size.';

            return $request->expectsJson() || $request->header('X-Inertia')
                ? response()->json(['message' => $message], 413)
                : response($message, 413);
        });
    })->create();
