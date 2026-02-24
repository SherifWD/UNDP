<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SetLocaleFromRequest
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $accepted = (string) $request->header('Accept-Language', '');

        $locale = collect(explode(',', $accepted))
            ->map(fn (string $lang): string => Str::lower(trim(explode(';', $lang)[0])))
            ->map(fn (string $lang): string => Str::substr($lang, 0, 2))
            ->first(fn (string $lang): bool => in_array($lang, ['ar', 'en'], true));

        if (! $locale && $request->user()?->preferred_locale) {
            $locale = $request->user()->preferred_locale;
        }

        app()->setLocale($locale ?: config('app.locale', 'ar'));

        return $next($request);
    }
}
