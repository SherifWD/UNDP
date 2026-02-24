<?php

namespace App\Providers;

use App\Contracts\OtpSender;
use App\Models\Submission;
use App\Policies\SubmissionPolicy;
use App\Services\Otp\ISendlyOtpSender;
use App\Services\Otp\LogOtpSender;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(OtpSender::class, function () {
            return (bool) config('services.isendly.enabled')
                ? app(ISendlyOtpSender::class)
                : app(LogOtpSender::class);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Submission::class, SubmissionPolicy::class);

        RateLimiter::for('otp', function (Request $request) {
            $phone = preg_replace('/[^0-9]/', '', (string) $request->input('phone', ''));

            return [
                Limit::perMinute(6)->by($request->ip()),
                Limit::perMinute(5)->by(($request->input('country_code', '+218')).$phone),
            ];
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(240)->by($request->user()?->id ?: $request->ip());
        });
    }
}
