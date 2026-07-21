<?php

use App\Services\Cash\CashAutomationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('tax-notifications:generate')
    ->dailyAt('08:00')
    ->timezone('America/El_Salvador')
    ->withoutOverlapping();

Schedule::command('fiscal-sync:reconcile')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::call(fn () => app(CashAutomationService::class)->process())
    ->name('cash-sessions:automate')
    ->everyMinute()
    ->withoutOverlapping();
