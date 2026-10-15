<?php

declare(strict_types=1);

namespace Padosoft\AskMyDocsConnectorGmail;

use Illuminate\Support\ServiceProvider;

final class GmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/connector-gmail.php', 'connector-gmail');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/connector-gmail.php' => config_path('connector-gmail.php')], 'connector-gmail-config');
            $this->publishes([__DIR__.'/../resources/icons/gmail.svg' => public_path('connectors/gmail.svg')], 'connector-gmail-assets');
        }
    }
}
