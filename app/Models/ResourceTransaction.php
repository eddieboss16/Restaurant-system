<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_id',
        'change_amount',
        'type',
        'reason',
        'triggered_by',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'change_amount' => 'decimal:3',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(Resource::class);
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
