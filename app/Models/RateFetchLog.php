<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RateFetchLog extends Model
{
    protected $fillable = [
        'date', 'base_currency', 'quote_currency',
        'primary_rate', 'rbz_rate', 'stored_rate', 'status', 'detail',
    ];

    protected $casts = [
        'date' => 'date',
        'primary_rate' => 'decimal:6',
        'rbz_rate' => 'decimal:6',
        'stored_rate' => 'decimal:6',
    ];
}
