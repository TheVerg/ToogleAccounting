<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'organization' => 'Acme Trading (Pvt) Ltd',
            'name' => 'Tari Moyo',
            'email' => 'tari@acme.test',
            'password' => 'Str0ng-password!',
            'password_confirmation' => 'Str0ng-password!',
            'currency' => 'USD',
            'terms' => true,
        ], $overrides);
    }

    public function test_registration_provisions_a_trial_tenant_with_company_and_owner(): void
    {
        $response = $this->post('/register', $this->validPayload());

        $response->assertRedirect('/dashboard');

        $tenant = Tenant::where('name', 'Acme Trading (Pvt) Ltd')->firstOrFail();
        $this->assertSame('trial', $tenant->status);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->isAfter(now()->addDays(29)));

        $owner = User::withoutGlobalScopes()->where('email', 'tari@acme.test')->firstOrFail();
        $this->assertSame($tenant->id, $owner->tenant_id);
        $this->assertTrue($owner->fresh()->isSystemAdmin());
        $this->assertFalse($owner->must_change_password);
        $this->assertAuthenticatedAs($owner);

        $company = Company::withoutGlobalScopes()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('holding', $company->type);
        $this->assertSame('HQ', $company->code);
        $this->assertSame('USD', $company->currency);
        $this->assertTrue($company->accounts()->where('code', '1000')->exists());
        $this->assertSame($company->id, $owner->current_company_id);
    }

    public function test_new_organizations_are_isolated_from_each_other(): void
    {
        $this->post('/register', $this->validPayload());
        auth()->logout();
        $this->post('/register', $this->validPayload([
            'organization' => 'Beta Ltd', 'email' => 'owner@beta.test',
        ]));

        $betaOwner = User::withoutGlobalScopes()->where('email', 'owner@beta.test')->first();
        $this->actingAs($betaOwner);

        $this->assertSame(['Beta Ltd'], Company::pluck('name')->all());
        $this->assertSame(['owner@beta.test'], User::pluck('email')->all());
    }

    public function test_duplicate_email_and_missing_terms_are_rejected(): void
    {
        $this->post('/register', $this->validPayload());
        auth()->logout();

        $this->post('/register', $this->validPayload(['organization' => 'Other Co']))
            ->assertSessionHasErrors('email');

        $this->post('/register', $this->validPayload([
            'email' => 'fresh@acme.test', 'terms' => false,
        ]))->assertSessionHasErrors('terms');
    }

    public function test_invite_code_is_enforced_when_configured(): void
    {
        config(['services.signup.invite_code' => 'ZIM2026']);

        $this->post('/register', $this->validPayload())
            ->assertSessionHasErrors('invite_code');

        $this->post('/register', $this->validPayload(['invite_code' => 'wrong']))
            ->assertSessionHasErrors('invite_code');

        $this->post('/register', $this->validPayload(['invite_code' => 'ZIM2026']))
            ->assertRedirect('/dashboard');
    }

    public function test_registration_page_renders_for_guests(): void
    {
        $this->get('/register')->assertOk();
    }
}
