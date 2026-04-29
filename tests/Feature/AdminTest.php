<?php

namespace Tests\Feature;

use App\Models\CustomerSession;
use App\Models\Order;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    private User $primaryAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
        $this->primaryAdmin = User::factory()->admin()->create(['name' => 'Owner']);
    }

    // ----- Staff CRUD -----

    public function test_admin_can_create_a_new_staff_member(): void
    {
        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'New Hire',
                'email' => 'hire@test.local',
                'password' => 'password123',
                'role' => 'waiter',
            ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'hire@test.local',
            'role' => 'waiter',
            'is_active' => true,
        ]);

        $created = User::where('email', 'hire@test.local')->first();
        $this->assertTrue(Hash::check('password123', $created->password));
    }

    public function test_creating_staff_requires_unique_email(): void
    {
        User::factory()->waiter()->create(['email' => 'taken@test.local']);

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'Dup',
                'email' => 'taken@test.local',
                'password' => 'password123',
                'role' => 'waiter',
            ])->assertStatus(422);
    }

    public function test_password_is_only_updated_when_provided(): void
    {
        $waiter = User::factory()->waiter()->create(['password' => bcrypt('original')]);
        $originalHash = $waiter->password;

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$waiter->id}", ['name' => 'Renamed'])
            ->assertOk();

        $this->assertSame($originalHash, $waiter->fresh()->password);

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$waiter->id}", ['password' => 'newpass1234'])
            ->assertOk();

        $this->assertTrue(Hash::check('newpass1234', $waiter->fresh()->password));
    }

    // ----- Self-protection -----

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$this->primaryAdmin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($this->primaryAdmin->fresh()->is_active);
    }

    public function test_admin_cannot_demote_themselves(): void
    {
        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$this->primaryAdmin->id}", ['role' => 'manager'])
            ->assertStatus(422);

        $this->assertSame('admin', $this->primaryAdmin->fresh()->role);
    }

    // ----- Primary admin protection -----

    public function test_other_admin_cannot_demote_primary_admin(): void
    {
        $secondAdmin = User::factory()->admin()->create();

        $this->actingAs($secondAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$this->primaryAdmin->id}", ['role' => 'manager'])
            ->assertStatus(422);

        $this->assertSame('admin', $this->primaryAdmin->fresh()->role);
    }

    public function test_other_admin_cannot_deactivate_primary_admin(): void
    {
        $secondAdmin = User::factory()->admin()->create();

        $this->actingAs($secondAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$this->primaryAdmin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($this->primaryAdmin->fresh()->is_active);
    }

    public function test_primary_admin_can_demote_a_secondary_admin(): void
    {
        $secondAdmin = User::factory()->admin()->create();

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->patchJson("/api/admin/staff/{$secondAdmin->id}", ['role' => 'manager'])
            ->assertOk();

        $this->assertSame('manager', $secondAdmin->fresh()->role);
    }

    public function test_staff_list_marks_only_the_lowest_id_admin_as_primary(): void
    {
        $secondAdmin = User::factory()->admin()->create();

        $payload = $this->actingAs($this->primaryAdmin, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk()
            ->json();

        $byId = collect($payload)->keyBy('id');
        $this->assertTrue($byId[$this->primaryAdmin->id]['is_primary_admin']);
        $this->assertFalse($byId[$secondAdmin->id]['is_primary_admin']);
    }

    public function test_staff_list_marks_users_with_recent_token_activity_as_online(): void
    {
        $recent = User::factory()->waiter()->create();
        $stale = User::factory()->waiter()->create();
        $never = User::factory()->waiter()->create();

        \DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $recent->id,
            'name' => 'session',
            'token' => hash('sha256', 'r'),
            'abilities' => '["*"]',
            'last_used_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \DB::table('personal_access_tokens')->insert([
            'tokenable_type' => User::class,
            'tokenable_id' => $stale->id,
            'name' => 'session',
            'token' => hash('sha256', 's'),
            'abilities' => '["*"]',
            'last_used_at' => now()->subHour(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $payload = $this->actingAs($this->primaryAdmin, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk()
            ->json();

        $byId = collect($payload)->keyBy('id');
        $this->assertTrue($byId[$recent->id]['online_now']);
        $this->assertFalse($byId[$stale->id]['online_now']);
        $this->assertFalse($byId[$never->id]['online_now']);
        $this->assertNull($byId[$never->id]['last_seen_at']);
    }

    // ----- Menu items -----

    public function test_menu_item_with_order_history_cannot_be_deleted(): void
    {
        $waiter = User::factory()->waiter()->create();
        $session = CustomerSession::create([
            'waiter_id' => $waiter->id,
            'status' => 'ordered',
            'opened_at' => now(),
        ]);
        Order::create([
            'session_id' => $session->id,
            'menu_item_id' => $this->menuItems['soda']->id,
            'quantity' => 1,
            'unit_price' => 80,
            'status' => 'pending',
        ]);

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->deleteJson("/api/admin/menu-items/{$this->menuItems['soda']->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('menu_items', ['id' => $this->menuItems['soda']->id]);
    }

    public function test_menu_item_without_order_history_can_be_deleted(): void
    {
        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->deleteJson("/api/admin/menu-items/{$this->menuItems['chips']->id}")
            ->assertOk();

        $this->assertDatabaseMissing('menu_items', ['id' => $this->menuItems['chips']->id]);
    }

    // ----- Resources / restock -----

    public function test_restocking_logs_a_manual_restock_transaction_and_bumps_timestamp(): void
    {
        $potatoes = $this->resources['potatoes'];
        $potatoes->update(['last_restocked_at' => now()->subDays(3)]);
        $beforeStock = (float) $potatoes->current_stock;
        $beforeTimestamp = $potatoes->fresh()->last_restocked_at;

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->postJson("/api/admin/resources/{$potatoes->id}/restock", [
                'amount' => 5000,
                'reason' => 'Tuesday delivery',
            ])->assertOk();

        $potatoes->refresh();
        $this->assertSame($beforeStock + 5000, (float) $potatoes->current_stock);
        $this->assertTrue($potatoes->last_restocked_at->greaterThan($beforeTimestamp));

        $this->assertDatabaseHas('resource_transactions', [
            'resource_id' => $potatoes->id,
            'change_amount' => 5000.000,
            'type' => 'manual_restock',
            'reason' => 'Tuesday delivery',
            'triggered_by' => $this->primaryAdmin->id,
        ]);
    }

    public function test_restocking_rejects_zero_or_negative_amount(): void
    {
        $potatoes = $this->resources['potatoes'];

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->postJson("/api/admin/resources/{$potatoes->id}/restock", ['amount' => 0])
            ->assertStatus(422);

        $this->actingAs($this->primaryAdmin, 'sanctum')
            ->postJson("/api/admin/resources/{$potatoes->id}/restock", ['amount' => -10])
            ->assertStatus(422);
    }

    public function test_resource_list_flags_low_stock(): void
    {
        $this->resources['potatoes']->update(['current_stock' => 1000]); // threshold is 2000

        $payload = $this->actingAs($this->primaryAdmin, 'sanctum')
            ->getJson('/api/admin/resources')
            ->assertOk()
            ->json();

        $byId = collect($payload)->keyBy('id');
        $this->assertTrue($byId[$this->resources['potatoes']->id]['low_stock']);
        $this->assertFalse($byId[$this->resources['oil']->id]['low_stock']);
    }

    // ----- Role gating -----

    public function test_only_admin_can_create_or_update_staff(): void
    {
        $waiter = User::factory()->waiter()->create();
        $manager = User::factory()->manager()->create();

        $this->actingAs($waiter, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'X', 'email' => 'x@test.local', 'password' => 'password123', 'role' => 'waiter',
            ])->assertForbidden();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/staff', [
                'name' => 'X', 'email' => 'x@test.local', 'password' => 'password123', 'role' => 'waiter',
            ])->assertForbidden();
    }

    public function test_manager_sees_staff_list_with_admins_ghosted(): void
    {
        $manager = User::factory()->manager()->create();
        $waiter = User::factory()->waiter()->create(['name' => 'Floor Waiter']);
        $kitchen = User::factory()->kitchen()->create(['name' => 'Kitchen Hand']);
        $secondAdmin = User::factory()->admin()->create(['name' => 'Other Admin']);

        $payload = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk()
            ->json();

        $names = array_column($payload, 'name');
        $this->assertContains('Floor Waiter', $names);
        $this->assertContains('Kitchen Hand', $names);
        $this->assertContains($manager->name, $names);
        $this->assertNotContains('Other Admin', $names);
        $this->assertNotContains('Owner', $names); // primaryAdmin from setUp
    }

    public function test_admin_sees_every_staff_member_including_other_admins(): void
    {
        $secondAdmin = User::factory()->admin()->create(['name' => 'Other Admin']);

        $payload = $this->actingAs($this->primaryAdmin, 'sanctum')
            ->getJson('/api/admin/staff')
            ->assertOk()
            ->json();

        $names = array_column($payload, 'name');
        $this->assertContains('Owner', $names);
        $this->assertContains('Other Admin', $names);
    }

    public function test_manager_can_reach_menu_inventory_and_cancellations(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'sanctum')->getJson('/api/admin/menu-items')->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson('/api/admin/resources')->assertOk();
        $this->actingAs($manager, 'sanctum')->getJson('/api/admin/cancellations')->assertOk();
    }

    public function test_manager_can_create_a_menu_item_and_restock_a_resource(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/menu-items', [
                'name' => 'Mandazi',
                'price' => 20.00,
                'category' => 'food',
            ])->assertCreated();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/admin/resources/' . $this->resources['potatoes']->id . '/restock', [
                'amount' => 1000,
                'reason' => 'manager-led restock',
            ])->assertOk();

        $this->assertDatabaseHas('menu_items', ['name' => 'Mandazi']);
        $this->assertDatabaseHas('resource_transactions', [
            'resource_id' => $this->resources['potatoes']->id,
            'change_amount' => 1000.000,
            'type' => 'manual_restock',
            'triggered_by' => $manager->id,
        ]);
    }

    public function test_waiter_and_kitchen_cannot_reach_menu_or_resource_management(): void
    {
        foreach ([User::factory()->waiter()->create(), User::factory()->kitchen()->create()] as $user) {
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/menu-items')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson('/api/admin/resources')->assertForbidden();
        }
    }
}
