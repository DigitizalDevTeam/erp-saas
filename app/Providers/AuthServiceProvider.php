<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Models\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Gate::before(function ($user, string $ability) {
            if (! $user) {
                return null;
            }

            $manageGrantsApprove = [
                'approve journal entry' => 'manage journal entry',
                'reject journal entry' => 'manage journal entry',
                'approve expense' => 'manage expense',
                'reject expense' => 'manage expense',
                'authorize invoice' => 'manage invoice',
                'reject invoice authorization' => 'manage invoice',
            ];

            if (isset($manageGrantsApprove[$ability]) && $user->can($manageGrantsApprove[$ability])) {
                return true;
            }

            return null;
        });

        Gate::define('access-company', function ($user, int $companyId) {
            return method_exists($user, 'canAccessCompany') ? $user->canAccessCompany($companyId) : false;
        });
    }
}
