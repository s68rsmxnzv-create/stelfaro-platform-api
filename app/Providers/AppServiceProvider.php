<?php

namespace App\Providers;

use App\Models\SalesOrder;
use App\Models\SalesOrderPayment;
use App\Models\WorkshopOrder;
use App\Models\WorkshopOrderPayment;
use App\Observers\SalesOrderObserver;
use App\Observers\SalesOrderPaymentObserver;
use App\Observers\WorkshopOrderObserver;
use App\Observers\WorkshopOrderPaymentObserver;
use Illuminate\Support\Facades\Vite;
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
        WorkshopOrder::observe(WorkshopOrderObserver::class);
        WorkshopOrderPayment::observe(WorkshopOrderPaymentObserver::class);
        SalesOrder::observe(SalesOrderObserver::class);
        SalesOrderPayment::observe(SalesOrderPaymentObserver::class);
        Vite::prefetch(concurrency: 3);
    }
}
