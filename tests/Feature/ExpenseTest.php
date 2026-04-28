<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\SeedsRestaurantData;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;
    use SeedsRestaurantData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRestaurantData();
        Carbon::setTestNow(Carbon::create(2026, 4, 29, 12, 0, 0));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ----- Role gating -----

    public function test_waiter_and_kitchen_cannot_list_expenses(): void
    {
        $this->actingAs(User::factory()->waiter()->create(), 'sanctum')
            ->getJson('/api/expenses')
            ->assertForbidden();

        $this->actingAs(User::factory()->kitchen()->create(), 'sanctum')
            ->getJson('/api/expenses')
            ->assertForbidden();
    }

    public function test_manager_can_list_expenses(): void
    {
        $this->actingAs(User::factory()->manager()->create(), 'sanctum')
            ->getJson('/api/expenses')
            ->assertOk();
    }

    public function test_admin_can_list_expenses_via_wildcard_bypass(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'sanctum')
            ->getJson('/api/expenses')
            ->assertOk();
    }

    // ----- Create -----

    public function test_manager_can_create_an_expense(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/expenses', [
                'amount' => 1500.50,
                'category' => 'supplies',
                'description' => 'Cooking gas refill',
                'incurred_on' => '2026-04-29',
            ])->assertCreated()
              ->assertJsonFragment([
                  'category' => 'supplies',
                  'description' => 'Cooking gas refill',
              ]);

        $this->assertDatabaseHas('expenses', [
            'amount' => 1500.50,
            'category' => 'supplies',
            'recorded_by' => $manager->id,
        ]);
    }

    public function test_create_expense_validates_amount_and_category(): void
    {
        $manager = User::factory()->manager()->create();

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/expenses', [
                'amount' => 0,
                'category' => 'supplies',
                'description' => 'X',
                'incurred_on' => '2026-04-29',
            ])->assertStatus(422);

        $this->actingAs($manager, 'sanctum')
            ->postJson('/api/expenses', [
                'amount' => 100,
                'category' => 'not_a_real_category',
                'description' => 'X',
                'incurred_on' => '2026-04-29',
            ])->assertStatus(422);
    }

    // ----- Update -----

    public function test_manager_can_edit_their_own_expense(): void
    {
        $manager = User::factory()->manager()->create();
        $expense = Expense::create([
            'amount' => 100, 'category' => 'supplies',
            'description' => 'Original', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson("/api/expenses/{$expense->id}", ['description' => 'Updated'])
            ->assertOk();

        $this->assertSame('Updated', $expense->fresh()->description);
    }

    public function test_manager_cannot_edit_another_managers_expense(): void
    {
        $alice = User::factory()->manager()->create();
        $bob = User::factory()->manager()->create();
        $expense = Expense::create([
            'amount' => 100, 'category' => 'supplies',
            'description' => 'Alice paid', 'incurred_on' => '2026-04-29',
            'recorded_by' => $alice->id,
        ]);

        $this->actingAs($bob, 'sanctum')
            ->patchJson("/api/expenses/{$expense->id}", ['description' => 'Hijacked'])
            ->assertForbidden();
    }

    public function test_admin_can_edit_any_expense(): void
    {
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $expense = Expense::create([
            'amount' => 100, 'category' => 'supplies',
            'description' => 'Manager wrote this', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/expenses/{$expense->id}", ['amount' => 200])
            ->assertOk();

        $this->assertEquals(200, $expense->fresh()->amount);
    }

    // ----- Delete -----

    public function test_manager_cannot_delete_an_expense(): void
    {
        $manager = User::factory()->manager()->create();
        $expense = Expense::create([
            'amount' => 100, 'category' => 'supplies',
            'description' => 'X', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_admin_can_delete_an_expense(): void
    {
        $manager = User::factory()->manager()->create();
        $admin = User::factory()->admin()->create();
        $expense = Expense::create([
            'amount' => 100, 'category' => 'supplies',
            'description' => 'X', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/expenses/{$expense->id}")
            ->assertOk();

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    // ----- Reports integration -----

    public function test_daily_report_includes_expenses_and_net(): void
    {
        $admin = User::factory()->admin()->create();
        $manager = User::factory()->manager()->create();

        Expense::create([
            'amount' => 500, 'category' => 'supplies',
            'description' => 'Today supplies', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);
        Expense::create([
            'amount' => 200, 'category' => 'transport',
            'description' => 'Today transport', 'incurred_on' => '2026-04-29',
            'recorded_by' => $manager->id,
        ]);
        // Yesterday -- shouldn't count toward "current".
        Expense::create([
            'amount' => 9999, 'category' => 'rent',
            'description' => 'Yesterday rent', 'incurred_on' => '2026-04-28',
            'recorded_by' => $manager->id,
        ]);

        $payload = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/reports/today')
            ->assertOk()
            ->json();

        $this->assertEquals(700, $payload['expenses']['current']);
        $this->assertEquals(9999, $payload['expenses']['previous']);
        $this->assertEquals(500, $payload['expenses_by_category']['supplies']);
        $this->assertEquals(200, $payload['expenses_by_category']['transport']);
        $this->assertEquals(0, $payload['expenses_by_category']['rent']); // rent was yesterday
        $this->assertEquals(-700, $payload['net']['current']); // no revenue, just expenses today
    }
}
