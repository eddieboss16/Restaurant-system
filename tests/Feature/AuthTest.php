<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_unauthenticated_user_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_root_routes_each_role_to_its_own_dashboard(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/')->assertRedirect('/admin/dashboard');

        $this->actingAs(User::factory()->kitchen()->create())
            ->get('/')->assertRedirect('/kitchen/dashboard');

        $this->actingAs(User::factory()->waiter()->create())
            ->get('/')->assertRedirect('/waiter/dashboard');

        $this->actingAs(User::factory()->manager()->create())
            ->get('/')->assertRedirect('/manager/dashboard');
    }

    public function test_api_login_issues_a_token_and_returns_user_summary(): void
    {
        User::factory()->waiter()->create([
            'email' => 'w@test.local',
            'password' => bcrypt('secret123'),
        ]);

        $this->postJson('/api/login', [
            'email' => 'w@test.local',
            'password' => 'secret123',
        ])->assertOk()
          ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role']]);
    }

    public function test_api_logout_revokes_the_caller_token(): void
    {
        $user = User::factory()->waiter()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_inactive_user_cannot_log_in_via_api(): void
    {
        User::factory()->waiter()->create([
            'email' => 'inactive@test.local',
            'password' => bcrypt('pw12345678'),
            'is_active' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'inactive@test.local',
            'password' => 'pw12345678',
        ])->assertForbidden();
    }

    public function test_role_middleware_blocks_wrong_roles_on_dashboards(): void
    {
        $waiter = User::factory()->waiter()->create();
        $kitchen = User::factory()->kitchen()->create();

        $this->actingAs($waiter)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($waiter)->get('/kitchen/dashboard')->assertForbidden();

        $this->actingAs($kitchen)->get('/admin/dashboard')->assertForbidden();
        $this->actingAs($kitchen)->get('/waiter/dashboard')->assertForbidden();
    }

    public function test_admin_bypasses_role_middleware_everywhere(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get('/admin/dashboard')->assertOk();
        $this->actingAs($admin)->get('/waiter/dashboard')->assertOk();
        $this->actingAs($admin)->get('/kitchen/dashboard')->assertOk();
    }

    public function test_manager_can_reach_waiter_kitchen_and_manager_views_but_not_admin(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager)->get('/waiter/dashboard')->assertOk();
        $this->actingAs($manager)->get('/kitchen/dashboard')->assertOk();
        $this->actingAs($manager)->get('/manager/dashboard')->assertOk();
        $this->actingAs($manager)->get('/admin/dashboard')->assertForbidden();
    }

    public function test_only_manager_and_admin_reach_manager_dashboard(): void
    {
        $waiter = User::factory()->waiter()->create();
        $kitchen = User::factory()->kitchen()->create();
        $admin = User::factory()->admin()->create();

        $this->actingAs($waiter)->get('/manager/dashboard')->assertForbidden();
        $this->actingAs($kitchen)->get('/manager/dashboard')->assertForbidden();
        $this->actingAs($admin)->get('/manager/dashboard')->assertOk(); // wildcard bypass
    }
}
