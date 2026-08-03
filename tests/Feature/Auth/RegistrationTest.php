<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registration creates a whole organization (tenant) — see
 * TenantRegistrationTest for the full flow. This guards that a bare
 * user cannot be created without an organization around it.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_an_organization_is_rejected(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Str0ng-password!',
            'password_confirmation' => 'Str0ng-password!',
        ])->assertSessionHasErrors(['organization', 'currency', 'terms']);

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }
}
