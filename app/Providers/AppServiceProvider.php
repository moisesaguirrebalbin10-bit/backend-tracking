<?php

namespace App\Providers;

use App\Events\OrderStatusChanged;
use App\Jobs\CancelExpiredOrderErrorsJob;
use App\Listeners\LogOrderStatusChange;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use App\Services\WooCommerceManager;

class AppServiceProvider extends ServiceProvider
{

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(WooCommerceManager::class, function () {
            return new WooCommerceManager();
        });
        #Si no funciona cambiar por esta
        
       # $this->app->singleton(\App\Services\WooCommerceManager::class, function () {
        #    return new \App\Services\WooCommerceManager();
        #});
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register event listeners
        Event::listen(
            OrderStatusChanged::class,
            LogOrderStatusChange::class,
        );
    }
}

