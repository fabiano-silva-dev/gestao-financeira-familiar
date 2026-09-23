<?php

namespace App\Providers;

use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use App\Support\Workspaces\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentWorkspace::class);

        $this->app->extend(Vite::class, function () {
            return new class extends Vite
            {
                public function devServerUrl()
                {
                    if (! $this->isRunningHot()) {
                        return null;
                    }

                    $configured = rtrim((string) file_get_contents($this->hotFile()));
                    $host = request()->getHost();

                    if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
                        return $configured;
                    }

                    $scheme = parse_url($configured, PHP_URL_SCHEME) ?: 'http';
                    $port = parse_url($configured, PHP_URL_PORT) ?: 5174;

                    return $scheme.'://'.$host.':'.$port;
                }
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Workspace::class, WorkspacePolicy::class);

        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
