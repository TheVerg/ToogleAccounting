<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ForcedPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_flagged_user_is_sent_to_password_change_after_login(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('password.force.edit'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_flagged_user_cannot_access_the_application_or_profile(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('password.force.edit'));
        $this->actingAs($user)->get('/profile')->assertRedirect(route('password.force.edit'));
        $this->actingAs($user)->get(route('password.force.edit'))->assertOk();
    }

    public function test_current_password_is_required_and_cannot_be_reused(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('password.force.update'), [
            'current_password' => 'wrong-password',
            'password' => 'Different1!',
            'password_confirmation' => 'Different1!',
        ])->assertSessionHasErrors('current_password');

        $this->actingAs($user)->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_successful_change_unlocks_account_and_is_audited(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->actingAs($user)->put(route('password.force.update'), [
            'current_password' => 'password',
            'password' => 'NewSecure1!',
            'password_confirmation' => 'NewSecure1!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NewSecure1!', $user->password));
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'initial_password_changed',
            'auditable_type' => $user->getMorphClass(),
            'auditable_id' => $user->id,
        ]);
        $this->assertSame(1, AuditLog::where('action', 'initial_password_changed')->count());
    }
}
