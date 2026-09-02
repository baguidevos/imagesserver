<?php

namespace App\Http\Middleware;

use App\Models\Application;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveApplication
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('X-Application-Key');

        if (! is_string($key) || $key === '') {
            return response()->json(['message' => 'Clé d\'application manquante.'], 401);
        }

        $application = Application::query()
            ->where('api_key_hash', hash('sha256', $key))
            ->where('is_active', true)
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Clé d\'application invalide.'], 401);
        }

        $request->attributes->set('application', $application);

        return $next($request);
    }
}
