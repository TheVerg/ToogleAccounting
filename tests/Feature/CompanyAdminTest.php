<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\ChartOfAccountsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    protected Company $home;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->home = Company::create(['name' => 'Home', 'code' => 'HOME', 'type' => 'holding']);
        app(ChartOfAccountsService::class)->seedDefaultChart($this->home);

        $this->admin = User::factory()->create(['current_company_id' => $this->home->id]);
        setPermissionsTeamId($this->home->id);
        $this->admin->assignRole('system-admin');
        $this->admin->companies()->attach($this->home->id);
    }

    public function test_a_parent_with_children_cannot_be_deleted(): void
    {
        $parent = Company::create(['name' => 'Parent', 'code' => 'PAR', 'type' => 'holding']);
        $child = Company::create(['name' => 'Child', 'code' => 'CHI', 'type' => 'subsidiary', 'parent_id' => $parent->id]);

        $this->actingAs($this->admin)
            ->delete('/companies/'.$parent->id)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['id' => $parent->id]);

        // After the child goes, the parent can be deleted.
        $this->actingAs($this->admin)->delete('/companies/'.$child->id)->assertSessionHas('success');
        $this->actingAs($this->admin)->delete('/companies/'.$parent->id)->assertSessionHas('success');

        $this->assertSoftDeleted('companies', ['id' => $parent->id]);
    }

    public function test_a_company_with_posted_books_cannot_be_deleted(): void
    {
        $company = Company::create(['name' => 'Books', 'code' => 'BKS', 'type' => 'subsidiary']);
        $chart = app(ChartOfAccountsService::class);
        $chart->seedDefaultChart($company);

        app(AccountingService::class)->recordExpense($company, [
            'date' => '2026-03-01',
            'account_id' => $chart->find($company, '5000')->id,
            'paid_from_account_id' => $chart->findBySubtype($company, 'bank')->id,
            'amount' => 100,
        ], $this->admin->id);

        $this->actingAs($this->admin)
            ->delete('/companies/'.$company->id)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_the_company_you_are_working_in_cannot_be_deleted(): void
    {
        $this->actingAs($this->admin)
            ->delete('/companies/'.$this->home->id)
            ->assertSessionHas('error');

        $this->assertDatabaseHas('companies', ['id' => $this->home->id]);
    }
}
