<?php

namespace HappyArif\LicenseGuard;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use HappyArif\LicenseGuard\Middleware\LaravelMiddleware;

class LicenseGuardServiceProvider extends ServiceProvider
{
    public function boot(Kernel $kernel)
    {
        $kernel->pushMiddleware(LaravelMiddleware::class);
    }
}