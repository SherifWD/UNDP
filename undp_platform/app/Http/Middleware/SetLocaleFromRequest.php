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
        $locale = $this->resolvePreferredLocale($request);

        if (! $locale) {
            $accepted = (string) $request->header('Accept-Language', '');

            $locale = collect(explode(',', $accepted))
                ->map(fn (string $lang): ?string => $this->normalizeLocale(explode(';', $lang)[0] ?? null))
                ->first(fn (?string $lang): bool => $lang !== null);
        }

        if (! $locale && $request->user()?->preferred_locale) {
            $locale = $this->normalizeLocale($request->user()->preferred_locale);
        }

        app()->setLocale($locale ?: config('app.locale', 'ar'));

        return $next($request);
    }

    private function resolvePreferredLocale(Request $request): ?string
    {
        foreach (['preferred_locale', 'preferred-locale'] as $header) {
            $locale = $this->normalizeLocale($request->header($header));

            if ($locale) {
                return $locale;
            }
        }

        return $this->normalizeLocale($request->input('preferred_locale'));
    }

    private function normalizeLocale(mixed $locale): ?string
    {
        if (! is_string($locale) || trim($locale) === '') {
            return null;
        }

        $normalized = Str::of($locale)
            ->trim()
            ->replace('_', '-')
            ->lower()
            ->value();

        $normalized = Str::substr($normalized, 0, 2);

        return in_array($normalized, ['ar', 'en'], true) ? $normalized : null;
    }
}
