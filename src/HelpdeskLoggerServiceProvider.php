<?php

namespace D3vnz\HelpdeskLogger;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class HelpdeskLoggerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/helpdesk-logger.php', 'helpdesk-logger');

        $this->app->singleton(Sanitizer::class, function () {
            return new Sanitizer((array) config('helpdesk-logger.sanitize_keys', []));
        });

        $this->app->singleton(StackFrameNormalizer::class, function ($app) {
            $configured = config('helpdesk-logger.stack.app_paths');
            $paths = is_array($configured) && ! empty($configured)
                ? array_values(array_filter($configured))
                : $this->defaultAppPaths();
            $max = (int) config('helpdesk-logger.stack.max_frames', 40);
            return new StackFrameNormalizer($paths, $max);
        });

        $this->app->singleton(ContextBuilder::class, function ($app) {
            return new ContextBuilder(
                config: (array) config('helpdesk-logger'),
                sanitizer: $app->make(Sanitizer::class),
                stackNormalizer: $app->make(StackFrameNormalizer::class),
                auth: $app->bound(AuthFactory::class) ? $app->make(AuthFactory::class) : null,
            );
        });

        $this->app->singleton(SpikeGate::class, function ($app) {
            /** @var CacheFactory $cacheFactory */
            $cacheFactory = $app->make(CacheFactory::class);
            return new SpikeGate(
                cache: $cacheFactory->store(),
                windowSeconds: (int) config('helpdesk-logger.burst_window_seconds', 60),
            );
        });

        $this->app->singleton(Reporter::class, function ($app) {
            return new Reporter(
                http: $app->make(HttpFactory::class),
                config: (array) config('helpdesk-logger'),
            );
        });

        $this->app->singleton(HelpdeskLogger::class, function ($app) {
            return new HelpdeskLogger(
                container: $app,
                contextBuilder: $app->make(ContextBuilder::class),
                spikeGate: $app->make(SpikeGate::class),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/helpdesk-logger.php' => config_path('helpdesk-logger.php'),
            ], 'helpdesk-logger-config');
        }
    }

    /** @return list<string> */
    protected function defaultAppPaths(): array
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR . '/');
        return array_values(array_filter([
            $base . DIRECTORY_SEPARATOR . 'app',
            $base . DIRECTORY_SEPARATOR . 'bootstrap',
            $base . DIRECTORY_SEPARATOR . 'config',
            $base . DIRECTORY_SEPARATOR . 'routes',
            $base . DIRECTORY_SEPARATOR . 'database',
            $base . DIRECTORY_SEPARATOR . 'tests',
        ]));
    }
}
