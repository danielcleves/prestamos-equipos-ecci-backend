<?php

namespace App\Providers;

use App\Models\Equipo;
use App\Observers\EquipoObserver;
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
        Equipo::observe(EquipoObserver::class);
    }
}
