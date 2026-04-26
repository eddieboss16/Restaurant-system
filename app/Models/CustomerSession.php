<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

class CustomerSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'waiter_id',
        'customer_label',
        'status',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function waiter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waiter_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'session_id');
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class, 'session_id');
    }

    public function totalAmount(): float
    {
        return (float) $this->orders()
            ->where('status', '!=', 'cancelled')
            ->sum(DB::raw('quantity * unit_price'));
    }
}
