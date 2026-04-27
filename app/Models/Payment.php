<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'method',
        'amount',
        'phone_number',
        'status',
        'mpesa_code',
        'mpesa_checkout_request_id',
        'mpesa_merchant_request_id',
        'mpesa_result_code',
        'mpesa_result_desc',
        'collected_by',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'mpesa_result_code' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CustomerSession::class, 'session_id');
    }

    public function collectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
