<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    protected $fillable = [
        'user_id', 'spent_on', 'merchant', 'description', 'amount',
        'category', 'source', 'status', 'image_path', 'raw_extraction',
    ];

    protected $casts = [
        'spent_on' => 'date',
        'amount' => 'integer',
        'raw_extraction' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
