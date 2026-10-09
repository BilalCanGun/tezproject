<?php

namespace App\Providers;

use App\Models\DataFile;
use App\Models\ExperimentSession;
use App\Models\ProtocolVersion;
use App\Models\ResourceArticle;
use App\Models\SessionDevice;
use App\Models\SessionStep;
use App\Models\TaskAttempt;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        Route::model('protocol', ProtocolVersion::class);
        Route::model('session', ExperimentSession::class);
        Route::model('step', SessionStep::class);
        Route::model('attempt', TaskAttempt::class);
        Route::model('device', SessionDevice::class);
        Route::model('file', DataFile::class);
        Route::model('resource', ResourceArticle::class);

        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip()));
        });

        RateLimiter::for('kiosk-events', function (Request $request) {
            return Limit::perMinute(240)->by('kiosk-'.($request->attributes->get('kioskSession')?->id ?? $request->ip()));
        });

        RateLimiter::for('kiosk-assistant', function (Request $request) {
            return Limit::perMinute(10)->by('kiosk-'.($request->attributes->get('kioskSession')?->id ?? $request->ip()));
        });
    }
}
