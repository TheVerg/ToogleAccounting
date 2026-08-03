<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One customer organization: a business (or group) with its own users,
 * companies, and books, fully isolated from every other tenant.
 */
class Tenant extends Model
{
    protected $fillable = ['name', 'is_active', 'plan', 'status', 'trial_ends_at'];

    protected $casts = [
        'is_active' => 'boolean',
        'trial_ends_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function supportQueries(): HasMany
    {
        return $this->hasMany(SupportQuery::class);
    }
}
