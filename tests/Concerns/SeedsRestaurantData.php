<?php

namespace Tests\Concerns;

use App\Models\MenuItem;
use App\Models\RecipeIngredient;
use App\Models\Resource;

trait SeedsRestaurantData
{
    protected array $resources = [];
    protected array $menuItems = [];

    protected function seedRestaurantData(): void
    {
        $this->resources['potatoes'] = Resource::create([
            'name' => 'potatoes',
            'unit' => 'g',
            'current_stock' => 10000,
            'low_stock_threshold' => 2000,
        ]);

        $this->resources['oil'] = Resource::create([
            'name' => 'oil',
            'unit' => 'ml',
            'current_stock' => 5000,
            'low_stock_threshold' => 1000,
        ]);

        $this->menuItems['chips'] = MenuItem::create([
            'name' => 'Chips',
            'price' => 150.00,
            'category' => 'food',
            'is_available' => true,
        ]);

        $this->menuItems['soda'] = MenuItem::create([
            'name' => 'Soda',
            'price' => 80.00,
            'category' => 'drinks',
            'is_available' => true,
        ]);

        RecipeIngredient::create([
            'menu_item_id' => $this->menuItems['chips']->id,
            'resource_id' => $this->resources['potatoes']->id,
            'quantity_used' => 300,
        ]);

        RecipeIngredient::create([
            'menu_item_id' => $this->menuItems['chips']->id,
            'resource_id' => $this->resources['oil']->id,
            'quantity_used' => 60,
        ]);
    }
}
