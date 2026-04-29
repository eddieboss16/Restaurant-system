<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\RecipeIngredient;
use App\Models\Resource;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Staff ---
        User::updateOrCreate(
            ['email' => 'admin@restaurant.co.ke'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'role' => 'admin',
                'is_active' => true,
            ],
        );

        User::updateOrCreate(
            ['email' => 'manager@restaurant.co.ke'],
            [
                'name' => 'Manager',
                'password' => Hash::make('password'),
                'role' => 'manager',
                'is_active' => true,
            ],
        );

        foreach ([
            ['name' => 'Amina Waiter',   'email' => 'amina@restaurant.co.ke'],
            ['name' => 'Brian Waiter',   'email' => 'brian@restaurant.co.ke'],
            ['name' => 'Cynthia Waiter', 'email' => 'cynthia@restaurant.co.ke'],
        ] as $waiter) {
            User::updateOrCreate(
                ['email' => $waiter['email']],
                [
                    'name' => $waiter['name'],
                    'password' => Hash::make('password'),
                    'role' => 'waiter',
                    'is_active' => true,
                ],
            );
        }

        User::updateOrCreate(
            ['email' => 'kitchen@restaurant.co.ke'],
            [
                'name' => 'Kitchen Station',
                'password' => Hash::make('password'),
                'role' => 'kitchen',
                'is_active' => true,
            ],
        );

        // --- Resources (raw ingredients) ---
        $resources = [
            'potatoes' => ['unit' => 'g',  'stock' => 10000, 'threshold' => 2000],
            'oil'      => ['unit' => 'ml', 'stock' => 5000,  'threshold' => 1000],
            'flour'    => ['unit' => 'g',  'stock' => 8000,  'threshold' => 1500],
            'chicken'  => ['unit' => 'g',  'stock' => 6000,  'threshold' => 1500],
        ];

        $resourceModels = [];
        foreach ($resources as $name => $attrs) {
            $resourceModels[$name] = Resource::updateOrCreate(
                ['name' => $name],
                [
                    'unit' => $attrs['unit'],
                    'current_stock' => $attrs['stock'],
                    'low_stock_threshold' => $attrs['threshold'],
                    'last_restocked_at' => now(),
                ],
            );
        }

        // --- Menu items ---
        $menu = [
            ['name' => 'Chips',       'price' => 150.00, 'category' => 'food'],
            ['name' => 'Chicken',     'price' => 400.00, 'category' => 'food'],
            ['name' => 'Chapati',     'price' => 30.00,  'category' => 'food'],
            ['name' => 'Soda',        'price' => 80.00,  'category' => 'drinks'],
            ['name' => 'Water 500ml', 'price' => 50.00,  'category' => 'drinks'],
        ];

        $menuModels = [];
        foreach ($menu as $item) {
            $menuModels[$item['name']] = MenuItem::updateOrCreate(
                ['name' => $item['name']],
                [
                    'price' => $item['price'],
                    'category' => $item['category'],
                    'is_available' => true,
                ],
            );
        }

        // --- Recipe mapping (per 1 unit ordered) ---
        $recipes = [
            'Chips'   => [['potatoes', 300], ['oil', 60]],
            'Chicken' => [['chicken', 250], ['oil', 30]],
            'Chapati' => [['flour', 150], ['oil', 20]],
        ];

        foreach ($recipes as $menuName => $ingredients) {
            foreach ($ingredients as [$resourceName, $qty]) {
                RecipeIngredient::updateOrCreate(
                    [
                        'menu_item_id' => $menuModels[$menuName]->id,
                        'resource_id' => $resourceModels[$resourceName]->id,
                    ],
                    ['quantity_used' => $qty],
                );
            }
        }
    }
}
