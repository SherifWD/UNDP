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
        $locale = $this->resolvePreferredLocale($request)
            ?? $this->resolveAcceptLanguage($request)
            ?? $this->normalizeLocale($request->user()?->preferred_locale)
            ?? config('app.locale', 'ar');

        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);
        $response->headers->set('X-Resolved-Locale', $locale);

        return $response;
    }

    private function resolvePreferredLocale(Request $request): ?string
    {
        foreach ([
            $request->header('preferred_locale'),
            $request->header('preferred-locale'),
            $request->header('x-preferred-locale'),
            $request->header('x_preferred_locale'),
            $this->findPreferredLocaleHeaderValue($request),
            $request->input('preferred_locale'),
        ] as $candidate) {
            $locale = $this->normalizeLocale($candidate);

            if ($locale) {
                return $locale;
            }
        }

        return null;
    }

    private function resolveAcceptLanguage(Request $request): ?string
    {
        $accepted = (string) $request->header('Accept-Language', '');

        return collect(explode(',', $accepted))
            ->map(fn (string $lang): ?string => $this->normalizeLocale(explode(';', $lang)[0] ?? null))
            ->first(fn (?string $lang): bool => $lang !== null);
    }

    private function findPreferredLocaleHeaderValue(Request $request): ?string
    {
        foreach ($request->headers->all() as $key => $values) {
            if ($this->normalizeHeaderKey($key) !== 'preferredlocale') {
                continue;
            }

            $value = is_array($values) ? ($values[0] ?? null) : $values;
            $locale = $this->normalizeLocale($value);

            if ($locale) {
                return $locale;
            }
        }

        foreach ($request->server->all() as $key => $value) {
            if ($this->normalizeServerKey($key) !== 'preferredlocale') {
                continue;
            }

            $locale = $this->normalizeLocale(is_array($value) ? ($value[0] ?? null) : $value);

            if ($locale) {
                return $locale;
            }
        }

        return null;
    }

    private function normalizeHeaderKey(string $key): string
    {
        return Str::of($key)
            ->lower()
            ->replaceMatches('/[^a-z]/', '')
            ->value();
    }

    private function normalizeServerKey(string $key): string
    {
        return Str::of($key)
            ->lower()
            ->replace(['redirect_http_', 'http_', 'redirect_'], '')
            ->replaceMatches('/[^a-z]/', '')
            ->value();
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
