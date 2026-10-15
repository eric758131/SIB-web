<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only users with a panel role (spatie/laravel-permission) may use the admin API.
 * Fine-grained checks use Spatie's "permission:" middleware on each route.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canUsePanel()) {
            return response()->json(['message' => 'Tu cuenta no tiene acceso al panel.'], 403);
        }

        return $next($request);
    }
}
