<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    public const CATEGORIES = ['supplies', 'salaries', 'utilities', 'rent', 'transport', 'other'];

    protected $fillable = [
        'amount',
        'category',
        'description',
        'incurred_on',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            // Pin the storage format so SQLite (TEXT) and MySQL (DATE)
            // both end up with 'YYYY-MM-DD' rather than full datetimes.
            // Otherwise SQLite stores 'YYYY-MM-DD HH:MM:SS' and breaks
            // string-compare date-range queries.
            'incurred_on' => 'date:Y-m-d',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
