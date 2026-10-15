<?php

namespace App\Providers;

use App\Support\PanelToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('wp-token', fn (Request $request) => PanelToken::resolve($request->bearerToken()));

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($request->ip().'|'.$request->input('login')));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));
    }
}
