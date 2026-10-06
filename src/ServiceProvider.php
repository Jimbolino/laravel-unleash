<?php

namespace MikeFrancis\LaravelUnleash;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * Register bindings in the container.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom($this->getConfigPath(), 'unleash');
        $this->app->singleton(Unleash::class, function ($app) {
            return new Unleash(
                fn () => $app->make(Client::class),
                fn () => $app->make(Cache::class),
                $app->make(Config::class),
                $app->make(Request::class),
                new FeatureFile($this->getFeatureFilePath()),
                // a web request refreshes a stale feature file after its response has been sent
                $app->runningInConsole() ? null : fn (Closure $refresh) => $app->terminating($refresh)
            );
        });
        $this->app->alias(Unleash::class, 'unleash');
    }

    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        $this->publishes(
            [
                $this->getConfigPath() => config_path('unleash.php'),
            ]
        );

        // only when a view is compiled, so a request without views does not build the Blade compiler
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            $blade->if(
                'featureEnabled',
                function (string $feature) {
                    return app(Unleash::class)->isFeatureEnabled($feature);
                }
            );

            $blade->if(
                'featureDisabled',
                function (string $feature) {
                    return !app(Unleash::class)->isFeatureEnabled($feature);
                }
            );
        });
    }

    /**
     * Get the path to the config.
     */
    private function getConfigPath(): string
    {
        return __DIR__ . '/../config/unleash.php';
    }

    /**
     * The feature file is per machine (or container), so the temp dir fits. The base path keeps the
     * files of several applications on one machine apart, the user id the files of several users: in
     * a sticky temp dir one user cannot replace a file another user created.
     */
    private function getFeatureFilePath(): string
    {
        $path = $this->app->make(Config::class)->get('unleash.cache.path');
        if ($path) {
            return $path;
        }

        $user = function_exists('posix_geteuid') ? posix_geteuid() : get_current_user();
        $name = 'laravel-unleash-' . md5($this->app->basePath()) . '-' . $user . '.json';

        return rtrim(sys_get_temp_dir(), '/') . '/' . $name;
    }
}
