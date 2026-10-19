<?php

namespace App\Http\Middleware;

use App\Support\IgnoredInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Says, on the response, which fields of the request were sent but not saved (see IgnoredInput). Not in production. */
class ReportIgnoredInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (app()->isProduction()) {
            return $response;
        }
        if ($ignored = IgnoredInput::take()) {
            $response->headers->set('X-Ignored-Input', implode(',', $ignored));
            Log::warning('Ignored input: fields were sent but have no validation rule, so they were not saved', ['route' => $request->method().' '.$request->path(), 'fields' => $ignored]);
        }

        return $response;
    }
}
