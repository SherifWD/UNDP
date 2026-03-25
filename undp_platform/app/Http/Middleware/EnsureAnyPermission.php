<?php

namespace App\Http\Middleware;

use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnyPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        $hasAnyPermission = $user && collect($permissions)
            ->filter(fn (string $permission): bool => $permission !== '')
            ->contains(fn (string $permission): bool => $user->hasPermission($permission));

        if (! $hasAnyPermission) {
            AuditLogger::blocked(
                $request,
                'any_of:'.implode(',', $permissions),
                $user,
            );

            return response()->json([
                'message' => __('Access denied. You are not allowed to perform this action.'),
                'required_any_permission' => $permissions,
            ], 403);
        }

        return $next($request);
    }
}
