<?php

namespace App\Integracao\Application\Providers;

use Illuminate\Support\ServiceProvider;
use App\Integracao\Application\Services\IntegrationProcessingService;
use App\Integracao\Application\Services\IntegrationCacheService;
use App\Integracao\Application\Services\XMLIntegrationLoggerService;
use App\Integracao\Infrastructure\Repositories\IntegrationRepository;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        
        $this->app->singleton(IntegrationProcessingService::class, function ($app) {
            return new IntegrationProcessingService(
                $app->make(IntegrationRepository::class),
                $app->make(XMLIntegrationLoggerService::class)
            );
        });

        $this->app->singleton(IntegrationCacheService::class, function ($app) {
            return new IntegrationCacheService();
        });

        $this->app->singleton(IntegrationRepository::class, function ($app) {
            return new IntegrationRepository();
        });

        $this->app->singleton(XMLIntegrationLoggerService::class, function ($app) {
            return new XMLIntegrationLoggerService();
        });
    }

    public function boot(): void
    {
        
        $this->publishConfig();
    }

    private function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/integration.php' => config_path('integration.php'),
            ], 'integration-config');
        }
    }
}
