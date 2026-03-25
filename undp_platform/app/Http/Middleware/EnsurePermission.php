<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasPermission($permission)) {
            AuditLogger::blocked($request, $permission, $user);

            return response()->json([
                'message' => __('Access denied. You are not allowed to perform this action.'),
                'required_permission' => $permission,
            ], 403);
        }

        return $next($request);
    }
}
