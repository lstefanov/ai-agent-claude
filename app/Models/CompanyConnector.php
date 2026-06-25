<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Свързана външна система на ниво фирма (Gmail, Notion, HTTP API…).
 * `credentials` са КРИПТИРАНИ през Laravel `encrypted:array` cast (APP_KEY) и
 * са `$hidden`, така че никога не изтичат през toArray()/toJson()/логове.
 * Единственото място, което ги чете и праща нататък, е McpClientService.
 */
class CompanyConnector extends Model
{
    protected $fillable = [
        'company_id', 'connector_type', 'display_name', 'auth_type',
        'credentials', 'scopes', 'status', 'last_tested_at', 'last_error', 'settings',
    ];

    protected $casts = [
        'credentials' => 'encrypted:array',
        'scopes' => 'array',
        'settings' => 'array',
        'last_tested_at' => 'datetime',
    ];

    protected $hidden = ['credentials'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function toolLogs(): HasMany
    {
        return $this->hasMany(ConnectorToolLog::class, 'connector_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Дали OAuth access token трябва refresh (60s буфер преди expiry). */
    public function needsRefresh(): bool
    {
        $creds = $this->credentials ?? [];

        return isset($creds['expires_at']) && now()->timestamp > ((int) $creds['expires_at']) - 60;
    }
}
