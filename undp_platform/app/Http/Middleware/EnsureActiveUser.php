<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->status !== UserStatus::ACTIVE->value) {
            if ($request->user()?->currentAccessToken()) {
                $request->user()->currentAccessToken()->delete();
            }

            AuditLogger::log(
                action: 'auth.token_revoked_user_disabled',
                entityType: 'users',
                entityId: $user->id,
                request: $request,
                actor: $user,
            );

            return response()->json([
                'message' => 'Your account is disabled. Please contact an administrator.',
            ], 403);
        }

        return $next($request);
    }
}
