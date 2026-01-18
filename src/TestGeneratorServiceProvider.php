<?php

declare(strict_types=1);

namespace Bberkaysari\LaravelTestGenerator;

use Illuminate\Support\ServiceProvider;
use Bberkaysari\LaravelTestGenerator\Commands\TestGenerateCommand;

class TestGeneratorServiceProvider extends ServiceProvider
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
        if ($this->app->runningInConsole()) {
            $this->commands([
                TestGenerateCommand::class,
            ]);
        }
    }
}
