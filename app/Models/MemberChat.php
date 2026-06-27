<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MemberChat extends Model
{
    protected $fillable = [
        'company_id', 'org_member_id', 'title', 'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function orgMember(): BelongsTo
    {
        return $this->belongsTo(OrgMember::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MemberMessage::class);
    }
}
