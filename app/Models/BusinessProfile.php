<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessProfile extends Model
{
    protected $fillable = [
        'company_id', 'research', 'interview_answers',
        'situational_analysis', 'pain_points', 'status',
    ];

    protected $casts = [
        'research' => 'array',
        'interview_answers' => 'array',
        'pain_points' => 'array',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
