<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AnalysisReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authorised_user_can_open_analysis_report_catalogue(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        $company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id);
        setPermissionsTeamId($company->id);
        $user->assignRole('system-admin');

        $this->actingAs($user)->get('/analysis')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Analysis')
                ->has('reports', 8)
                ->where('reports.0.name', 'Accounts Receivable Ageing')
                ->where('reports.0.href', '/analysis/receivables-ageing')
                ->where('reports.7.name', 'Monthly Management Accounts Pack'));
    }

    public function test_all_analysis_reports_can_be_generated_for_an_empty_period(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $company = Company::create(['name' => 'Hold', 'code' => 'H', 'type' => 'holding']);
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id);
        setPermissionsTeamId($company->id);
        $user->assignRole('system-admin');

        foreach (['receivables-ageing', 'revenue', 'expenses', 'payroll', 'employee-establishment', 'subsidiary-performance', 'intercompany-reconciliation', 'management-accounts'] as $type) {
            $this->actingAs($user)->get("/analysis/{$type}?from=2026-01-01&to=2026-01-31")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('Reports/AnalysisView')
                    ->where('type', $type)
                    ->has('report.metrics')
                    ->has('report.columns'));
        }
    }

    public function test_guest_cannot_open_analysis_report_catalogue(): void
    {
        $this->get('/analysis')->assertRedirect(route('login'));
    }
}
