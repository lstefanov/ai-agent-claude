<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FlowEvalCase extends Model
{
    protected $fillable = ['flow_id', 'name', 'description', 'input_data', 'criteria', 'weight', 'is_active'];

    protected $casts = [
        'input_data' => 'array',
        'criteria' => 'array',
        'weight' => 'float',
        'is_active' => 'boolean',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(Flow::class);
    }

    public function evalRuns(): HasMany
    {
        return $this->hasMany(FlowEvalRun::class, 'eval_case_id');
    }
}
