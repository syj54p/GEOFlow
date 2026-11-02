<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\SkillIntegration\SkillManager;
use Illuminate\Support\ServiceProvider;

class SkillIntegrationServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(SkillManager::class, function ($app) {
            return new SkillManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
