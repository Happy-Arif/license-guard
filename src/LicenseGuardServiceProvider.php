<?php

namespace HappyArif\LicenseGuard;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use HappyArif\LicenseGuard\Middleware\LaravelMiddleware;

class LicenseGuardServiceProvider extends ServiceProvider
{
    public function boot(Kernel $kernel): void
    {
        // Installed packages can be migrated via the application's normal
        // php artisan migrate --force command, without copying the migration.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $kernel->pushMiddleware(LaravelMiddleware::class);
    }
}
