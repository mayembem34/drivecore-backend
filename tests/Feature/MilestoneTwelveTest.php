<?php

namespace Tests\Feature;

use App\Jobs\SendPasswordReset;
use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use App\Notifications\AccountInvitation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MilestoneTwelveTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function workspace(): array
    {
        $this->freezeTime();
        $this->seed(RolePermissionSeeder::class);
        $c = Company::factory()->create();
        $b = Branch::factory()->create(['company_id' => $c->id]);
        $u = User::factory()->create();
        $u->assignRole('company-admin');
        $u->companies()->attach($c->id);
        Sanctum::actingAs($u);

        return [$u, $c, $b];
    }

    public function test_invitation_rotation_revocation_and_single_use_acceptance(): void
    {
        [$u,$c,$b] = $this->workspace();
        Notification::fake();
        $body = ['company_id' => $c->id, 'branch_id' => $b->id, 'email' => 'new@example.com', 'role' => 'operations'];
        $id = $this->postJson('/api/v1/invitations', $body)->assertCreated()->assertJsonMissingPath('data.token_hash')->json('data.id');
        $this->postJson('/api/v1/invitations', $body)->assertUnprocessable();
        $raw = Notification::sent(new AnonymousNotifiable, AccountInvitation::class)->first()->token;
        $this->getJson('/api/v1/invitations/preview?token='.$raw)->assertOk()->assertJsonPath('data.account_exists', false);
        $this->postJson('/api/v1/invitations/'.$id.'/resend')->assertOk();
        $new = Notification::sent(new AnonymousNotifiable, AccountInvitation::class)->last()->token;
        $this->getJson('/api/v1/invitations/preview?token='.$raw)->assertGone();
        $this->postJson('/api/v1/invitations/accept', ['token' => $new, 'name' => 'New Staff', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123'])->assertOk()->assertJsonPath('company_id', $c->id);
        $this->postJson('/api/v1/invitations/accept', ['token' => $new, 'password' => 'StrongPass123'])->assertGone();
        $account = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertTrue($account->companies()->whereKey($c->id)->exists());
        $this->assertTrue($account->hasRole('operations'));
        $this->assertDatabaseMissing('invitations', ['token_hash' => $new]);
        Sanctum::actingAs($u);
        $second = $this->postJson('/api/v1/invitations', array_replace($body, ['email' => 'revoke@example.com']))->assertCreated()->json('data.id');
        $token = Notification::sent(new AnonymousNotifiable, AccountInvitation::class)->last()->token;
        $this->postJson('/api/v1/invitations/'.$second.'/revoke')->assertOk();
        $this->getJson('/api/v1/invitations/preview?token='.$token)->assertGone();
        Notification::assertSentOnDemand(AccountInvitation::class);
    }

    public function test_existing_account_must_prove_password_before_membership(): void
    {
        [$u,$c,$b] = $this->workspace();
        Notification::fake();
        $existing = User::factory()->create(['email' => 'existing@example.com', 'password' => 'Existing12345']);
        $this->postJson('/api/v1/invitations', ['company_id' => $c->id, 'email' => $existing->email, 'role' => 'operations'])->assertCreated();
        $token = Notification::sent(new AnonymousNotifiable, AccountInvitation::class)->first()->token;
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => 'Incorrect123'])->assertUnprocessable();
        $this->assertFalse($existing->companies()->whereKey($c->id)->exists());
        $this->postJson('/api/v1/invitations/accept', ['token' => $token, 'password' => 'Existing12345'])->assertOk();
        $this->assertTrue($existing->companies()->whereKey($c->id)->exists());
        Notification::assertSentOnDemand(AccountInvitation::class);
    }

    public function test_direct_creation_requires_rotation_and_profile_cannot_change_email(): void
    {
        [$admin,$c,$b] = $this->workspace();
        $id = $this->postJson('/api/v1/users', ['company_id' => $c->id, 'name' => 'Direct', 'email' => 'direct@example.com', 'password' => 'InitialPass123', 'password_confirmation' => 'InitialPass123', 'role' => 'operations'])->assertCreated()->assertJsonPath('data.must_change_password', true)->json('data.id');
        $u = User::findOrFail($id);
        Sanctum::actingAs($u);
        $this->getJson('/api/v1/customers?company_id='.$c->id)->assertForbidden();
        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'InitialPass123', 'password' => 'InitialPass123', 'password_confirmation' => 'InitialPass123'])->assertUnprocessable();
        $this->postJson('/api/v1/auth/change-password', ['current_password' => 'InitialPass123', 'password' => 'ChangedPass123', 'password_confirmation' => 'ChangedPass123'])->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->getJson('/api/v1/customers?company_id='.$c->id)->assertOk();
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Changed', 'email' => 'steal@example.com'])->assertUnprocessable();
        $this->patchJson('/api/v1/auth/profile', ['name' => 'Changed'])->assertOk()->assertJsonPath('data.name', 'Changed')->assertJsonPath('data.email', 'direct@example.com');
    }

    public function test_forgot_has_constant_response_and_reset_revokes_tokens(): void
    {
        [$u,$c,$b] = $this->workspace();
        Queue::fake([SendPasswordReset::class]);
        $response = $this->postJson('/api/v1/auth/forgot-password', ['email' => $u->email])->assertOk()->json();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk()->assertExactJson($response);
        Queue::assertPushed(SendPasswordReset::class, 2);
        $u->createToken('Old');
        $token = Password::createToken($u);
        $this->postJson('/api/v1/auth/reset-password', ['token' => $token, 'email' => $u->email, 'password' => 'ResetStrong123', 'password_confirmation' => 'ResetStrong123'])->assertOk();
        $this->assertTrue(Hash::check('ResetStrong123', $u->fresh()->password));
        $this->assertSame(0, $u->tokens()->count());
        $this->assertSame(1, $u->fresh()->auth_version);
    }
}
