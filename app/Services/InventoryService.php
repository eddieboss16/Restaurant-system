<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ResourceTransaction;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryService
{
    public function deductForOrder(Order $order): void
    {
        $recipes = $order->menuItem->recipeIngredients()->with('resource')->get();

        DB::transaction(function () use ($recipes, $order) {
            foreach ($recipes as $recipe) {
                $resource = $recipe->resource;
                $totalDeduction = (float) $recipe->quantity_used * $order->quantity;

                $resource->refresh();

                if ((float) $resource->current_stock < $totalDeduction) {
                    throw new RuntimeException(sprintf(
                        'Not enough %s in stock for %d × %s (need %s%s, have %s%s).',
                        $resource->name,
                        $order->quantity,
                        $order->menuItem->name,
                        rtrim(rtrim(number_format($totalDeduction, 3, '.', ''), '0'), '.'),
                        $resource->unit,
                        rtrim(rtrim(number_format((float) $resource->current_stock, 3, '.', ''), '0'), '.'),
                        $resource->unit,
                    ));
                }

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
