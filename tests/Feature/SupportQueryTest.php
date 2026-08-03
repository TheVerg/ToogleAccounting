<?php

namespace Tests\Feature;

use App\Models\SupportQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function registerTenant(string $organization, string $email): User
    {
        $this->post('/register', [
            'organization' => $organization,
            'name' => 'Owner of '.$organization,
            'email' => $email,
            'password' => 'Str0ng-password!',
            'password_confirmation' => 'Str0ng-password!',
            'currency' => 'USD',
            'terms' => true,
        ]);
        auth()->logout();

        return User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    }

    public function test_business_user_can_submit_a_query_and_only_sees_their_own(): void
    {
        $acme = $this->registerTenant('Acme Ltd', 'owner@acme.test');
        $beta = $this->registerTenant('Beta Ltd', 'owner@beta.test');

        $this->actingAs($acme)
            ->post('/support/queries', [
                'type' => 'improvement',
                'subject' => 'Add dark mode',
                'message' => 'A dark theme would be easier on the eyes.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $query = SupportQuery::withoutGlobalScope('tenant')->firstOrFail();
        $this->assertSame($acme->tenant_id, $query->tenant_id);
        $this->assertSame($acme->id, $query->user_id);
        $this->assertSame('open', $query->status);

        auth()->logout();
        $this->actingAs($beta->fresh());
        $this->assertSame(0, SupportQuery::count());
    }

    public function test_platform_admin_sees_all_queries_and_can_respond(): void
    {
        $acme = $this->registerTenant('Acme Ltd', 'owner@acme.test');
        $beta = $this->registerTenant('Beta Ltd', 'owner@beta.test');

        $this->actingAs($acme)->post('/support/queries', [
            'type' => 'query', 'subject' => 'From Acme', 'message' => 'Hello',
        ]);
        auth()->logout();

        $admin = $beta;
        $admin->forceFill(['is_platform_admin' => true])->save();

        $response = $this->actingAs($admin->fresh())->get('/platform/queries');
        $response->assertOk();
        $queries = $response->viewData('page')['props']['queries']['data'];
        $this->assertCount(1, $queries);
        $this->assertSame('From Acme', $queries[0]['subject']);

        $query = SupportQuery::withoutGlobalScope('tenant')->firstOrFail();
        $this->patch("/platform/queries/{$query->id}", [
            'status' => 'resolved',
            'admin_response' => 'Done — shipped in the next release.',
        ])->assertRedirect();

        $query->refresh();
        $this->assertSame('resolved', $query->status);
        $this->assertNotNull($query->responded_at);
    }

    public function test_platform_routes_are_forbidden_for_regular_users(): void
    {
        $owner = $this->registerTenant('Acme Ltd', 'owner@acme.test');

        $this->actingAs($owner)->get('/platform/businesses')->assertForbidden();
        $this->get('/platform/queries')->assertForbidden();
    }

    public function test_platform_admin_sees_all_registered_businesses(): void
    {
        $this->registerTenant('Acme Ltd', 'owner@acme.test');
        $admin = $this->registerTenant('Beta Ltd', 'owner@beta.test');
        $admin->forceFill(['is_platform_admin' => true])->save();

        $response = $this->actingAs($admin->fresh())->get('/platform/businesses');
        $response->assertOk();

        $businesses = $response->viewData('page')['props']['businesses'];
        $this->assertCount(2, $businesses);
        $names = array_column($businesses, 'name');
        $this->assertContains('Acme Ltd', $names);
        $this->assertContains('Beta Ltd', $names);
        $acmeRow = collect($businesses)->firstWhere('name', 'Acme Ltd');
        $this->assertSame('owner@acme.test', $acmeRow['owner_email']);
        $this->assertSame(1, $acmeRow['users_count']);
    }
}
