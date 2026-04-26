<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ResourceTransaction;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    public function deductForOrder(Order $order): void
    {
        $recipes = $order->menuItem->recipeIngredients()->with('resource')->get();

        DB::transaction(function () use ($recipes, $order) {
            foreach ($recipes as $recipe) {
                $resource = $recipe->resource;
                $totalDeduction = (float) $recipe->quantity_used * $order->quantity;

                $resource->decrement('current_stock', $totalDeduction);

                ResourceTransaction::create([
                    'resource_id' => $resource->id,
                    'change_amount' => -$totalDeduction,
                    'type' => 'auto_deduction',
                    'order_id' => $order->id,
                ]);
            }
        });
    }
}
