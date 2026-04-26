<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Resource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'unit',
        'current_stock',
        'low_stock_threshold',
        'last_restocked_at',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:3',
            'low_stock_threshold' => 'decimal:3',
            'last_restocked_at' => 'datetime',
        ];
    }

    public function recipeIngredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(ResourceTransaction::class);
    }

    public function isLowStock(): bool
    {
        return $this->current_stock <= $this->low_stock_threshold;
    }
}
