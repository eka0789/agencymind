<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

use App\Models\Client;
use App\Models\User;
use App\Observers\ClientObserver;
use Illuminate\Support\Facades\Gate;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\LLMGatewayManager::class, function () {
            return new \App\Services\LLMGatewayManager();
        });

        $this->app->bind(\App\Services\LLMGatewayService::class, function ($app) {
            return $app->make(\App\Services\LLMGatewayManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Client::observe(ClientObserver::class);

        // Otorisasi: Hanya lead_dev ke atas (owner, admin, lead_dev) yang bisa override validasi gagal
        Gate::define('override-validation', function (User $user) {
            return in_array($user->role, ['owner', 'lead_dev', 'admin']);
        });

        // Otorisasi: Hanya lead_dev ke atas yang bisa mengubah threshold
        Gate::define('manage-thresholds', function (User $user) {
            return in_array($user->role, ['owner', 'lead_dev', 'admin']);
        });
    }
}
