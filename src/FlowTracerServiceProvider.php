<?php

namespace LaravelFlowTracer;

use Illuminate\Support\ServiceProvider;
use LaravelFlowTracer\Console\Commands\FlowTraceCommand;

class FlowTracerServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the main services
        $this->app->singleton('flow-tracer.parser', function ($app) {
            return new \LaravelFlowTracer\Services\FlowParser();
        });

        $this->app->singleton('flow-tracer.visualizer', function ($app) {
            return new \LaravelFlowTracer\Services\FlowVisualizer();
        });

        $this->app->singleton('flow-tracer.analyzer', function ($app) {
            return new \LaravelFlowTracer\Services\CodeAnalyzer();
        });

        $this->app->singleton('flow-tracer.dependency', function ($app) {
            return new \LaravelFlowTracer\Services\DependencyTracer();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                FlowTraceCommand::class,
            ]);
        }

        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/flow-tracer.php' => config_path('flow-tracer.php'),
        ], 'config');

        // Merge default config
        $this->mergeConfigFrom(
            __DIR__ . '/../config/flow-tracer.php', 'flow-tracer'
        );
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return [
            'flow-tracer.parser',
            'flow-tracer.visualizer', 
            'flow-tracer.analyzer',
            'flow-tracer.dependency',
            FlowTraceCommand::class,
        ];
    }
}