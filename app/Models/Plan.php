<?php

namespace App\Models;

use App\Support\ModelLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'key', 'name', 'price_cents', 'monthly_credits',
        'max_star_tier', 'features', 'stripe_price_id', 'is_active',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** Максималното позволено ниво по плана (за cap на задачите). */
    public function maxStarTier(): ModelLevel
    {
        return ModelLevel::from($this->max_star_tier);
    }
}
