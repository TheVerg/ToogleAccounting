<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_assigned_user_sees_dashboard(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($company);

        $user = User::factory()->create(['current_company_id' => $company->id]);
        setPermissionsTeamId($company->id);
        $user->assignRole('system-admin');
        $user->companies()->attach($company->id);

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            // The sidebar is permission-driven: the shared auth prop must
            // carry the user's permissions for the current company.
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->contains('reports.view'))
                ->where('auth.user.is_system_admin', true));
    }
}
