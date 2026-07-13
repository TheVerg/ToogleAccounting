<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // A system administrator is structure-wide, not tied to the current
        // Spatie permissions team. This also covers companies created after
        // the administrator originally received the role.
        Gate::before(fn (User $user): ?bool => $user->isSystemAdmin() ? true : null);

        Password::defaults(fn () => Password::min(8)
            ->mixedCase()
            ->numbers()
            ->symbols());

        Vite::prefetch(concurrency: 3);
    }
}
