<?php

namespace App\Http\Middleware;

use App\Traits\HelpersTrait;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class NormalizeApiJsonResponse
{
    use HelpersTrait;

    public function __construct(private readonly ExceptionHandlerContract $exceptionHandler)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->exceptionHandler->report($exception);
            $response = $this->exceptionHandler->render($request, $exception);
        }

        if (! $response instanceof JsonResponse) {
            return $response;
        }

        return $this->normalizeApiJsonResponse($response);
    }
}
