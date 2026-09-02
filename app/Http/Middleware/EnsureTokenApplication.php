<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTokenApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $application = $request->attributes->get('application');

        if (! $request->user() || ! $application || $request->user()->application_id !== $application->id) {
            return response()->json(['message' => 'Le token ne correspond pas à cette application.'], 403);
        }

        return $next($request);
    }
}
