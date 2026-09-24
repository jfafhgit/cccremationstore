<?php

namespace App\Providers;

use App\Http\Middleware\IdentifyStore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureLivewireUpdateRoute();
        $this->configureAdminGates();
    }

    /**
     * Signing in through WorkOS only proves identity. Reaching the admin
     * area requires approval, and managing who else gets in is reserved for
     * super admins. Both are enforced with "can:" route middleware, which
     * Livewire re-applies to every component request made from those pages.
     */
    protected function configureAdminGates(): void
    {
        Gate::define('access-admin', fn (User $user): bool => $user->isApproved());
        Gate::define('manage-admins', fn (User $user): bool => $user->isApproved() && $user->is_super_admin);
    }

    /**
     * Livewire registers its component-update AJAX endpoint once, globally,
     * with no domain constraint of its own — so by default it never runs
     * our tenant-scoped "store" middleware and Store::current() is null on
     * every Livewire interaction on a tenant subdomain (only the initial,
     * fully-routed page load resolves it). The page that renders a
     * component embeds a *relative* update URL, so the browser's AJAX call
     * naturally lands back on whatever host served the page — we just need
     * that single endpoint to identify the tenant itself. IdentifyStore
     * does that by reading the subdomain off the Host header when there's
     * no "{store}" route parameter to fall back on (see its docblock), and
     * is a no-op for root-domain requests (admin/marketing components), so
     * one global route safely serves both.
     */
    protected function configureLivewireUpdateRoute(): void
    {
        Livewire::setUpdateRoute(function ($handle, $path) {
            return Route::post($path, $handle)
                ->middleware(['web', IdentifyStore::class])
                ->name('livewire.update');
        });
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
